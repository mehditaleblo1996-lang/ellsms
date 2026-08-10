<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 4 schedule claiming/lease/retry/cancellation against real MySQL — run_due_schedules() in
 * app/backend.php. Like BulkJobQueueTest, dispatch_message_raw()'s gateway call always resolves to
 * the deterministic "unreachable" (retryable=true) branch here — no api_base_url configured in the
 * test schema — which is exactly the transient-failure path this suite needs.
 */
final class ScheduleQueueTest extends IntegrationTestCase
{
    private function fundWallet(int $userId): void {
        wallet_credit($userId, 1000, 'purchase', 'test', 'seed:' . $userId, 'seed:' . $userId);
    }

    private function makeActiveSchedule(int $userId, string $runAt = 'NOW()'): int {
        db()->prepare(
            "INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, repeat_type, status)
             VALUES (?, ?, ?, ?, {$runAt}, 'none', 'active')"
        )->execute([$userId, self::DEFAULT_ORIGINATOR, json_encode(['09120000000']), 'hi']);
        return (int)db()->lastInsertId();
    }

    private function scheduleRow(int $id): array {
        $st = db()->prepare('SELECT * FROM ellsms_schedule WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    public function testDueScheduleIsClaimedAndRetriedOnTransientFailure(): void
    {
        putenv('JOB_MAX_ATTEMPTS=5');
        putenv('JOB_RETRY_BASE_SECONDS=30');
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $id = $this->makeActiveSchedule($userId);

        $n = run_due_schedules();
        $this->assertSame(1, $n);

        $row = $this->scheduleRow($id);
        $this->assertSame('active', $row['status'], 'a retryable failure with attempts remaining must not become terminal');
        $this->assertSame(1, (int)$row['attempt_count']);
        $this->assertNotNull($row['next_attempt_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);
        $this->assertSame(0, (int)$row['run_count'], 'run_count only advances on a finalized (terminal) outcome, not a retry-scheduled one');

        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
    }

    public function testNotClaimableAgainBeforeItsBackoffWindowPasses(): void
    {
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $id = $this->makeActiveSchedule($userId);
        run_due_schedules(); // schedules a retry with next_attempt_at in the future

        $this->assertSame(0, run_due_schedules(), 'must not be reclaimed before next_attempt_at');

        db()->prepare('UPDATE ellsms_schedule SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')
            ->execute([$id]);
        $this->assertSame(1, run_due_schedules(), 'must be claimable once next_attempt_at has passed');
    }

    public function testBecomesTerminalAfterMaxAttempts(): void
    {
        putenv('JOB_MAX_ATTEMPTS=2');
        putenv('JOB_RETRY_BASE_SECONDS=1');
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $id = $this->makeActiveSchedule($userId);

        run_due_schedules(); // attempt 1: retryable, back to active
        $this->assertSame('active', $this->scheduleRow($id)['status']);

        db()->prepare('UPDATE ellsms_schedule SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$id]);
        run_due_schedules(); // attempt 2 == JOB_MAX_ATTEMPTS: terminal

        $row = $this->scheduleRow($id);
        $this->assertSame('done', $row['status'], 'max attempts reached — must stop retrying (Invariant H), a one-time schedule finalizes as done');
        $this->assertSame(1, (int)$row['run_count']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);

        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
    }

    public function testExpiredLeaseOnAStuckProcessingRowBecomesReclaimable(): void
    {
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $id = $this->makeActiveSchedule($userId);

        // Simulate a crashed worker: claimed, then the process died before finalizing.
        db()->prepare("UPDATE ellsms_schedule SET status='processing', claimed_by='dead-worker', lease_expires_at=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id=?")
            ->execute([$id]);

        $n = run_due_schedules();
        $this->assertSame(1, $n, 'a schedule stuck processing past its lease must be reclaimable (Invariant D)');
        $row = $this->scheduleRow($id);
        $this->assertNotSame('processing', $row['status'], 'must have moved past the stuck state');
    }

    public function testRevokedUserSchedulePermanentlyFailsWithoutRetrying(): void
    {
        $userId = $this->makeUser();
        $id = $this->makeActiveSchedule($userId);
        db()->prepare('UPDATE ellsms_meta SET panel_access = 0 WHERE user_id = ?')->execute([$userId]);

        run_due_schedules();

        $row = $this->scheduleRow($id);
        $this->assertSame('done', $row['status'], 'an authorization failure is permanent — must not enter the retry cycle');
        $this->assertSame(1, (int)$row['attempt_count']);
    }

    /**
     * TD-008 / STEP 13: the cancellation-race guard on run_due_schedules()'s finalize UPDATE.
     * Exercises the exact SQL shape run_due_schedules() itself executes (CASE WHEN status='cancelled'
     * THEN 'cancelled' ELSE ? END) rather than the full function end-to-end — driving a genuine
     * "cancel lands in the few-microsecond window between claim and finalize" race deterministically
     * from a single test process isn't possible without refactoring run_due_schedules() into smaller
     * units, which was judged out of proportion for this phase. This proves the guard mechanism the
     * race depends on is correct in isolation, which is the part that was actually missing/buggy
     * before Phase 4 (the pre-Phase-4 finalize UPDATE had no such guard at all — see
     * docs/technical-debt.md TD-008).
     */
    public function testFinalizeGuardPreservesCancelledStatusInsteadOfOverwritingIt(): void
    {
        $userId = $this->makeUser();
        $id = $this->makeActiveSchedule($userId);

        // Simulate: worker claimed it (status='processing'), then the user cancelled it, all before
        // the worker's own finalize statement runs.
        db()->prepare("UPDATE ellsms_schedule SET status='processing' WHERE id=?")->execute([$id]);
        db()->prepare("UPDATE ellsms_schedule SET status='cancelled' WHERE id=? AND status IN ('active','processing')")->execute([$id]);
        $this->assertSame('cancelled', $this->scheduleRow($id)['status']);

        // The exact finalize UPDATE shape from run_due_schedules() — must record that a send was
        // attempted (STEP 13: "record truthfully") without reverting the user's cancellation.
        db()->prepare("UPDATE ellsms_schedule SET
                          status = CASE WHEN status='cancelled' THEN 'cancelled' ELSE ? END,
                          run_at=COALESCE(?, run_at), last_run_at=NOW(), last_result=?, run_count=run_count+1,
                          claimed_by=NULL, lease_expires_at=NULL, next_attempt_at=NULL
                        WHERE id=?")
            ->execute(['done', null, 'موفق: تست', $id]);

        $row = $this->scheduleRow($id);
        $this->assertSame('cancelled', $row['status'], 'a cancellation that landed before finalize must never be silently reverted to active/done');
        $this->assertSame(1, (int)$row['run_count'], 'the outcome is still recorded truthfully — run_count advances even though status stays cancelled');
    }

    public function testRecurringScheduleAdvancesRunAtExactlyOnceOnSuccessPath(): void
    {
        $userId = $this->makeUser();
        db()->prepare(
            "INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, repeat_type, status)
             VALUES (?, ?, ?, ?, NOW(), 'daily', 'active')"
        )->execute([$userId, self::DEFAULT_ORIGINATOR, json_encode(['09120000000']), 'hi']);
        $id = (int)db()->lastInsertId();

        // Force max attempts to 1 so the (always-failing, no gateway) dispatch finalizes immediately
        // as terminal, taking the same "compute the next occurrence" code path a successful send
        // would — this test's concern is "does run_at advance exactly once," not the dispatch
        // outcome itself.
        putenv('JOB_MAX_ATTEMPTS=1');
        $before = $this->scheduleRow($id)['run_at'];
        run_due_schedules();
        $row = $this->scheduleRow($id);
        putenv('JOB_MAX_ATTEMPTS');

        $this->assertSame('active', $row['status'], 'a recurring schedule goes back to active for its next occurrence, never done');
        $this->assertNotSame($before, $row['run_at'], 'run_at must have advanced to the next occurrence');
        $this->assertSame(1, (int)$row['run_count']);
    }
}
