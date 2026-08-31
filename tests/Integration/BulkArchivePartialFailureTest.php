<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Issue #13's "resumable/idempotent and safe for large datasets" criterion: if an archive chunk is
 * interrupted partway through, neither the archive table, the live table, nor the run's high-water
 * mark may move -- a rerun must repeat the exact same chunk, never half-apply it (an item must never
 * exist in both places, or in neither).
 *
 * DELIBERATELY DOES NOT extend IntegrationTestCase, for the same reason
 * ReportDimensionSummaryPartialFailureTest doesn't: that base class's own enclosing transaction
 * would make bulk_archive_run_chunk()'s db_transaction() call join it instead of owning it, so a
 * thrown exception would not trigger a real rollback -- see that test's docblock for the full
 * reasoning, which applies identically here.
 */
final class BulkArchivePartialFailureTest extends TestCase
{
    private PDO $db;
    private ?PDO $lockConn = null;
    private array $userIds = [];
    private int $jobId = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = \db();
    }

    protected function tearDown(): void
    {
        if ($this->lockConn !== null) {
            $this->lockConn = null;
        }
        if ($this->jobId !== 0) {
            $this->db->prepare('DELETE FROM ellsms_bulk_items_archive WHERE job_id = ?')->execute([$this->jobId]);
            $this->db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$this->jobId]);
            $this->db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$this->jobId]);
        }
        $this->db->exec("DELETE FROM ellsms_bulk_archive_runs WHERE reason = 'partial-failure-test'");
        foreach ($this->userIds as $userId) {
            $this->db->prepare('DELETE FROM ellsms_wallet_reservations WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
    }

    private function rawConnection(): PDO {
        return new PDO(
            'mysql:host=' . getenv('BACKEND_DB_HOST') . ';port=' . getenv('BACKEND_DB_PORT') . ';dbname=' . getenv('BACKEND_DB_NAME') . ';charset=utf8mb4',
            (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function makeUserCommitted(string $originator): int {
        $username = 'bulkarchpf_' . bin2hex(random_bytes(4));
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 1, ?)')
            ->execute([$id, $originator]);
        $this->userIds[] = $id;
        return $id;
    }

    public function testAChunkInterruptedByARealLockTimeoutArchivesNothingPartiallyAndARerunCompletesCleanly(): void
    {
        $ownerId = $this->makeUserCommitted('5998');
        \wallet_credit($ownerId, 100000, 'purchase', 'test', 'seed:' . $ownerId, 'test:credit:' . $ownerId);
        [$ok, $msg, $jobId] = \bulk_queue_job(
            ['id' => $ownerId, 'role' => 'admin', 'organization_id' => null],
            'p2p', 'archive partial failure test', '5998', null,
            [['mobile' => '09120000001', 'content' => 'seed']]
        );
        $this->assertTrue($ok, "seed failed: {$msg}");
        $this->jobId = (int)$jobId;
        $this->db->prepare("UPDATE ellsms_bulk_items SET status = 'sent', created_at = '2020-01-01 00:00:00' WHERE job_id = ?")->execute([$this->jobId]);
        $itemId = (int)$this->db->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$this->jobId}")->fetchColumn();

        $request = \bulk_archive_request(['id' => $ownerId, 'role' => 'admin'], '2021-01-01', 'partial-failure-test');
        $runId = $request['run_id'];
        \bulk_archive_approve(['id' => $ownerId, 'role' => 'admin'], $runId);

        // A genuinely separate connection holds an uncommitted exclusive lock on the run row --
        // the same row bulk_archive_run_chunk()'s high-water-mark UPDATE must acquire at the end.
        $this->lockConn = $this->rawConnection();
        $this->lockConn->beginTransaction();
        $this->lockConn->query("SELECT * FROM ellsms_bulk_archive_runs WHERE id = {$runId} FOR UPDATE")->fetch();

        $this->db->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $threw = false;
        try {
            \bulk_archive_run_chunk($runId, 5000);
        } catch (\Throwable $t) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the chunk must fail while another connection holds the run row locked');

        // No partial effect: the item must be in EXACTLY one place, never both (a half-applied
        // archive) and never neither (a lost row).
        $inLive = (int)$this->db->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE id = {$itemId}")->fetchColumn();
        $inArchive = (int)$this->db->query("SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id = {$itemId}")->fetchColumn();
        $this->assertSame(1, $inLive, 'a chunk that fails partway must leave the item in the live table');
        $this->assertSame(0, $inArchive, 'a chunk that fails partway must NOT have archived the item');

        $run = \bulk_archive_run($runId);
        $this->assertSame(0, (int)$run['last_archived_item_id'], 'the high-water mark must not have advanced when the chunk failed');
        $this->assertSame(0, (int)$run['rows_archived']);

        $this->lockConn->rollBack();
        $this->lockConn = null;

        $retry = \bulk_archive_run_chunk($runId, 5000);
        $this->assertSame(1, $retry['processed'], 'the rerun must process exactly the one item the failed attempt never committed');
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE id = {$itemId}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM ellsms_bulk_items_archive WHERE id = {$itemId}")->fetchColumn());
    }
}
