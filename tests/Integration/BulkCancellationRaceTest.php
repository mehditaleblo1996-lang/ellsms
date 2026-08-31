<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #11 acceptance criterion: "Tests cover races between worker claim/send and admin
 * cancellation." Modeled directly on BulkWorkerCrashRecoveryTest.php (issue #6) -- a real worker
 * subprocess against a deliberately slow fake backend, so an admin cancellation can be issued while
 * one item is genuinely mid-flight ('processing', not 'pending'). DELIBERATELY DOES NOT extend
 * IntegrationTestCase, for the same reason: the worker is a separate OS process with its own DB
 * connection and cannot see rows held in an uncommitted enclosing transaction.
 *
 * The rule under test (app/BulkCancellation.php: bulk_cancel_campaign() only ever rewrites rows
 * still in 'pending') means the race has exactly one correct outcome regardless of scheduling: the
 * item the worker already claimed must complete on its own (the cancellation must not touch it),
 * while every other still-pending item in the same job must be cancelled.
 */
final class BulkCancellationRaceTest extends TestCase
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

        $this->captureFile = sys_get_temp_dir() . '/ellsms_cancel_race_capture_' . bin2hex(random_bytes(6)) . '.ndjson';
        $this->startFakeBackend();

        $this->originator = sprintf('%04d', 5950 + random_int(0, 40));
        $username = 'cancelrace_' . bin2hex(random_bytes(4));
        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
        $this->userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 1, ?)')
            ->execute([$this->userId, $this->originator]);

        \set_setting('default_originator', $this->originator);
        \set_setting('api_base_url', "http://127.0.0.1:{$this->fakeBackendPort}");

        $user = ['id' => $this->userId, 'role' => 'admin', 'organization_id' => null];
        [$ok, $msg, $jobId] = \bulk_queue_job($user, 'p2p', 'cancellation race test', $this->originator, null, [
            ['mobile' => '09120000101', 'content' => 'in-flight item'],
            ['mobile' => '09120000102', 'content' => 'still pending item'],
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

        $this->fakeBackendLogPath = sys_get_temp_dir() . '/ellsms_cancel_race_backend_' . getmypid() . '.log';
        $env = array_merge($_ENV ?: [], [
            // Slow enough that the admin cancellation below reliably lands while the worker's
            // first claimed item is still mid-request -- same empirically-timed approach as
            // BulkWorkerCrashRecoveryTest.
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

    private function spawnWorker(int $deadlineSecondsFromNow)
    {
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_BASE_URL' => "http://127.0.0.1:{$this->fakeBackendPort}",
            'WORKER_JOB_LEASE_SECONDS' => '30',
            // One item claimed per pass so the second item reliably stays 'pending' long enough
            // for the cancellation below to reach it before the worker ever claims it.
            'WORKER_BULK_BATCH_SIZE' => '1',
        ];
        $script = dirname(__DIR__, 2) . '/cron/load-test-worker-runner.php';
        $deadline = microtime(true) + $deadlineSecondsFromNow;
        $proc = proc_open([PHP_BINARY, $script, (string)$deadline], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to spawn load-test-worker-runner subprocess');
        return $proc;
    }

    public function testCancellationDuringAnInFlightSendLeavesTheClaimedItemAloneButCancelsTheRest(): void
    {
        $worker = $this->spawnWorker(20);

        // Give the worker time to claim exactly one item (WORKER_BULK_BATCH_SIZE=1) and issue the
        // slow provider request for it, so it is genuinely 'processing' -- not 'pending' -- when
        // the admin cancellation below runs.
        usleep(500000);
        $claimed = $this->db->query(
            "SELECT id FROM ellsms_bulk_items WHERE job_id = {$this->jobId} AND status = 'processing'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(1, $claimed, 'test timing assumption violated: expected exactly one in-flight claim before cancelling');
        $inFlightItemId = (int)$claimed[0];

        $admin = ['id' => $this->userId, 'role' => 'admin', 'organization_id' => null];
        $result = \bulk_cancel_campaign($this->jobId, $admin, 'race test: cancel while one item in flight');
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['cancelled_items'], 'only the still-pending item may be cancelled -- the in-flight one must be left alone');

        // Let the worker finish the in-flight request and exit on its own.
        $exitDeadline = microtime(true) + 15;
        do {
            $status = proc_get_status($worker);
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(200000);
        } while (microtime(true) < $exitDeadline);
        if ($status['running'] ?? false) {
            posix_kill($status['pid'], 9 /* SIGKILL, avoiding a pcntl-only constant dependency */);
        }
        proc_close($worker);

        $items = $this->db->query("SELECT id, status FROM ellsms_bulk_items WHERE job_id = {$this->jobId}")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('sent', $items[$inFlightItemId], 'the item claimed before cancellation must complete normally -- cancellation must never rewrite a processing row');
        foreach ($items as $id => $status) {
            if ($id !== $inFlightItemId) {
                $this->assertSame('cancelled', $status, "item {$id} was still pending when cancellation ran and must have been cancelled");
            }
        }

        $jobStatus = $this->db->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$this->jobId}")->fetchColumn();
        $this->assertSame('cancelled', $jobStatus, 'the job itself is flipped to cancelled immediately, independent of the in-flight item finishing later');
    }
}
