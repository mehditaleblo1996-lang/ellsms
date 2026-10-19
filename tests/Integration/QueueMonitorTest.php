<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * app/QueueMonitor.php — the live queue snapshot and the per-queue purge. The snapshot is asserted
 * as a delta over a baseline (the shared test database may hold committed rows from other suites);
 * the purge tests check the safety rules: only unclaimed rows are deleted, a bulk campaign is
 * stopped and its wallet reservation released, the auto-reply log is never purgeable, and only an
 * admin can purge.
 */
final class QueueMonitorTest extends IntegrationTestCase
{
    private int $adminId;
    private string $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sender = sprintf('%04d', 5300 + random_int(0, 90));
        $this->adminId = $this->makeUser(['originator' => $this->sender, 'is_admin' => 1]);
    }

    private function admin(): array
    {
        return ['id' => $this->adminId, 'role' => 'admin', 'organization_id' => null];
    }

    /** @return array{0:int,1:list<int>} */
    private function seedJob(array $owner, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['mobile' => sprintf('0912%07d', random_int(0, 9999999)), 'content' => "msg {$i}"];
        }
        [$ok, $msg, $jobId] = \bulk_queue_job($owner, 'p2p', 'queue monitor test', $this->sender, null, $rows);
        $this->assertTrue($ok, "seed failed: {$msg}");
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$jobId]);
        $ids = \db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        return [(int)$jobId, array_map('intval', $ids)];
    }

    private function claim(int $itemId, string $lease): void
    {
        \db()->prepare("UPDATE ellsms_bulk_items SET status='processing', claimed_by='test-worker', lease_expires_at = {$lease} WHERE id = ?")
            ->execute([$itemId]);
    }

    private function directRow(string $status, string $availableAt): int
    {
        \db()->prepare(
            "INSERT INTO ellsms_direct_send_queue (user_id, originator, destinations_json, content, status, available_at, claimed_at, dedup_key)
             VALUES (?, ?, '[\"09120000000\"]', 'x', ?, {$availableAt}, IF(? = 'processing', NOW(), NULL), ?)"
        )->execute([$this->adminId, $this->sender, $status, $status, bin2hex(random_bytes(32))]);
        return (int)\db()->lastInsertId();
    }

    public function testEveryQueueIsReadWithoutError(): void
    {
        // A failing collector only blanks its own row on the page, so check none fails silently.
        $snap = \queue_monitor_compute();
        $this->assertSame([], $snap['errors']);
        $this->assertSame(array_keys(\queue_monitor_definitions()), array_keys($snap['queues']));
        foreach ($snap['queues'] as $key => $row) {
            $this->assertIsArray($row, "queue {$key}");
        }
    }

    public function testSnapshotIsSharedThroughTheCache(): void
    {
        @unlink(\queue_monitor_cache_path());
        $first = \queue_monitor_snapshot();
        $this->assertFalse($first['cached']);
        $second = \queue_monitor_snapshot();
        $this->assertTrue($second['cached']);
        $this->assertSame($first['generated_at'], $second['generated_at']);
        @unlink(\queue_monitor_cache_path());
    }

    public function testSnapshotCountsBulkItemsThroughActiveJobs(): void
    {
        $before = \queue_monitor_compute()['queues']['bulk'];
        [$jobId, $ids] = $this->seedJob($this->admin(), 4);
        $this->claim($ids[0], 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)'); // a crashed worker's expired lease
        $this->claim($ids[1], 'DATE_ADD(NOW(), INTERVAL 5 MINUTE)');
        \db()->prepare("UPDATE ellsms_bulk_items SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?")->execute([$ids[2]]);

        $snap = \queue_monitor_compute();
        $bulk = $snap['queues']['bulk'];
        $this->assertSame(1, $bulk['ready'] - $before['ready']);
        $this->assertSame(1, $bulk['delayed'] - $before['delayed']);
        $this->assertSame(2, $bulk['processing'] - $before['processing']);
        $this->assertSame(1, $bulk['stale'] - $before['stale']);
        $this->assertNotNull($bulk['oldest_age']);

        $job = array_values(array_filter($snap['jobs'], static fn(array $j): bool => (int)$j['id'] === $jobId))[0] ?? null;
        $this->assertNotNull($job, 'the seeded job is listed among the active campaigns');
        $this->assertSame(2, $job['pending_count']);
        $this->assertSame(2, $job['processing_count']);
    }

    public function testSnapshotIgnoresItemsOfFinishedJobs(): void
    {
        $before = \queue_monitor_compute()['queues']['bulk'];
        [$jobId] = $this->seedJob($this->admin(), 3);
        \db()->prepare("UPDATE ellsms_bulk_jobs SET status='cancelled' WHERE id = ?")->execute([$jobId]);

        $after = \queue_monitor_compute()['queues']['bulk'];
        $this->assertSame($before['ready'], $after['ready'], 'pending rows of a cancelled job are not waiting to be sent');
    }

    public function testSnapshotSplitsDirectQueueIntoReadyDelayedAndProcessing(): void
    {
        $before = \queue_monitor_compute()['queues']['direct'];
        $this->directRow('queued', 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND)');
        $this->directRow('queued', 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)');
        $this->directRow('processing', 'UTC_TIMESTAMP()');
        $this->directRow('sent', 'UTC_TIMESTAMP()');

        $after = \queue_monitor_compute()['queues']['direct'];
        $this->assertSame(1, $after['ready'] - $before['ready']);
        $this->assertSame(1, $after['delayed'] - $before['delayed']);
        $this->assertSame(1, $after['processing'] - $before['processing']);
        $this->assertGreaterThanOrEqual(30, $after['oldest_age']);
    }

    public function testPurgeBulkDeletesWaitingItemsStopsJobAndReleasesReservation(): void
    {
        $payingUserId = $this->makeUser(['originator' => $this->sender]);
        \wallet_credit($payingUserId, 100000, 'purchase', 'test', 'seed:' . $payingUserId, 'test:credit:' . $payingUserId);
        [$jobId, $ids] = $this->seedJob(['id' => $payingUserId, 'role' => 'user', 'organization_id' => null], 4);
        $this->claim($ids[0], 'DATE_ADD(NOW(), INTERVAL 5 MINUTE)');
        \db()->prepare("UPDATE ellsms_bulk_items SET status='sent' WHERE id = ?")->execute([$ids[1]]);
        $reserved = "SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type='bulk_job' AND reference_id='{$jobId}' AND status='active'";
        $this->assertGreaterThan(0, (int)\db()->query($reserved)->fetchColumn(), 'sanity: the job holds a reservation');

        $result = \queue_monitor_purge('bulk', $this->admin());

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertGreaterThanOrEqual(2, $result['deleted']);
        $this->assertSame('cancelled', \db()->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn());
        $left = \db()->query("SELECT id, status FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame([$ids[0] => 'processing', $ids[1] => 'sent'], $left, 'the in-flight and the sent rows stay; the waiting ones are gone');
        $this->assertSame(0, (int)\db()->query($reserved)->fetchColumn(), 'the reservation is released');
        $this->assertSame([], \queue_monitor_drain_jobs(), 'nothing is left to drain');

        $audit = \db()->query("SELECT details FROM ellsms_audit_log WHERE action = 'queue_monitor.purge' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertStringContainsString('"queue":"bulk"', (string)$audit);
    }

    public function testContinuingABulkPurgeDoesNotStopACampaignCreatedSince(): void
    {
        [$newJobId] = $this->seedJob($this->admin(), 2);
        $result = \queue_monitor_purge('bulk', $this->admin(), true);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['jobs_stopped']);
        $this->assertSame('processing', \db()->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$newJobId}")->fetchColumn());
        $this->assertSame(2, (int)\db()->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = {$newJobId}")->fetchColumn());
    }

    public function testPurgeDirectDeletesOnlyQueuedRows(): void
    {
        $queued = $this->directRow('queued', 'UTC_TIMESTAMP()');
        $delayed = $this->directRow('queued', 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)');
        $processing = $this->directRow('processing', 'UTC_TIMESTAMP()');
        $sent = $this->directRow('sent', 'UTC_TIMESTAMP()');

        $result = \queue_monitor_purge('direct', $this->admin());

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $ids = implode(',', [$queued, $delayed, $processing, $sent]);
        $left = array_map('intval', \db()->query("SELECT id FROM ellsms_direct_send_queue WHERE id IN ({$ids}) ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame([$processing, $sent], $left);
    }

    public function testPurgeScheduleDeletesActiveSchedulesOnly(): void
    {
        $insert = \db()->prepare("INSERT INTO ellsms_schedule (user_id, originator, destinations, content, run_at, status) VALUES (?, ?, '[]', 'x', NOW(), ?)");
        $ids = [];
        foreach (['active', 'processing', 'done'] as $status) {
            $insert->execute([$this->adminId, $this->sender, $status]);
            $ids[$status] = (int)\db()->lastInsertId();
        }

        $this->assertTrue(\queue_monitor_purge('schedule', $this->admin())['ok']);

        $left = \db()->query('SELECT status FROM ellsms_schedule WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['processing', 'done'], $left);
    }

    public function testPurgeForwardQueueDeletesPendingRowsOnly(): void
    {
        $insert = \db()->prepare("INSERT INTO ellsms_number_forward_queue (kind, number, source_key, vars_json, status) VALUES ('mo', ?, ?, '{}', ?)");
        $ids = [];
        foreach (['pending', 'processing', 'delivered'] as $status) {
            $insert->execute([$this->sender, bin2hex(random_bytes(8)), $status]);
            $ids[$status] = (int)\db()->lastInsertId();
        }

        $this->assertTrue(\queue_monitor_purge('forward', $this->admin())['ok']);

        $left = \db()->query('SELECT status FROM ellsms_number_forward_queue WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['processing', 'delivered'], $left);
    }

    public function testPurgeImportCancelsTheImportAndDeletesItsChunksAndStagedRows(): void
    {
        $db = \db();
        $db->prepare("INSERT INTO ellsms_import_jobs (organization_id, user_id, source_type, storage_key, status) VALUES (1, ?, 'p2p', ?, 'analyzing')")
           ->execute([$this->adminId, 'test-' . bin2hex(random_bytes(6))]);
        $importId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_import_chunks (import_job_id, chunk_no, status) VALUES (?, 1, 'pending'), (?, 2, 'completed')")
           ->execute([$importId, $importId]);
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, originator, status, source_import_job_id) VALUES (?, 'p2p', ?, 'staged', ?)")
           ->execute([$this->adminId, $this->sender, $importId]);
        $stagedJobId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000001', 'x', 'pending'), (?, '09120000002', 'x', 'pending')")
           ->execute([$stagedJobId, $stagedJobId]);

        $result = \queue_monitor_purge('import', $this->admin());

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['done']);
        $this->assertSame(1, $result['jobs_stopped']);
        $this->assertSame('cancelled', $db->query("SELECT status FROM ellsms_import_jobs WHERE id = {$importId}")->fetchColumn());
        $this->assertSame(['completed'], $db->query("SELECT status FROM ellsms_import_chunks WHERE import_job_id = {$importId}")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('cancelled', $db->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$stagedJobId}")->fetchColumn());
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = {$stagedJobId}")->fetchColumn());
    }

    public function testAutoreplyQueueIsNeverPurged(): void
    {
        $result = \queue_monitor_purge('autoreply', $this->admin());
        $this->assertFalse($result['ok']);
        $this->assertSame('not_purgeable', $result['error']);
    }

    public function testOnlyAnAdminCanPurge(): void
    {
        $queued = $this->directRow('queued', 'UTC_TIMESTAMP()');
        $result = \queue_monitor_purge('direct', ['id' => $this->adminId, 'role' => 'user']);
        $this->assertFalse($result['ok']);
        $this->assertSame('forbidden', $result['error']);
        $this->assertSame(1, (int)\db()->query("SELECT COUNT(*) FROM ellsms_direct_send_queue WHERE id = {$queued}")->fetchColumn());
    }
}
