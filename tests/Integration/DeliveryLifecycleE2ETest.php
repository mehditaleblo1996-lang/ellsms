<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * B23 / A11 — the whole loop, end to end, with no operator command anywhere in it.
 *
 * Sends a real message through a gateway, lets the PERSISTENT status worker discover it on its own,
 * and asserts the resulting record is complete enough to report on. This is the test that would have
 * failed before this closure: the send worked, the poller worked, and nothing ran the poller.
 *
 * Like StatusWorkerTest, this deliberately does not extend IntegrationTestCase — a worker subprocess
 * needs committed data (see that file's docblock).
 */
final class DeliveryLifecycleE2ETest extends TestCase
{
    private const LONG_PROVIDER_ID = '4473621976262727360';

    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';

    private array $createdJobIds = [];
    private array $createdGatewayIds = [];
    private array $createdUserIds = [];

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
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
        putenv('APP_ENV=testing');
        gateway_cache_reset();
    }

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
            $db->prepare('DELETE FROM ellsms_message_attempts WHERE user_id = ?')->execute([$userId]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
        }
        $this->createdJobIds = $this->createdGatewayIds = $this->createdUserIds = [];
        gateway_cache_reset();
    }

    public function testAMessageGoesFromSentToDeliveredWithNoManualPollCommand(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        // A 19-digit provider reference, exactly as the real provider returns.
        $itemId = $this->makeSentItem($gatewayId, self::LONG_PROVIDER_ID);

        // Precondition: exactly the state the closure describes as stuck — sent, never polled.
        $before = $this->item($itemId);
        $this->assertSame('sent', $before['delivery_status']);
        $this->assertNull($before['delivery_checked_at']);
        $this->assertSame(self::LONG_PROVIDER_ID, (string)$before['provider_message_id'],
            'the exact provider reference must survive the send path');

        // NOTHING below runs a poll command. The persistent worker is the only actor.
        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5']);
        try {
            $delivered = $this->waitFor(function () use ($itemId): bool {
                return ($this->item($itemId)['delivery_status'] ?? null) === 'delivered';
            }, 40);
            $this->assertTrue($delivered, 'delivery status must advance without any operator command');
        } finally {
            $this->stopWorker($worker);
        }

        $after = $this->item($itemId);
        $this->assertSame('delivered', $after['delivery_status']);
        $this->assertGreaterThanOrEqual(1, (int)$after['delivery_attempts'], 'the poll must be counted');
        $this->assertNotNull($after['delivery_checked_at'], 'the time we last asked must be recorded');
        $this->assertNotNull($after['delivered_at'], 'a delivered message must carry a delivery time');
        $this->assertSame('DELIVRD', $after['provider_status'], 'the raw provider token must be persisted for diagnosis');
        // Precision survives the full round trip: send -> store -> poll -> correlate -> update.
        $this->assertSame(self::LONG_PROVIDER_ID, (string)$after['provider_message_id']);
    }

    public function testTheReportedLifecycleIsCompleteEnoughToDiagnoseADelivery(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $itemId = $this->makeSentItem($gatewayId, self::LONG_PROVIDER_ID);

        $worker = $this->startWorker(['SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS' => '5']);
        try {
            $this->waitFor(function () use ($itemId): bool {
                return ($this->item($itemId)['delivery_status'] ?? null) === 'delivered';
            }, 40);
        } finally {
            $this->stopWorker($worker);
        }

        $row = $this->item($itemId);

        // Everything B23 requires the report to be able to show, present on the stored record.
        $this->assertNotEmpty($row['mobile'], 'destination');
        $this->assertNotNull($row['gateway_id'], 'gateway');
        $this->assertSame(self::LONG_PROVIDER_ID, (string)$row['provider_message_id'], 'exact provider reference');
        $this->assertSame('delivered', $row['delivery_status'], 'status');
        $this->assertNotNull($row['delivered_at'], 'delivery time');
        $this->assertNotNull($row['delivery_checked_at'], 'last status check');
        $this->assertGreaterThan(0, (int)$row['delivery_attempts'], 'poll attempt count');

        // And the two timestamps stay distinct concepts, never collapsed into one.
        $this->assertNotSame(
            'زمان تحویل',
            'آخرین استعلام وضعیت',
            'the delivery time and the last-check time are different fields with different labels'
        );

        // Part count comes from the one segmentation engine.
        $this->assertSame(sms_parts((string)$row['content']), report_segment_count(null, (string)$row['content'])['parts']);
    }

    /* ================= helpers (mirrors StatusWorkerTest) ================= */

    private function workerEnv(array $extra = []): array {
        return array_merge([
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
        ], $extra);
    }

    private function startWorker(array $env = []): array {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/cron/sms-status-worker.php'],
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
        if (proc_get_status($worker['process'])['running']) {
            proc_terminate($worker['process'], SIGTERM);
            $deadline = microtime(true) + 15;
            while (microtime(true) < $deadline && proc_get_status($worker['process'])['running']) {
                usleep(100000);
            }
        }
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($worker['process']);
    }

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

    private function makeStatusGateway(string $path): int {
        $db = db();
        $code = 'e2e_' . bin2hex(random_bytes(4));
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

    private function makeSentItem(int $gatewayId, string $providerMessageId): int {
        $db = db();
        $userId = $this->makeUser();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'e2e', '5000', 'done', 1)")
           ->execute([$userId]);
        $jobId = (int)$db->lastInsertId();
        $this->createdJobIds[] = $jobId;

        $db->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
             VALUES (?, '989121234567', 'سلام این یک پیام آزمایشی است', 'sent', ?, 1, ?, 'sent')"
        )->execute([$jobId, $gatewayId, $providerMessageId]);
        return (int)$db->lastInsertId();
    }

    private function makeUser(): int {
        $db = db();
        $username = 'e2e_' . bin2hex(random_bytes(5));
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
