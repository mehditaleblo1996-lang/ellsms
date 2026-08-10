<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Genuine cross-process concurrency test for Invariant B ("two workers must never intentionally
 * dispatch the same message item simultaneously") — Phase 4, STEP 24.
 *
 * Deliberately does NOT extend IntegrationTestCase, matching tests/Integration/WalletConcurrencyTest.php's
 * own reasoning: that base class wraps every test in one uncommitted transaction on the single
 * shared db() connection, rolled back in tearDown() — the opposite of what a real concurrency test
 * needs (two separate OS processes, each its own connection, each seeing real committed data and
 * holding its own real row lock). This class manages its own committed test data directly and
 * cleans it up explicitly afterward.
 */
final class BulkItemConcurrencyTest extends TestCase
{
    private ?\PDO $db = null;
    private ?int $jobId = null;
    private ?int $userId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();
    }

    protected function tearDown(): void
    {
        if ($this->jobId !== null) {
            $this->db?->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$this->jobId]);
            $this->db?->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$this->jobId]);
        }
        if ($this->userId !== null) {
            $this->db?->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->userId]);
            $this->db?->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->userId]);
        }
    }

    /** Creates and COMMITS a real 'processing' job with $count pending items (must be visible to separate subprocess connections). */
    private function makeCommittedJob(int $count): int {
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')
            ->execute(['bulkconc_' . bin2hex(random_bytes(4))]);
        $this->userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
            ->execute([$this->userId, '']);

        $this->db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 't', '5000', 'processing', ?)")
            ->execute([$this->userId, $count]);
        $jobId = (int)$this->db->lastInsertId();
        $this->jobId = $jobId;

        $ins = $this->db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?, ?, 'hi', 'pending')");
        for ($i = 0; $i < $count; $i++) {
            $ins->execute([$jobId, '0912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT)]);
        }

        return $jobId;
    }

    private function spawnClaimWorker(int $jobId, int $limit): array {
        $script = __DIR__ . '/../fixtures/bulk_claim_worker.php';
        $cmd = [
            PHP_BINARY, $script,
            (string)getenv('BACKEND_DB_HOST'), (string)getenv('BACKEND_DB_PORT'),
            (string)getenv('BACKEND_DB_NAME'), (string)getenv('BACKEND_DB_USER'), (string)getenv('BACKEND_DB_PASS'),
            (string)$jobId, (string)$limit,
        ];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'Failed to spawn subprocess for concurrency test.');
        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function collectWorker(array $handle): array {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        proc_close($handle['proc']);
        $lines = array_values(array_filter(explode("\n", trim($stdout)), static fn($l) => trim($l) !== ''));
        $lastLine = $lines ? end($lines) : '';
        $decoded = json_decode($lastLine, true);
        $this->assertIsArray($decoded, "Subprocess produced no valid JSON (stderr: {$stderr}, stdout: {$stdout})");
        return $decoded;
    }

    public function testTwoConcurrentWorkersClaimingTheSamePoolNeverClaimTheSameItemTwice(): void
    {
        $itemCount = 20;
        $jobId = $this->makeCommittedJob($itemCount);

        // Both request enough headroom to grab everything if they raced unsafely (limit > half the
        // pool each) — launched before waiting on either, so they start as close together in time
        // as the OS scheduler allows, each opening its own MySQL connection and calling
        // bulk_claim_items() independently.
        $handleA = $this->spawnClaimWorker($jobId, 15);
        $handleB = $this->spawnClaimWorker($jobId, 15);

        $claimedA = $this->collectWorker($handleA);
        $claimedB = $this->collectWorker($handleB);

        $overlap = array_intersect($claimedA, $claimedB);
        $this->assertSame([], array_values($overlap), 'no item id may be claimed by both processes — SELECT ... FOR UPDATE SKIP LOCKED must fully serialize the claim');

        $union = array_unique(array_merge($claimedA, $claimedB));
        $this->assertCount($itemCount, $union, 'every pending item must end up claimed by exactly one of the two workers, none lost, none duplicated');

        $statuses = $this->db->query("SELECT status, COUNT(*) c FROM ellsms_bulk_items WHERE job_id = {$jobId} GROUP BY status")->fetchAll();
        $this->assertCount(1, $statuses);
        $this->assertSame('processing', $statuses[0]['status']);
        $this->assertSame($itemCount, (int)$statuses[0]['c']);
    }

    public function testConcurrentClaimsAcrossTwoWorkersNeverExceedThePendingPool(): void
    {
        // Fewer items than the combined limit both workers ask for — proves SKIP LOCKED means the
        // second worker gets only what's left (possibly nothing), never blocks waiting for the
        // first to finish, and never claims a row a moment too late that the first already has.
        $itemCount = 5;
        $jobId = $this->makeCommittedJob($itemCount);

        $handleA = $this->spawnClaimWorker($jobId, 10);
        $handleB = $this->spawnClaimWorker($jobId, 10);

        $claimedA = $this->collectWorker($handleA);
        $claimedB = $this->collectWorker($handleB);

        $this->assertSame([], array_values(array_intersect($claimedA, $claimedB)));
        $this->assertCount($itemCount, array_unique(array_merge($claimedA, $claimedB)));
    }
}
