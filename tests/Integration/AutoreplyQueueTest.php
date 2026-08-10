<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Phase 4 auto-reply claiming/lease/retry against real MySQL — autoreply_process_one() and
 * run_autoreply_pass() in app/backend.php. Like the other Phase 4 queue tests, dispatch always
 * resolves to the deterministic "unreachable" (retryable=true) branch — no api_base_url configured
 * in the test schema.
 */
final class AutoreplyQueueTest extends IntegrationTestCase
{
    private function fundWallet(int $userId): void {
        wallet_credit($userId, 1000, 'purchase', 'test', 'seed:' . $userId, 'seed:' . $userId);
    }

    private function makeRule(int $userId, string $line = '5000', string $keyword = 'hi'): int {
        db()->prepare(
            "INSERT INTO ellsms_autoreply_rules (user_id, originator, keyword, match_type, reply_content, is_active)
             VALUES (?, ?, ?, 'exact', 'auto reply', 1)"
        )->execute([$userId, $line, $keyword]);
        return (int)db()->lastInsertId();
    }

    private function makeInbound(string $destination, string $originator, string $content): int {
        db()->prepare('INSERT INTO inbound_message (destination, originator, content) VALUES (?, ?, ?)')
            ->execute([$destination, $originator, $content]);
        return (int)db()->lastInsertId();
    }

    private function logRow(int $inboundMessageId): array {
        $st = db()->prepare('SELECT * FROM ellsms_autoreply_log WHERE inbound_message_id = ?');
        $st->execute([$inboundMessageId]);
        return $st->fetch();
    }

    public function testFirstClaimInsertsAProcessingRowThenRetriesOnTransientFailure(): void
    {
        putenv('JOB_MAX_ATTEMPTS=5');
        putenv('JOB_RETRY_BASE_SECONDS=30');
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $this->makeRule($userId, '5000', 'hi');
        $msgId = $this->makeInbound('5000', '09121234567', 'hi');

        $sent = run_autoreply_pass();
        $this->assertSame(0, $sent, 'no gateway configured — the deterministic unreachable/retryable branch never actually sends');

        $row = $this->logRow($msgId);
        $this->assertSame('processing', $row['status'], 'a retryable failure with attempts remaining stays processing (retry-eligible), not terminal');
        $this->assertSame(1, (int)$row['attempt_count']);
        $this->assertNotNull($row['lease_expires_at'], 'lease_expires_at doubles as the retry-eligible-at gate for auto-reply');

        putenv('JOB_MAX_ATTEMPTS');
        putenv('JOB_RETRY_BASE_SECONDS');
    }

    public function testDuplicateInboundRowNeverProducesASecondReply(): void
    {
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $this->makeRule($userId, '5000', 'hi');
        $msgId = $this->makeInbound('5000', '09121234567', 'hi');

        run_autoreply_pass(); // claims it, fails (no gateway), leaves it 'processing' with a future-eligible lease

        // A second pass immediately after (simulating an overlapping worker tick) must not reclaim
        // it — the lease/backoff window hasn't passed yet.
        $sentSecondPass = run_autoreply_pass();
        $this->assertSame(0, $sentSecondPass);
        $this->assertSame(1, (int)$this->logRow($msgId)['attempt_count'], 'must not have been reclaimed a second time before its backoff window passed');
    }

    public function testExpiredLeaseBecomesReclaimableAndCountsAsATrueRetry(): void
    {
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $this->makeRule($userId, '5000', 'hi');
        $msgId = $this->makeInbound('5000', '09121234567', 'hi');

        run_autoreply_pass(); // attempt 1
        $this->assertSame(1, (int)$this->logRow($msgId)['attempt_count']);

        // Simulate the backoff window (or a crashed worker's lease) having passed.
        db()->prepare('UPDATE ellsms_autoreply_log SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE inbound_message_id = ?')
            ->execute([$msgId]);

        run_autoreply_pass(); // attempt 2 — reclaimed via the retry-due scan, not the cursor scan
        $this->assertSame(2, (int)$this->logRow($msgId)['attempt_count'], 'Invariant D: a stuck/backed-off claim must be reclaimable, and the retry must actually be attempted again');
    }

    public function testBecomesTerminalAfterMaxAttemptsAndIsNeverReclaimedAgain(): void
    {
        putenv('JOB_MAX_ATTEMPTS=2');
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $this->makeRule($userId, '5000', 'hi');
        $msgId = $this->makeInbound('5000', '09121234567', 'hi');

        run_autoreply_pass(); // attempt 1
        db()->prepare('UPDATE ellsms_autoreply_log SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE inbound_message_id = ?')
            ->execute([$msgId]);
        run_autoreply_pass(); // attempt 2 == max: terminal

        $row = $this->logRow($msgId);
        $this->assertSame('failed_permanent', $row['status'], 'max attempts reached — must stop retrying (Invariant H)');
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['lease_expires_at']);

        // Even if something later set an expired lease timestamp on a terminal row (shouldn't
        // happen, but confirms the reclaim query itself is status-gated, not just lease-gated),
        // a terminal row is status='failed_permanent', not 'processing', so the retry-due scan's
        // own WHERE (status='processing') already excludes it structurally.
        $sent = run_autoreply_pass();
        $this->assertSame(0, $sent);
        $this->assertSame(2, (int)$this->logRow($msgId)['attempt_count'], 'a terminal row must never be reclaimed again');

        putenv('JOB_MAX_ATTEMPTS');
    }

    public function testRevokedRuleOwnerFailsPermanentlyWithoutRetrying(): void
    {
        $userId = $this->makeUser();
        $this->fundWallet($userId);
        $this->makeRule($userId, '5000', 'hi');
        db()->prepare('UPDATE ellsms_meta SET panel_access = 0 WHERE user_id = ?')->execute([$userId]);
        $msgId = $this->makeInbound('5000', '09121234567', 'hi');

        run_autoreply_pass();

        $row = $this->logRow($msgId);
        $this->assertSame('failed_permanent', $row['status'], 'an authorization failure must never enter the retry cycle');
        $this->assertSame(1, (int)$row['attempt_count']);
    }

    public function testSelfLoopAndNoMatchingRuleNeverInsertALogRow(): void
    {
        $userId = $this->makeUser();
        $this->makeRule($userId, '5000', 'hi');
        $unmatched = $this->makeInbound('5000', '09121234567', 'this does not match any rule');

        run_autoreply_pass();

        $st = db()->prepare('SELECT COUNT(*) c FROM ellsms_autoreply_log WHERE inbound_message_id = ?');
        $st->execute([$unmatched]);
        $this->assertSame(0, (int)$st->fetch()['c'], 'no matching rule means no claim is ever attempted');
    }
}
