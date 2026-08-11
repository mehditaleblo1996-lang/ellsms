<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * STEP 50–57 — delivery-status polling: canonical mapping, monotonic transitions, poll limits, and
 * atomic claiming.
 *
 * The mapping and transition rules are exercised directly because they are pure decisions, and the
 * polling pass is exercised against a real HTTP fixture because "did the worker actually ask, and did
 * it write the right thing" is not provable from unit-level reasoning.
 */
final class GatewayStatusPollTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }

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
        parent::setUp();
        putenv('APP_ENV=testing');
        gateway_cache_reset();
    }

    protected function tearDown(): void {
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= pure decisions ================= */

    public function testAnUnmappedProviderStatusBecomesUnknownAndNeverDelivered(): void {
        $map = gateway_status_mapping_compile(['DELIVRD' => 'delivered', 'UNDELIV' => 'failed']);

        $this->assertSame('delivered', gateway_status_map($map, 'DELIVRD'));
        $this->assertSame('delivered', gateway_status_map($map, 'delivrd'), 'provider casing must not matter');
        $this->assertSame('unknown', gateway_status_map($map, 'SOMETHING_NEW'));
        $this->assertSame('unknown', gateway_status_map($map, null));
        $this->assertSame('unknown', gateway_status_map($map, ''));
    }

    public function testATerminalStateIsNeverDowngraded(): void {
        $this->assertFalse(gateway_status_may_transition('delivered', 'sent'), 'a late poll must not undo a delivery');
        $this->assertFalse(gateway_status_may_transition('failed', 'queued'));
        $this->assertFalse(gateway_status_may_transition('delivered', 'unknown'));
        $this->assertTrue(gateway_status_may_transition('sent', 'delivered'));
        $this->assertTrue(gateway_status_may_transition(null, 'queued'));
        $this->assertTrue(gateway_status_may_transition('unknown', 'sent'));
        $this->assertFalse(gateway_status_may_transition('sent', 'unknown'), 'a non-answer must not overwrite a known state');
    }

    /* ================= the polling pass ================= */

    public function testAPollingPassMapsTheProviderStatusOntoTheRow(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $itemId = $this->makeSentItem($gatewayId, 'pmid-1');

        $stats = gateway_status_poll_pass();

        $this->assertGreaterThanOrEqual(1, $stats['polled']);
        $row = $this->item($itemId);
        $this->assertSame('delivered', $row['delivery_status']);
        $this->assertNotNull($row['delivered_at'], 'a delivered row must carry a delivery time');
        $this->assertSame(1, (int)$row['delivery_attempts']);
    }

    public function testASecondPassDoesNotDowngradeATerminalRow(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        $itemId = $this->makeSentItem($gatewayId, 'pmid-2');
        gateway_status_poll_pass();

        // The provider now re-reports an EARLIER state, which real providers do.
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET endpoint_url = ? WHERE gateway_id = ?')
            ->execute([self::$baseUrl . '/status/sent', $gatewayId]);
        db()->prepare('UPDATE ellsms_bulk_items SET delivery_checked_at = NULL WHERE id = ?')->execute([$itemId]);
        gateway_cache_reset();
        gateway_status_poll_pass();

        $this->assertSame('delivered', $this->item($itemId)['delivery_status'], 'a re-report must never undo a delivery');
    }

    public function testAFailedPollLeavesTheDeliveryStateUntouched(): void {
        $gatewayId = $this->makeStatusGateway('/status/error');
        $itemId = $this->makeSentItem($gatewayId, 'pmid-3', 'sent');

        gateway_status_poll_pass();

        $row = $this->item($itemId);
        // "We could not ask" is not "not delivered".
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame(1, (int)$row['delivery_attempts'], 'a failed poll still costs an attempt, so it cannot loop forever');
    }

    public function testARowIsNotPolledPastItsAttemptLimit(): void {
        $gatewayId = $this->makeStatusGateway('/status/queued');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET poll_max_attempts = 2, poll_initial_delay_seconds = 0 WHERE gateway_id = ?')
            ->execute([$gatewayId]);
        gateway_cache_reset();
        $itemId = $this->makeSentItem($gatewayId, 'pmid-4');
        db()->prepare('UPDATE ellsms_bulk_items SET delivery_attempts = 2 WHERE id = ?')->execute([$itemId]);

        $stats = gateway_status_poll_pass();

        $this->assertSame(0, $stats['polled'], 'a message out of attempts must not be asked about again');
        $this->assertSame(2, (int)$this->item($itemId)['delivery_attempts']);
    }

    public function testARowOlderThanTheMaximumAgeIsAbandonedRatherThanPolledForever(): void {
        $gatewayId = $this->makeStatusGateway('/status/queued');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET poll_max_age_seconds = 60, poll_initial_delay_seconds = 0 WHERE gateway_id = ?')
            ->execute([$gatewayId]);
        gateway_cache_reset();
        $itemId = $this->makeSentItem($gatewayId, 'pmid-5');
        db()->prepare('UPDATE ellsms_bulk_items SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = ?')->execute([$itemId]);

        $stats = gateway_status_poll_pass();

        $this->assertSame(0, $stats['polled']);
    }

    public function testAClaimIsAtomicSoTwoPassesCannotPollTheSameRow(): void {
        $gatewayId = $this->makeStatusGateway('/status/queued');
        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET poll_initial_delay_seconds = 3600 WHERE gateway_id = ?')
            ->execute([$gatewayId]);
        gateway_cache_reset();
        $connector = gateway_compiled($gatewayId);
        $itemId = $this->makeSentItem($gatewayId, 'pmid-6');

        $first  = gateway_status_claim('bulk_item', $itemId, $connector['status']);
        $second = gateway_status_claim('bulk_item', $itemId, $connector['status']);

        $this->assertTrue($first);
        $this->assertFalse($second, 'the loser of the race must claim nothing, not poll a second time');
    }

    public function testAGatewayWithoutAStatusConnectorIsSkippedRatherThanFailing(): void {
        $gatewayId = $this->makeStatusGateway('/status/delivered');
        db()->prepare('DELETE FROM ellsms_sms_gateway_status_connectors WHERE gateway_id = ?')->execute([$gatewayId]);
        db()->prepare('UPDATE ellsms_sms_gateways SET status_enabled = 0 WHERE id = ?')->execute([$gatewayId]);
        gateway_cache_reset();
        $this->makeSentItem($gatewayId, 'pmid-7');

        $stats = gateway_status_poll_pass();

        $this->assertSame(0, $stats['polled']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped'], 'most gateways genuinely have no delivery API — that is not an error');
    }

    /* ================= fixtures ================= */

    private function makeStatusGateway(string $path): int {
        $db = db();
        $code = 'st_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,1,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();

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
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'status test', '5000', 'done', 1)")
           ->execute([$userId]);
        $jobId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
             VALUES (?, '989121234567', 'x', 'sent', ?, 1, ?, ?)"
        )->execute([$jobId, $gatewayId, $providerMessageId, $deliveryStatus]);
        return (int)$db->lastInsertId();
    }

    private function item(int $itemId): array {
        $st = db()->prepare('SELECT * FROM ellsms_bulk_items WHERE id = ?');
        $st->execute([$itemId]);
        return $st->fetch() ?: [];
    }
}
