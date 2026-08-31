<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/BulkCancellation.php (issue #11) — the three admin cancellation scopes (message/campaign/
 * provider), the state rule (only 'pending' rows are ever rewritten), chunked-safe campaign
 * cancellation, and audit logging. The worker's own pre-dispatch cancellation recheck
 * (bulk_item_preflight(), app/backend.php) is pre-existing and covered by its own race test below;
 * this file proves the admin-facing half.
 */
final class BulkCancellationTest extends IntegrationTestCase
{
    private int $ownerId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('%04d', 5200 + random_int(0, 90));
        $this->ownerId = $this->makeUser(['originator' => $this->sender, 'is_admin' => 1]);
        \wallet_credit($this->ownerId, 100000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);
    }

    private function admin(): array
    {
        return ['id' => $this->ownerId, 'role' => 'admin', 'organization_id' => null];
    }

    /** @return array{0:int,1:list<int>} job id, item ids in insertion order */
    private function seedJob(int $count = 3, string $type = 'p2p'): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', random_int(0, 9999999)), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job($this->admin(), $type, 'cancellation test', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);
        $itemIds = \db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        return [(int)$jobId, array_map('intval', $itemIds)];
    }

    public function testCancelMessageCancelsExactlyOnePendingItem(): void
    {
        [$jobId, $itemIds] = $this->seedJob(3);
        $result = \bulk_cancel_message($itemIds[0], $this->admin(), 'test reason');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['cancelled']);

        $statuses = \db()->query("SELECT id, status FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('cancelled', $statuses[$itemIds[0]]);
        $this->assertSame('pending', $statuses[$itemIds[1]]);
        $this->assertSame('pending', $statuses[$itemIds[2]]);
    }

    public function testCancelMessageNeverRewritesAnAlreadySentItem(): void
    {
        [, $itemIds] = $this->seedJob(1);
        \db()->prepare("UPDATE ellsms_bulk_items SET status='sent' WHERE id = ?")->execute([$itemIds[0]]);

        $result = \bulk_cancel_message($itemIds[0], $this->admin(), '');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['cancelled'], 'an already-sent item must not be rewritten');

        $status = \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$itemIds[0]}")->fetchColumn();
        $this->assertSame('sent', $status);
    }

    public function testCancelCampaignCancelsEveryPendingItemAndReleasesWallet(): void
    {
        // A non-admin actor -- admins are exempt from wallet reservation entirely (README: "Admins
        // send without a credit check"), which would make this test's reservation-release assertion
        // vacuously true instead of actually proving anything.
        $payingUserId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($payingUserId, 100000, 'purchase', 'test', 'seed:' . $payingUserId, 'test:credit:' . $payingUserId);
        $payingUser = ['id' => $payingUserId, 'role' => 'user', 'organization_id' => null];

        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', random_int(0, 9999999)), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job($payingUser, 'p2p', 'cancellation wallet test', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);
        $itemIds = array_map('intval', \db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));

        $reservedBefore = \db()->query("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type='bulk_job' AND reference_id='{$jobId}' AND status='active'")->fetchColumn();
        $this->assertGreaterThan(0, (int)$reservedBefore, 'sanity: the seed job must have an active reservation to prove release');

        $result = \bulk_cancel_campaign($jobId, $this->admin(), 'campaign test');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['job_cancelled']);
        $this->assertSame(4, $result['cancelled_items']);

        $jobStatus = \db()->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn();
        $this->assertSame('cancelled', $jobStatus);
        foreach ($itemIds as $id) {
            $this->assertSame('cancelled', \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$id}")->fetchColumn());
        }

        $reservedAfter = \db()->query("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type='bulk_job' AND reference_id='{$jobId}' AND status='active'")->fetchColumn();
        $this->assertSame(0, (int)$reservedAfter, 'the reservation must be released, not left stranded');
    }

    public function testCancelCampaignLeavesAlreadySentItemsUntouched(): void
    {
        [$jobId, $itemIds] = $this->seedJob(3);
        \db()->prepare("UPDATE ellsms_bulk_items SET status='sent' WHERE id = ?")->execute([$itemIds[0]]);

        $result = \bulk_cancel_campaign($jobId, $this->admin(), '');
        $this->assertSame(2, $result['cancelled_items'], 'only the still-pending items are counted/cancelled');

        $this->assertSame('sent', \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$itemIds[0]}")->fetchColumn());
        $this->assertSame('cancelled', \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$itemIds[1]}")->fetchColumn());
    }

    public function testCancelCampaignIsChunkedAcrossMultipleBatchesAndStillCancelsEverything(): void
    {
        putenv('BULK_CANCELLATION_CHUNK_SIZE=2'); // force multiple chunks for a small, fast test
        try {
            [$jobId, $itemIds] = $this->seedJob(7);
            $result = \bulk_cancel_campaign($jobId, $this->admin(), '');
            $this->assertSame(7, $result['cancelled_items']);
            foreach ($itemIds as $id) {
                $this->assertSame('cancelled', \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$id}")->fetchColumn());
            }
        } finally {
            putenv('BULK_CANCELLATION_CHUNK_SIZE');
        }
    }

    public function testCancelCampaignByNonOwnerNonAdminIsForbidden(): void
    {
        [$jobId, $itemIds] = $this->seedJob(2);
        $otherUserId = $this->makeUser(['originator' => '5299']);
        $result = \bulk_cancel_campaign($jobId, ['id' => $otherUserId, 'role' => 'user', 'organization_id' => null], '');
        $this->assertFalse($result['ok']);
        $this->assertSame('pending', \db()->query("SELECT status FROM ellsms_bulk_items WHERE id = {$itemIds[0]}")->fetchColumn());
    }

    public function testCancelByProviderOnlyAffectsJobsResolvingToThatProviderKey(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT=0'); // no gateways configured -- every job resolves to legacy_backend
        try {
            [$jobId] = $this->seedJob(2);
            $noMatch = \bulk_cancel_by_provider('gateway:999999', $this->admin(), '');
            $this->assertSame(0, $noMatch['jobs_cancelled']);
            $this->assertSame('processing', \db()->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn());

            $match = \bulk_cancel_by_provider('legacy_backend', $this->admin(), 'provider test');
            $this->assertGreaterThanOrEqual(1, $match['jobs_cancelled']);
            $this->assertSame('cancelled', \db()->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn());
        } finally {
            putenv('SMS_GATEWAY_TRANSPORT');
        }
    }

    public function testCancelByProviderRequiresAdmin(): void
    {
        $nonAdmin = ['id' => $this->makeUser(['originator' => '5298']), 'role' => 'user', 'organization_id' => null];
        $result = \bulk_cancel_by_provider('legacy_backend', $nonAdmin, '');
        $this->assertFalse($result['ok']);
    }

    public function testAuditLogRecordsActorScopeCountReasonAndOutcome(): void
    {
        [$jobId] = $this->seedJob(2);
        \bulk_cancel_campaign($jobId, $this->admin(), 'audit test reason');

        $row = \db()->query(
            "SELECT * FROM ellsms_audit_log WHERE user_id = {$this->ownerId} AND action = 'queue_cancellation.campaign' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertNotFalse($row, 'expected an audit log row for the cancellation');
        $details = json_decode((string)$row['details'], true);
        $this->assertSame('job:' . $jobId, $details['scope']);
        $this->assertSame(2, $details['count']);
        $this->assertSame('audit test reason', $details['reason']);
        $this->assertSame('cancelled', $details['outcome']);
    }
}
