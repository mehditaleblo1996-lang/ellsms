<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Part A — the persistent delivery-status worker.
 *
 * The worker is exercised as a REAL PROCESS rather than by calling its loop in-process: the whole
 * point of this file is that `docker compose up` produces something that keeps polling and survives
 * signals and provider failures, and none of that is observable from a function call. Each test
 * therefore spawns `php cron/sms-status-worker.php` exactly as the container does.
 *
 * The polling logic itself is NOT retested here — GatewayStatusPollTest owns that, and the worker
 * deliberately adds none of its own. What is tested is the runtime: does it run passes repeatedly,
 * does it honour the interval, does it stop cleanly on SIGTERM, and does a failing provider leave it
 * running.
 *
 * DELIBERATELY DOES NOT EXTEND IntegrationTestCase, for exactly the reason WalletConcurrencyTest
 * does not: that base wraps each test in one uncommitted transaction on a single shared connection.
 * A worker spawned as a separate OS process has its OWN connection and therefore cannot see
 * uncommitted rows — every test here would poll an empty table and pass vacuously. This class
 * commits its fixtures and cleans them up explicitly instead.
 */
final class StatusWorkerTest extends TestCase
{
    /** Rows this test created, torn down explicitly since there is no enclosing transaction. */
    private array $createdJobIds = [];
    private array $createdGatewayIds = [];
    private array $createdUserIds = [];

    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        // Point BACKEND_DB_* at the test database BEFORE ensureSchemaLoaded() opens the connection —
        // otherwise db() connects with the production defaults and fails. The instance-based helper
        // (skipUnlessTestDatabaseConfigured) cannot be used from a static context, and its skip
        // branch is unreachable here anyway: the host was just checked above.
        putenv('BACKEND_DB_HOST=' . getenv('ELLSMS_TEST_DB_HOST'));
        putenv('BACKEND_DB_PORT=' . (getenv('ELLSMS_TEST_DB_PORT') ?: '3306'));
        putenv('BACKEND_DB_NAME=' . (getenv('ELLSMS_TEST_DB_NAME') ?: 'ellsms_test'));
        putenv('BACKEND_DB_USER=' . (getenv('ELLSMS_TEST_DB_USER') ?: 'ellsms_test'));
        putenv('BACKEND_DB_PASS=' . (getenv('ELLSMS_TEST_DB_PASS') ?: 'ellsms_test'));
        IntegrationTestCase::ensureSchemaLoaded();

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;

        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/fixtures/fake_status_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (self::$serverProcess === false) {
            self::markTestSkipped('Could not start the fake status server.');
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Fake status server did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        putenv('APP_ENV=testing');
        gateway_cache_reset();
    }

    /**
     * Explicit cleanup, in dependency order. Committed fixtures would otherwise accumulate across
     * runs and collide with later tests' auto-increment expectations.
     */
    protected function tearDown(): void {
        $db = db();
        foreach ($this->createdJobIds as $jobId) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
        }
        foreach ($this->createdGatewayIds as $gatewayId) {
            foreach (['ellsms_sms_gateway_parameters', 'ellsms_sms_gateway_status_connectors',
                      'ellsms_sms_gateway_send_connectors'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE gateway_id = ?")->execute([$gatewayId]);
            }
            $db->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$gatewayId]);
        }
        foreach ($this->createdUserIds as $userId) {
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
        $this->createdJobIds = $this->createdGatewayIds = $this->createdUserIds = [];
        gateway_cache_reset();
    }

    /* ================= the worker as a process ================= */

    public function testOneWorkerPassPollsAMessageWithoutAnyManualCommand(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $itemId = $this->makeSentItem($gatewayId, 'sw-once-' . bin2hex(random_bytes(3)));

        // --once is the same bounded pass the persistent loop runs; running it as a subprocess proves
        // the ENTRYPOINT works (bootstrap, env, DB connection), not merely the function it calls.
        $result = $this->runWorker(['--once'], 30);

        $this->assertSame(0, $result['exit'], "worker must exit 0; stderr: {$result['stderr']}");
        $this->assertSame('delivered', $this->item($itemId)['delivery_status']);
    }

    public function testThePersistentWorkerKeepsPollingWithoutBeingReinvoked(): void {
        // THE ACTUAL CLOSURE CRITERION: a message becomes delivered while nobody runs a poll command.
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $itemId = $this->makeSentItem($gatewayId, 'sw-live-' . bin2hex(random_bytes(3)));

        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5']);
        try {
            $delivered = $this->waitFor(function () use ($itemId): bool {
                return ($this->item($itemId)['delivery_status'] ?? null) === 'delivered';
            }, 30);
            $this->assertTrue($delivered, 'the persistent worker must poll on its own, with no operator command');
        } finally {
            $this->stopWorker($worker);
        }
    }

    public function testASecondMessageSentLaterIsAlsoPolled(): void {
        // Proves the loop CONTINUES rather than performing a single pass and idling: the second row
        // does not exist when the worker starts.
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5']);
        try {
            usleep(500000);
            $itemId = $this->makeSentItem($gatewayId, 'sw-second-' . bin2hex(random_bytes(3)));

            $delivered = $this->waitFor(function () use ($itemId): bool {
                return ($this->item($itemId)['delivery_status'] ?? null) === 'delivered';
            }, 30);
            $this->assertTrue($delivered, 'a message created after startup must still be picked up');
        } finally {
            $this->stopWorker($worker);
        }
    }

    public function testSigtermStopsTheWorkerCleanlyRatherThanRequiringSigkill(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $this->makeSentItem($gatewayId, 'sw-term-' . bin2hex(random_bytes(3)));

        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '30']);
        usleep(1500000); // let it complete one pass and enter its sleep

        $sent = proc_terminate($worker['process'], SIGTERM);
        $this->assertTrue($sent);

        $stoppedAt = microtime(true);
        $exit = $this->awaitExit($worker['process'], 20);
        $elapsed = microtime(true) - $stoppedAt;

        $this->assertSame(0, $exit, 'a graceful stop must exit 0, not be killed');
        // Well under the 30s interval: pcntl_async_signals interrupts the sleep instead of waiting
        // it out, which is what keeps `docker compose down` from taking a full interval per worker.
        $this->assertLessThan(15, $elapsed, 'SIGTERM must interrupt the sleep, not wait for it to elapse');

        // Logger mirrors CLI output to STDOUT, not stderr (app/Support/Logger.php).
        $stdout = (string)stream_get_contents($worker['pipes'][1]);
        $this->closeWorker($worker);
        $this->assertStringContainsString('gateway.status_worker.stopped', $stdout, 'shutdown must be logged');
    }

    public function testAFailingProviderDoesNotKillThePersistentWorker(): void {
        // A gateway pointed at a dead port: every poll fails at the transport level.
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET endpoint_url = ? WHERE gateway_id = ?')
            ->execute(['http://127.0.0.1:1/unreachable', $gatewayId]);
        gateway_cache_reset();
        $this->makeSentItem($gatewayId, 'sw-fail-' . bin2hex(random_bytes(3)));

        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5']);
        try {
            // Long enough for several failing cycles.
            sleep(12);
            $status = proc_get_status($worker['process']);
            $this->assertTrue($status['running'], 'a provider outage must not terminate the worker — delivery tracking would silently stop');
        } finally {
            $this->stopWorker($worker);
        }
    }

    public function testTheWorkerRecoversAndPollsOnceTheProviderComesBack(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET endpoint_url = ? WHERE gateway_id = ?')
            ->execute(['http://127.0.0.1:1/unreachable', $gatewayId]);
        gateway_cache_reset();
        $itemId = $this->makeSentItem($gatewayId, 'sw-recover-' . bin2hex(random_bytes(3)));

        // SMS_GATEWAY_VERSION_CHECK_SECONDS=1 so the worker notices the recovered configuration
        // promptly. The default 30s is a production throughput choice, not part of what is tested
        // here; leaving it would just make this test wait out a cache window.
        $worker = $this->startWorker([
            'SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5',
            'SMS_GATEWAY_VERSION_CHECK_SECONDS'          => '1',
        ]);
        try {
            sleep(6); // at least one failing cycle

            // Provider "comes back". The config_version MUST be bumped alongside the endpoint change:
            // a running worker caches its compiled connector and only re-reads it when the version
            // moves (app/Sms/GatewayCache.php), which is exactly what a real admin edit does. Without
            // the bump the worker would keep using the dead URL and this would test nothing.
            db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET endpoint_url = ? WHERE gateway_id = ?')
                ->execute([self::$baseUrl . '/status/delivered', $gatewayId]);
            db()->prepare('UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE id = ?')
                ->execute([$gatewayId]);
            // Due again, with its attempt budget reset: the failed polls consumed attempts, which is
            // the documented behaviour and not what this test is about.
            db()->prepare('UPDATE ellsms_bulk_items SET delivery_checked_at = NULL, delivery_attempts = 0 WHERE id = ?')
                ->execute([$itemId]);

            $delivered = $this->waitFor(function () use ($itemId): bool {
                return ($this->item($itemId)['delivery_status'] ?? null) === 'delivered';
            }, 40);
            $this->assertTrue($delivered, 'the next cycle after a recovery must succeed — failures must not latch');
        } finally {
            $this->stopWorker($worker);
        }
    }

    public function testTwoWorkersCannotBothPollTheSameMessage(): void {
        // A6: duplicate replicas must remain safe. The guarantee is the DB claim, not a lock, so this
        // asserts on the ATTEMPT COUNTER — the observable consequence of a double poll would be two
        // increments for one row in one due-window.
        //
        // The delay is 60s (not 0, and not 3600): it must be short enough that the row is due for the
        // FIRST worker — a row nobody polls would satisfy this assertion for entirely the wrong
        // reason — and long enough that the row is no longer due for the second, which is exactly the
        // window gateway_status_claim()'s compare-and-swap defends.
        $gatewayId = $this->makeStatusGateway('/status/queued');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET poll_initial_delay_seconds = 60 WHERE gateway_id = ?')
            ->execute([$gatewayId]);
        gateway_cache_reset();
        $itemId = $this->makeSentItem($gatewayId, 'sw-dup-' . bin2hex(random_bytes(3)));
        // Created "a while ago" so the initial delay has already elapsed and the row is due now.
        db()->prepare('UPDATE ellsms_bulk_items SET created_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?')
            ->execute([$itemId]);

        $a = $this->runWorker(['--once'], 30);
        $b = $this->runWorker(['--once'], 30);

        $this->assertSame(0, $a['exit']);
        $this->assertSame(0, $b['exit']);
        $this->assertSame(
            1,
            (int)$this->item($itemId)['delivery_attempts'],
            'the second worker must find the row already claimed and not poll it again'
        );
    }

    public function testTheIntervalIsConfigurableAndFloored(): void {
        // A2: a zero/sub-minimum interval must be raised rather than producing a busy loop. Asserted
        // through the worker's own startup log, which is the value it actually applied.
        $result = $this->runWorker(['--once'], 30, ['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '0']);

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('"interval_seconds":5', $result['stdout'], 'a sub-minimum interval must be floored to 5s');
        $this->assertStringContainsString('gateway.status_worker.interval_raised', $result['stdout'], 'and the operator must be told');
    }

    public function testTheStartupAndPassLogsCarryNoSecrets(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $this->makeSentItem($gatewayId, 'sw-log-' . bin2hex(random_bytes(3)));

        $result = $this->runWorker(['--once'], 30);

        $this->assertStringContainsString('gateway.status_worker.started', $result['stdout']);
        $this->assertStringContainsString('gateway.status_worker.pass_completed', $result['stdout']);
        $this->assertStringNotContainsString(
            (string)getenv('ELLSMS_TEST_DB_PASS'),
            $result['stdout'] . $result['stderr'],
            'a worker log must never contain the database password'
        );
    }

    /* ================= process helpers ================= */

    /** Environment a spawned worker needs to reach the same test database this process is using. */
    private function workerEnv(array $extra = []): array {
        $env = [
            'PATH'                  => getenv('PATH') ?: '/usr/bin:/bin',
            'APP_ENV'               => 'testing',
            'ELLSMS_TEST_DB_HOST'   => (string)getenv('ELLSMS_TEST_DB_HOST'),
            'ELLSMS_TEST_DB_PORT'   => (string)getenv('ELLSMS_TEST_DB_PORT'),
            'ELLSMS_TEST_DB_NAME'   => (string)getenv('ELLSMS_TEST_DB_NAME'),
            'ELLSMS_TEST_DB_USER'   => (string)getenv('ELLSMS_TEST_DB_USER'),
            'ELLSMS_TEST_DB_PASS'   => (string)getenv('ELLSMS_TEST_DB_PASS'),
            'BACKEND_DB_HOST'       => (string)getenv('ELLSMS_TEST_DB_HOST'),
            'BACKEND_DB_PORT'       => (string)getenv('ELLSMS_TEST_DB_PORT'),
            'BACKEND_DB_NAME'       => (string)getenv('ELLSMS_TEST_DB_NAME'),
            'BACKEND_DB_USER'       => (string)getenv('ELLSMS_TEST_DB_USER'),
            'BACKEND_DB_PASS'       => (string)getenv('ELLSMS_TEST_DB_PASS'),
            'SMS_GATEWAY_TRANSPORT' => '1',
        ];
        return array_merge($env, $extra);
    }

    private function workerPath(): string {
        return dirname(__DIR__, 2) . '/cron/sms-status-worker.php';
    }

    /** Starts a persistent worker. Caller must stopWorker() it. */
    private function startWorker(array $env = []): array {
        $process = proc_open(
            [PHP_BINARY, $this->workerPath()],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $this->workerEnv($env)
        );
        if ($process === false) {
            $this->markTestSkipped('Could not start the status worker process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        return ['process' => $process, 'pipes' => $pipes];
    }

    private function stopWorker(array $worker): void {
        if (!is_resource($worker['process'])) {
            return;
        }
        $status = proc_get_status($worker['process']);
        if ($status['running']) {
            proc_terminate($worker['process'], SIGTERM);
            $this->awaitExit($worker['process'], 15);
        }
        $this->closeWorker($worker);
    }

    private function closeWorker(array $worker): void {
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }

    /** Waits for a process to exit, returning its exit code (or -1 on timeout). */
    private function awaitExit($process, int $timeoutSeconds): int {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return (int)$status['exitcode'];
            }
            usleep(100000);
        }
        proc_terminate($process, SIGKILL);
        return -1;
    }

    /** Runs a worker to completion and returns its exit code and stderr. */
    private function runWorker(array $args, int $timeoutSeconds, array $env = []): array {
        $process = proc_open(
            array_merge([PHP_BINARY, $this->workerPath()], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $this->workerEnv($env)
        );
        if ($process === false) {
            $this->markTestSkipped('Could not start the status worker process.');
        }
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (microtime(true) < $deadline) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string)stream_get_contents($pipes[1]);
                $stderr .= (string)stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return ['exit' => (int)$status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
            }
            usleep(100000);
        }
        proc_terminate($process, SIGKILL);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return ['exit' => -1, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** Polls a predicate until it holds or the deadline passes. */
    private function waitFor(callable $predicate, int $timeoutSeconds): bool {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return true;
            }
            usleep(300000);
        }
        return false;
    }

    /* ================= fixtures ================= */

    private function makeStatusGateway(string $path): int {
        $db = db();
        $code = 'sw_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,1,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $this->createdGatewayIds[] = $gatewayId;

        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, success_rule_json) VALUES (?,?,?)")
           ->execute([$gatewayId, self::$baseUrl . '/send', json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []])]);

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_status_connectors
               (gateway_id, endpoint_url, http_method, response_mapping_json, status_mapping_json,
                poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
             VALUES (?,?, 'GET', ?, ?, 0, 6, 86400)"
        )->execute([
            $gatewayId, self::$baseUrl . $path,
            json_encode(['provider_status' => 'status', 'delivered_at' => 'delivered_at']),
            json_encode(['DELIVRD' => 'delivered', 'UNDELIV' => 'failed', 'ENROUTE' => 'queued', 'ACCEPTD' => 'sent']),
        ]);

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters (gateway_id, connector, location, scope, param_key, value_type, value, data_type, status, active_slot)
             VALUES (?, 'status', 'query', 'gateway', 'id', 'variable', 'provider_message_id', 'string', 'active', ?)"
        )->execute([$gatewayId, "{$gatewayId}:status:query:gateway::id"]);

        gateway_cache_reset();
        return $gatewayId;
    }

    private function makeSentItem(int $gatewayId, string $providerMessageId, string $deliveryStatus = 'sent'): int {
        $db = db();
        $userId = $this->makeUser();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'status worker test', '5000', 'done', 1)")
           ->execute([$userId]);
        $jobId = (int)$db->lastInsertId();
        $this->createdJobIds[] = $jobId;

        $db->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
             VALUES (?, '989121234567', 'x', 'sent', ?, 1, ?, ?)"
        )->execute([$jobId, $gatewayId, $providerMessageId, $deliveryStatus]);
        return (int)$db->lastInsertId();
    }

    /**
     * A committed user row. IntegrationTestCase::makeUser() is a protected instance method on a base
     * class this test deliberately does not extend, so the same two inserts are reproduced here.
     */
    private function makeUser(): int {
        $db = db();
        $username = 'swtest_' . bin2hex(random_bytes(5));
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
        $userId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')
           ->execute([$userId, '']);
        $this->createdUserIds[] = $userId;
        return $userId;
    }

    private function item(int $itemId): array {
        $st = db()->prepare('SELECT * FROM ellsms_bulk_items WHERE id = ?');
        $st->execute([$itemId]);
        return $st->fetch() ?: [];
    }
}
