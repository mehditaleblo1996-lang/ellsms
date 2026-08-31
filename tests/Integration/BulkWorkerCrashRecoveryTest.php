<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6 — "worker crash/restart does not silently lose accepted messages."
 *
 * Phase 9's own report (docs/phase-9-final-report.md) describes a real `kill -9` crash test that
 * confirmed the lease-based reclaim model end-to-end, but it was a one-off manual run, not a
 * repeatable automated test — this is that test. DELIBERATELY DOES NOT extend
 * IntegrationTestCase, for the same reason WalletConcurrencyTest/StatusWorkerTest don't: a worker
 * spawned as a real, separate OS process has its own DB connection and cannot see rows held in an
 * uncommitted enclosing transaction. Fixtures are committed and torn down explicitly instead.
 *
 * Uses tests/fixtures/fake_backend_server.php's FAKE_BACKEND_CAPTURE_FILE (added for this test) to
 * tell "the provider was asked to send this destination" apart from what the (about to be killed)
 * worker process itself ever found out — the whole point of a crash test being that the worker
 * never gets to record the outcome of the request it was mid-flight on when killed.
 */
final class BulkWorkerCrashRecoveryTest extends TestCase
{
    private \PDO $db;
    private int $userId;
    private int $jobId;
    private string $originator;
    private $fakeBackendProcess = null;
    private string $captureFile;
    private string $fakeBackendLogPath;
    private int $fakeBackendPort = 0;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            $this->markTestSkipped('proc_open()/posix_kill() are not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = \db();

        $this->captureFile = sys_get_temp_dir() . '/ellsms_crash_capture_' . bin2hex(random_bytes(6)) . '.ndjson';
        $this->startFakeBackend();

        $this->originator = sprintf('%04d', 5900 + random_int(0, 90));
        $username = 'crashtest_' . bin2hex(random_bytes(4));
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
        $this->userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 1, ?)')
            ->execute([$this->userId, $this->originator]);

        \set_setting('default_originator', $this->originator);
        \set_setting('api_base_url', "http://127.0.0.1:{$this->fakeBackendPort}");

        $user = ['id' => $this->userId, 'role' => 'admin', 'organization_id' => null];
        [$ok, $msg, $jobId] = \bulk_queue_job($user, 'p2p', 'crash recovery test', $this->originator, null, [
            ['mobile' => '09120000001', 'content' => 'crash test message'],
        ]);
        $this->assertTrue($ok, "seed job failed: {$msg}");
        $this->jobId = (int)$jobId;
        $this->db->prepare("UPDATE ellsms_bulk_jobs SET status='processing' WHERE id = ?")->execute([$this->jobId]);
    }

    protected function tearDown(): void
    {
        if ($this->fakeBackendProcess !== null && is_resource($this->fakeBackendProcess)) {
            @proc_terminate($this->fakeBackendProcess);
            @proc_close($this->fakeBackendProcess);
        }
        @unlink($this->captureFile);
        @unlink($this->fakeBackendLogPath);

        $this->db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$this->jobId]);
        $this->db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$this->jobId]);
        $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$this->userId]);
        $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->userId]);
        $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->userId]);
    }

    private function startFakeBackend(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "could not allocate a local port: {$errstr}");
        $name = stream_socket_get_name($socket, false);
        $this->fakeBackendPort = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $this->fakeBackendLogPath = sys_get_temp_dir() . '/ellsms_crash_backend_' . getmypid() . '.log';
        $env = array_merge($_ENV ?: [], [
            // Slow enough that a worker killed ~500ms after issuing the request is reliably still
            // waiting on it -- matching Phase 9's own empirically-timed kill-9 methodology
            // (docs/phase-9-final-report.md: "kill -9'd it ~800ms after claim").
            'FAKE_BACKEND_LATENCY_MS' => '3000',
            'FAKE_BACKEND_CAPTURE_FILE' => $this->captureFile,
        ]);
        $this->fakeBackendProcess = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->fakeBackendPort}", dirname(__DIR__) . '/fixtures/fake_backend_server.php'],
            [1 => ['file', $this->fakeBackendLogPath, 'w'], 2 => ['file', $this->fakeBackendLogPath, 'a']],
            $pipes, null, $env
        );
        $this->assertIsResource($this->fakeBackendProcess, 'could not start fake backend server');

        $booted = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(100000);
            $conn = @fsockopen('127.0.0.1', $this->fakeBackendPort, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $this->assertTrue($booted, 'fake backend server never accepted connections');
    }

    /** @return list<array{path:string,destinations:array,received_at:float}> */
    private function readCaptured(): array
    {
        if (!is_file($this->captureFile)) {
            return [];
        }
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents($this->captureFile))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    private function spawnWorker(int $deadlineSecondsFromNow, array $envOverrides = [])
    {
        $env = array_merge([
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_BASE_URL' => "http://127.0.0.1:{$this->fakeBackendPort}",
            'WORKER_JOB_LEASE_SECONDS' => '3',
            'WORKER_BULK_BATCH_SIZE' => '20',
        ], $envOverrides);
        $script = dirname(__DIR__, 2) . '/cron/load-test-worker-runner.php';
        $deadline = microtime(true) + $deadlineSecondsFromNow;
        $proc = proc_open([PHP_BINARY, $script, (string)$deadline], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to spawn load-test-worker-runner subprocess');
        return $proc;
    }

    public function testCrashedWorkerLeavesTheClaimedItemRecoverableNotLost(): void
    {
        $worker = $this->spawnWorker(30);

        // Give the worker time to claim the item and issue the (slow) provider request, then kill
        // it hard -- no graceful shutdown, exactly the scenario the lease model exists for.
        usleep(500000);
        $status = proc_get_status($worker);
        $this->assertTrue($status['running'] ?? false, 'worker exited before the kill -- test timing assumption violated');
        posix_kill($status['pid'], 9 /* SIGKILL, avoiding a pcntl-only constant dependency */);
        proc_close($worker);

        $item = $this->db->query("SELECT * FROM ellsms_bulk_items WHERE job_id = {$this->jobId}")->fetch();
        $this->assertNotFalse($item, 'the item must still exist as a real row after the crash -- silent loss would mean it vanished');
        $this->assertSame('processing', $item['status'], 'a genuinely in-flight claim should still show processing, not silently reverted or dropped');
        $this->assertNotNull($item['lease_expires_at'], 'a claimed row must carry a lease so it becomes reclaimable');

        $captured = $this->readCaptured();
        $this->assertNotEmpty($captured, 'the provider should have actually received the request the crashed worker issued');
    }

    public function testAfterLeaseExpiryAFreshWorkerCompletesTheCrashedItemExactlyOnce(): void
    {
        $worker = $this->spawnWorker(30);
        usleep(500000);
        $status = proc_get_status($worker);
        $this->assertTrue($status['running'] ?? false, 'worker exited before the kill -- test timing assumption violated');
        posix_kill($status['pid'], 9 /* SIGKILL, avoiding a pcntl-only constant dependency */);
        proc_close($worker);

        // job_lease_seconds() (app/jobqueue.php) enforces a hard floor of 30s regardless of
        // WORKER_JOB_LEASE_SECONDS — a real crash victim's lease is genuinely valid that long, by
        // design. Waiting it out for real would make this test take 30+ seconds; instead, simulate
        // "time has passed since the crash" directly, the same end state a real 30-second wait
        // would produce. This is deliberately NOT cron/jobs-recover.php --force: that tool only
        // ever clears a lease that has ALREADY expired (never a still-valid one) — it's an
        // operator nudge for genuinely-stuck rows, not a time machine, so it cannot stand in for a
        // real wait here.
        $this->db->prepare('UPDATE ellsms_bulk_items SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE job_id = ?')
            ->execute([$this->jobId]);

        // A second, completely fresh worker to actually finish it. The fake backend process is
        // already running with its original FAKE_BACKEND_LATENCY_MS=3000 (env is fixed at
        // proc_open time, not per-request), so this recovery attempt is slow too -- deadline sized
        // accordingly.
        $recovery = $this->spawnWorker(15);
        $exitDeadline = microtime(true) + 20;
        do {
            $recoveryStatus = proc_get_status($recovery);
            if (!($recoveryStatus['running'] ?? false)) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $exitDeadline);
        if ($recoveryStatus['running'] ?? false) {
            posix_kill($recoveryStatus['pid'], 9 /* SIGKILL, avoiding a pcntl-only constant dependency */);
        }
        proc_close($recovery);

        $item = $this->db->query("SELECT * FROM ellsms_bulk_items WHERE job_id = {$this->jobId}")->fetch();
        $this->assertSame('sent', $item['status'], 'not silently lost: the crashed item must eventually complete via reclaim, never stay stuck forever');
        $this->assertGreaterThanOrEqual(2, (int)$item['attempt_count'], 'attempt_count must show the reclaim actually happened (not the same attempt slipping through)');

        // Not silently duplicated: the reclaim IS a second real request to the provider (this
        // architecture is at-least-once, documented in docs/job-queue-architecture.md), but it must
        // be fully visible via attempt_count/captured requests, never hidden -- and never MORE than
        // the two real attempts (crashed + reclaimed) this scenario actually made.
        $captured = $this->readCaptured();
        $destinationHits = 0;
        foreach ($captured as $entry) {
            if (in_array('09120000001', $entry['destinations'] ?? [], true)) {
                $destinationHits++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $destinationHits, 'the destination must have reached the provider at least once -- never silently lost');
        $this->assertLessThanOrEqual(2, $destinationHits, 'exactly the crashed attempt plus the one real reclaim -- more would mean uncontrolled duplication');
    }
}
