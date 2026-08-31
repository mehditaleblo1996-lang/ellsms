<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Backend/report_dimension_summary.php';

/**
 * Issue #12's "tests cover ... partial failure" criterion for the daily dimensioned aggregation
 * job: if the pass is interrupted partway through a chunk, neither the aggregate table nor the
 * high-water-mark state may move -- a rerun must repeat the exact same chunk, never half-apply it.
 *
 * DELIBERATELY DOES NOT extend IntegrationTestCase, for the same reason WalletConcurrencyTest and
 * BulkWorkerCrashRecoveryTest don't: that base class wraps every test in one already-open
 * transaction on the shared db() connection, which makes report_dimension_summary_incremental_chunk()'s
 * own db_transaction() call join that outer transaction instead of owning it -- so a thrown
 * exception would NOT trigger db_transaction()'s own rollback (only the outermost owner rolls back),
 * and the test would end up "proving" a bug that cannot happen in real deployment, where the worker
 * script never runs inside a pre-opened transaction. Forcing a REAL failure with a second, genuinely
 * independent connection holding a lock is the only way to exercise the real atomicity boundary.
 */
final class ReportDimensionSummaryPartialFailureTest extends TestCase
{
    private PDO $db;
    private ?PDO $lockConn = null;
    private array $userIds = [];
    private array $jobIds = [];
    private ?int $organizationId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = \db();
    }

    protected function tearDown(): void
    {
        if ($this->lockConn !== null) {
            $this->lockConn = null; // closing the connection releases its locks/uncommitted work
        }
        foreach ($this->jobIds as $jobId) {
            $this->db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
            $this->db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
        }
        foreach ($this->userIds as $userId) {
            $this->db->prepare('DELETE FROM ellsms_wallet_reservations WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
        }
        if ($this->organizationId !== null) {
            $this->db->prepare('DELETE FROM ellsms_report_daily_dimension_summary WHERE organization_id = ?')->execute([$this->organizationId]);
            $this->db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->organizationId]);
            $this->db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->organizationId]);
        }
        foreach ($this->userIds as $userId) {
            $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
        // Restore the shared state row to a clean, committed baseline for whichever test runs next.
        $this->db->prepare('UPDATE ellsms_report_dimension_summary_state SET last_bulk_item_id = 0, last_incremental_at = NULL, last_full_rebuild_at = NULL WHERE id = 1')->execute();
    }

    private function rawConnection(): PDO {
        $pdo = new PDO(
            'mysql:host=' . getenv('BACKEND_DB_HOST') . ';port=' . getenv('BACKEND_DB_PORT') . ';dbname=' . getenv('BACKEND_DB_NAME') . ';charset=utf8mb4',
            (string)getenv('BACKEND_DB_USER'),
            (string)getenv('BACKEND_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    }

    public function testAChunkInterruptedByARealLockTimeoutLeavesNoPartialStateAndARerunCompletesCleanly(): void
    {
        $ownerId = $this->makeUserCommitted();
        $result = \create_organization($ownerId, 'dim-partial-failure-' . bin2hex(random_bytes(4)));
        $this->assertTrue($result['ok']);
        $this->organizationId = (int)$result['organization_id'];
        \wallet_credit($ownerId, 100000, 'purchase', 'test', 'seed:' . $ownerId, 'test:credit:' . $ownerId);

        [$ok, $msg, $jobId] = \bulk_queue_job(
            ['id' => $ownerId, 'role' => 'user', 'organization_id' => $this->organizationId],
            'p2p', 'partial failure test', '5999', null,
            [['mobile' => '09120000001', 'content' => 'seed']], null, null, null, 'advertising'
        );
        $this->assertTrue($ok, "seed failed: {$msg}");
        $this->jobIds[] = (int)$jobId;
        $this->db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
        $this->db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, '09120000000', 'x', 'sent')")
            ->execute([$jobId]);

        // A genuinely separate connection holds an uncommitted exclusive lock on the state row --
        // the same row report_dimension_summary_incremental_chunk()'s final UPDATE must acquire.
        $this->lockConn = $this->rawConnection();
        $this->lockConn->beginTransaction();
        $this->lockConn->query("SELECT * FROM ellsms_report_dimension_summary_state WHERE id = 1 FOR UPDATE")->fetch();

        // Give the connection under test a short, deterministic wait instead of MySQL's default
        // (often 50s), so the forced failure below happens quickly.
        $this->db->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $threw = false;
        try {
            \report_dimension_summary_incremental_chunk(50000);
        } catch (\Throwable $t) {
            $threw = true;
        }
        $this->assertTrue($threw, 'the chunk must fail while another connection holds the state row locked');

        // Partial failure must mean NO partial effect: the INSERT this chunk issued before reaching
        // the locked UPDATE must have been rolled back along with everything else in its transaction
        // -- db_transaction() genuinely owns this transaction here (unlike under IntegrationTestCase),
        // so this is the real atomicity guarantee, not an artifact of test isolation.
        $aggregateRows = (int)$this->db->query(
            "SELECT COUNT(*) FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$this->organizationId}"
        )->fetchColumn();
        $this->assertSame(0, $aggregateRows, 'a chunk that fails partway must leave zero aggregate rows behind, not a half-applied insert');

        $state = \report_dimension_summary_state();
        $this->assertSame(0, (int)($state['last_bulk_item_id'] ?? -1), 'the high-water mark must not have advanced when the chunk failed');

        // Release the lock (simulating the other process finishing) and rerun: the exact same range
        // must now apply cleanly and completely, proving the interrupted attempt left nothing to
        // clean up and nothing was lost.
        $this->lockConn->rollBack();
        $this->lockConn = null;

        $retry = \report_dimension_summary_incremental_chunk(50000);
        $this->assertSame(1, $retry['processed'], 'the rerun must process exactly the one item the failed attempt never committed');

        $recovered = (int)$this->db->query(
            "SELECT message_count FROM ellsms_report_daily_dimension_summary WHERE organization_id = {$this->organizationId} AND status = 'sent'"
        )->fetchColumn();
        $this->assertSame(1, $recovered, 'after recovery the count must be exactly right -- no loss, no duplication from the failed attempt');
    }

    private function makeUserCommitted(): int {
        $username = 'dimpartial_' . bin2hex(random_bytes(4));
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$id, '5999']);
        $this->userIds[] = $id;
        return $id;
    }
}
