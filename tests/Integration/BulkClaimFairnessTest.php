<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Issue #3 re-audit: end-to-end proof (real MySQL, real claim SQL, real allocate_priority_quota())
 * that the per-tick fairness split actually prevents starvation under a sustained mixed backlog,
 * and that the runtime-configurable floors (app/QueueFairness.php's
 * QUEUE_CLASS_MIN_SHARE_<CLASS> env vars) change real claim behavior without any code change.
 *
 * tests/Unit/QueueFairnessTest.php already proves allocate_priority_quota() itself is correct in
 * isolation; this file proves bulk_claim_unthrottled_items_by_class() (app/backend.php) wires that
 * allocator to the real ellsms_bulk_items claim query correctly.
 */
final class BulkClaimFairnessTest extends IntegrationTestCase
{
    private int $ownerId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('%04d', 5800 + random_int(0, 90));
        $this->ownerId = $this->makeUser(['originator' => $this->sender, 'is_admin' => 1]);
        \wallet_credit($this->ownerId, 1000000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);
    }

    private function seedJob(string $messageClass, int $count): int {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', random_int(0, 9999999)), 'content' => 'x'];
        }
        $actor = ['id' => $this->ownerId, 'role' => 'admin', 'organization_id' => null];
        [$ok, $msg, $jobId] = \bulk_queue_job($actor, 'smart', 'fairness test', $this->sender, 'x', $rows, null, null, null, $messageClass);
        $this->assertTrue($ok, "seed failed: {$msg}");
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);
        return (int)$jobId;
    }

    /** Resets every claimed ('processing') item back to 'pending' -- simulates a backlog that is
     * continuously refilled at least as fast as it drains, the scenario the starvation guarantee
     * exists for, without needing real workers to actually complete sends between ticks. */
    private function resetProcessingToPending(int $jobId): void {
        \db()->prepare("UPDATE ellsms_bulk_items SET status='pending', claimed_by=NULL, lease_expires_at=NULL WHERE job_id = ? AND status = 'processing'")
            ->execute([$jobId]);
    }

    public function testAdvertisingIsNeverStarvedAcrossMultipleTicksOfARealSustainedBulkCampaignBacklog(): void
    {
        $bulkJobId = $this->seedJob('bulk_campaign', 500);
        $adJobId = $this->seedJob('advertising', 30);

        $totalAdvertisingClaimed = 0;
        $totalBulkClaimed = 0;
        for ($tick = 0; $tick < 5; $tick++) {
            $claimed = \bulk_claim_unthrottled_items_by_class(\db(), 20);
            foreach ($claimed as $item) {
                if ((int)$item['job_id'] === $adJobId) { $totalAdvertisingClaimed++; }
                if ((int)$item['job_id'] === $bulkJobId) { $totalBulkClaimed++; }
            }
            // Simulate the backlog persisting (more Bulk Campaign work keeps arriving) rather than
            // draining after one tick, which is what would make a starvation bug invisible.
            $this->resetProcessingToPending($bulkJobId);
            $this->resetProcessingToPending($adJobId);
        }

        $this->assertGreaterThan(0, $totalAdvertisingClaimed, 'advertising must make real progress across a sustained multi-tick bulk backlog, never zero');
        $this->assertGreaterThan(0, $totalBulkClaimed);
    }

    public function testCustomEnvironmentFloorChangesRealClaimDistributionWithoutCodeChanges(): void
    {
        $bulkJobId = $this->seedJob('bulk_campaign', 500);
        $adJobId = $this->seedJob('advertising', 500);

        putenv('QUEUE_CLASS_MIN_SHARE_ADVERTISING=0.80');
        putenv('QUEUE_CLASS_MIN_SHARE_BULK_CAMPAIGN=0.05');
        try {
            $claimed = \bulk_claim_unthrottled_items_by_class(\db(), 20);
        } finally {
            putenv('QUEUE_CLASS_MIN_SHARE_ADVERTISING');
            putenv('QUEUE_CLASS_MIN_SHARE_BULK_CAMPAIGN');
        }

        $adCount = 0; $bulkCount = 0;
        foreach ($claimed as $item) {
            if ((int)$item['job_id'] === $adJobId) { $adCount++; }
            if ((int)$item['job_id'] === $bulkJobId) { $bulkCount++; }
        }
        // With both classes equally backlogged and Advertising's floor raised to 80% of a 20-row
        // budget, it must claim the clear majority -- proving the environment value actually
        // reached the real claim query, not just the pure allocator function.
        $this->assertGreaterThanOrEqual(14, $adCount, 'a configured 80% floor must dominate the real claim distribution');
        $this->assertSame(20, $adCount + $bulkCount);
    }
}
