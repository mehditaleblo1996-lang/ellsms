<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 4 bulk-item claiming/lease/retry/cancellation against real MySQL — bulk_claim_items() and
 * bulk_send_one_item() in app/backend.php. dispatch_message_raw()'s gateway call always resolves to
 * the "unreachable" branch here (no api_base_url configured in the test schema — see
 * IntegrationTestCase::ensureSchemaLoaded()), which is retryable=true, ok=false, sentCount=0 —
 * exactly the deterministic transient-failure path this suite needs to exercise claim/retry/backoff
 * behavior without a real gateway. That also means no item here ever reaches status='sent' — a real
 * successful send's terminal path is covered by static review + the fact that it uses the exact
 * same finalize UPDATE shape as the retryable branch, just with a different target status.
 */
final class BulkJobQueueTest extends IntegrationTestCase
{
    /** @return array{userId:int, jobId:int, itemIds:int[]} */
    private function makeFundedBulkJob(int $itemCount = 3, ?int $throttleCount = null, ?int $throttleMinutes = null): array {
        $userId = $this->makeUser();
        wallet_credit($userId, 1000, 'purchase', 'test', 'seed:' . $userId, 'seed:' . $userId);
        $user = ['id' => $userId, 'role' => 'user'];

        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = ['mobile' => '0912000000' . $i, 'content' => 'hi'];
        }
        [$ok, , $jobId] = bulk_queue_job($user, 'p2p', 't', self::DEFAULT_ORIGINATOR, null, $items, $throttleCount, $throttleMinutes);
        $this->assertTrue($ok);

        // bulk_queue_job() creates a job as status='pending' — in production, run_bulk_send_pass()
        // always promotes exactly one pending job to 'processing' before any item claim ever
        // happens. Tests call bulk_claim_items() directly, so they must do that same promotion
        // themselves first, or the fresh cancellation re-check in bulk_send_one_item() (which
        // requires status='processing') would correctly, but misleadingly for a test, treat every
        // item as belonging to a cancelled/not-yet-started job.
        db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);

        $st = db()->prepare('SELECT id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        return ['userId' => $userId, 'jobId' => (int)$jobId, 'itemIds' => $st->fetchAll(\PDO::FETCH_COLUMN)];
    }

    private function itemRow(int $itemId): array {
        $st = db()->prepare('SELECT * FROM ellsms_bulk_items WHERE id = ?');
        $st->execute([$itemId]);
        return $st->fetch();
    }

    public function testClaimMarksItemsProcessingAndExcludesThemFromASecondClaim(): void
    {
        $job = $this->makeFundedBulkJob(3);

        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(3, $claimed);
        foreach ($claimed as $row) {
            $this->assertSame('processing', $row['status']);
            $this->assertSame(1, (int)$row['attempt_count']);
            $this->assertNotNull($row['lease_expires_at']);
        }

        // Nothing left to claim — all three are already 'processing' with an unexpired lease.
        $second = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertSame([], $second);
    }

    public function testClaimSkipsItemsBelongingToANonProcessingJob(): void
    {
        $job = $this->makeFundedBulkJob(2);
        db()->prepare("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id = ?")->execute([$job['jobId']]);

        $claimed = bulk_claim_items(db(), "j.status = 'processing' AND j.throttle_count IS NULL", [], 20);
        $ids = array_map(static fn($r) => (int)$r['id'], $claimed);
        foreach ($job['itemIds'] as $id) {
            $this->assertNotContains($id, $ids, 'a cancelled job\'s items must never be claimed');
        }
    }

    public function testExpiredLeaseItemBecomesReclaimable(): void
    {
        $job = $this->makeFundedBulkJob(1);
        $itemId = $job['itemIds'][0];

        $first = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(1, $first);
        $this->assertSame(1, (int)$first[0]['attempt_count']);

        // Simulate a crashed worker: its claim's lease has expired.
        db()->prepare("UPDATE ellsms_bulk_items SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?")
            ->execute([$itemId]);

        $reclaimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(1, $reclaimed, 'a row with an expired lease must be reclaimable (Invariant D)');
        $this->assertSame($itemId, (int)$reclaimed[0]['id']);
        $this->assertSame(2, (int)$reclaimed[0]['attempt_count'], 'attempt_count increments on every claim, including a reclaim');
    }

    public function testBulkSendOneItemMarksCancelledWhenJobCancelledBeforeDispatch(): void
    {
        $job = $this->makeFundedBulkJob(1);
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(1, $claimed);

        // The exact race this closes: cancellation lands AFTER the claim but BEFORE dispatch.
        db()->prepare("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id = ?")->execute([$job['jobId']]);

        $result = bulk_send_one_item(db(), $claimed[0]);
        $this->assertFalse($result);

        $row = $this->itemRow($claimed[0]['id']);
        $this->assertSame('cancelled', $row['status']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);
    }

    public function testRetryableFailureSchedulesRetryWithBackoffAndDoesNotCountAsFailedYet(): void
    {
        putenv('JOB_MAX_ATTEMPTS=5');
        putenv('JOB_RETRY_BASE_SECONDS=30');
        $job = $this->makeFundedBulkJob(1);
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);

        $result = bulk_send_one_item(db(), $claimed[0]);
        $this->assertFalse($result, 'no gateway configured in the test schema — dispatch is always the retryable-unreachable branch');

        $row = $this->itemRow($claimed[0]['id']);
        $this->assertSame('pending', $row['status'], 'a retryable failure with attempts remaining goes back to pending, not a terminal status');
        $this->assertNotNull($row['next_attempt_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);

        $jobRow = db()->prepare('SELECT sent_rows, failed_rows FROM ellsms_bulk_jobs WHERE id = ?');
        $jobRow->execute([$job['jobId']]);
        $counts = $jobRow->fetch();
        $this->assertSame(0, (int)$counts['sent_rows']);
        $this->assertSame(0, (int)$counts['failed_rows'], 'a retry-scheduled item must not be counted as failed yet (Invariant G)');

        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
    }

    public function testItemNotClaimableAgainUntilItsBackoffWindowPasses(): void
    {
        $job = $this->makeFundedBulkJob(1);
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        bulk_send_one_item(db(), $claimed[0]); // schedules a retry with next_attempt_at in the future

        $tooSoon = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertSame([], $tooSoon, 'must not be claimable before next_attempt_at');

        db()->prepare('UPDATE ellsms_bulk_items SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')
            ->execute([$claimed[0]['id']]);

        $nowDue = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(1, $nowDue, 'must be claimable once next_attempt_at has passed');
    }

    public function testPermanentlyFailsAfterMaxAttemptsAndCountsAsFailedExactlyOnce(): void
    {
        putenv('JOB_MAX_ATTEMPTS=2');
        putenv('JOB_RETRY_BASE_SECONDS=1');
        $job = $this->makeFundedBulkJob(1);
        $itemId = $job['itemIds'][0];

        // Attempt 1: retryable, goes back to pending with a short backoff.
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        bulk_send_one_item(db(), $claimed[0]);
        $this->assertSame('pending', $this->itemRow($itemId)['status']);

        // Force the backoff window to have passed, then attempt 2 (== JOB_MAX_ATTEMPTS): terminal.
        db()->prepare('UPDATE ellsms_bulk_items SET next_attempt_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?')->execute([$itemId]);
        $claimed2 = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        $this->assertCount(1, $claimed2);
        $this->assertSame(2, (int)$claimed2[0]['attempt_count']);
        bulk_send_one_item(db(), $claimed2[0]);

        $row = $this->itemRow($itemId);
        $this->assertSame('failed', $row['status'], 'max attempts reached — must become terminal, not retry forever (Invariant H)');
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);

        $jobRow = db()->prepare('SELECT failed_rows FROM ellsms_bulk_jobs WHERE id = ?');
        $jobRow->execute([$job['jobId']]);
        $this->assertSame(1, (int)$jobRow->fetch()['failed_rows'], 'failed_rows must increment exactly once, on the terminal outcome only');

        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
    }

    public function testUnauthorizedOwnerFailsImmediatelyWithoutRetrying(): void
    {
        $job = $this->makeFundedBulkJob(1);
        // Revoke panel access after the job was queued — the owner is still active/not deleted,
        // just no longer ELLSMS-managed (STEP 6/31 revalidation).
        db()->prepare('UPDATE ellsms_meta SET panel_access = 0 WHERE user_id = ?')->execute([$job['userId']]);

        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        bulk_send_one_item(db(), $claimed[0]);

        $row = $this->itemRow($job['itemIds'][0]);
        $this->assertSame('failed', $row['status'], 'authorization failures are permanent, never scheduled for retry');
        $this->assertSame(1, (int)$row['attempt_count'], 'must not have consumed a second attempt trying to retry an unauthorized send');
    }

    /** STEP 22: a job with a retry-pending item must never be marked done early. */
    public function testJobCompletionDetectionTreatsRetryWaitAndInFlightItemsAsNotDone(): void
    {
        $job = $this->makeFundedBulkJob(1);
        $claimed = bulk_claim_items(db(), 'j.id = ?', [$job['jobId']], 10);
        bulk_send_one_item(db(), $claimed[0]); // -> pending, next_attempt_at in the future

        $notDone = db()->query(
            "SELECT 1 FROM ellsms_bulk_jobs j WHERE j.id = {$job['jobId']} AND NOT EXISTS (
               SELECT 1 FROM ellsms_bulk_items i WHERE i.job_id = j.id AND i.status IN ('pending','processing')
             )"
        )->fetchColumn();
        $this->assertFalse($notDone, 'the job-completion query must not treat a retry-wait item as done');
    }

    /** Reuses Phase 3's own idempotency guarantee — the exact mechanism a crash between a
     *  successful gateway send and this item's own finalize UPDATE would rely on for STEP 23/11:
     *  the actual cost commit for one item id is safe to attempt twice. */
    public function testCommittingTheSameItemsCostTwiceOnlyDebitsOnce(): void
    {
        $job = $this->makeFundedBulkJob(1);
        $before = wallet_balance($job['userId'])['available'];

        // The reservation bulk_queue_job() made for this 1-item, 1-SMS-part-content job is exactly
        // 1 — committing more than what's reserved would (correctly) fail with
        // 'amount_exceeds_remaining', a different code path than the one under test here.
        $r1 = wallet_commit_reservation('bulk_job', (string)$job['jobId'], 1, 'commit:bulk_item:' . $job['itemIds'][0]);
        $r2 = wallet_commit_reservation('bulk_job', (string)$job['jobId'], 1, 'commit:bulk_item:' . $job['itemIds'][0]);

        $this->assertTrue($r1['ok']);
        $this->assertTrue($r2['ok']);
        $this->assertTrue($r2['replayed']);

        // available_balance is untouched by a commit either way (commits spend from reserved, not
        // available — see docs/wallet-architecture.md) — what matters here is there's exactly one
        // ledger row for this idempotency key, not two.
        $this->assertSame($before, wallet_balance($job['userId'])['available']);
        $count = db()->prepare('SELECT COUNT(*) c FROM ellsms_wallet_transactions WHERE idempotency_key = ?');
        $count->execute(['commit:bulk_item:' . $job['itemIds'][0]]);
        $this->assertSame(1, (int)$count->fetch()['c']);
    }
}
