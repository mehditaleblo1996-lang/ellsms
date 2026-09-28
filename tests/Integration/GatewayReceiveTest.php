<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #37 — inbound SMS through a gateway receive connector, against a Vesal-shaped
 * /pullReceivedMessages (tests/fixtures/recording_gateway_server.php): polled per line, stored once
 * (no provider id, so de-duplicated by fingerprint), read by the inbox alongside the backend's
 * inbound_message, and handed to the auto-responder with its own cursor.
 */
final class GatewayReceiveTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900300';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_receive_' . bin2hex(random_bytes(6)) . '.jsonl';
        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Recording receiver did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        @unlink(self::$recordFile);
        @unlink(self::$recordFile . '.mo');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_TRANSPORT=1');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.mo', '[]');
        // Other gateways left enabled by committed fixtures must not be polled by this class's passes.
        db()->exec('UPDATE ellsms_sms_gateway_receive_connectors SET enabled = 0');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    private function makeGateway(string $receivePath = '/vesal/pullReceivedMessages', bool $routeLine = true): int
    {
        $db = db();
        $code = 'recv_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none')")->execute([$gatewayId, self::$baseUrl . '/vesal/ManyToMany']);
        $db->prepare("INSERT INTO ellsms_sms_gateway_receive_connectors
                        (gateway_id, enabled, endpoint_url, http_method, content_type, success_rule_json, per_line, poll_interval_seconds, lookback_seconds)
                      VALUES (?, 1, ?, 'POST', 'application/json', ?, 1, 30, 3600)")
           ->execute([$gatewayId, self::$baseUrl . $receivePath,
                      json_encode(['rules' => [['path' => 'errorModel.errorCode', 'operator' => 'equals', 'values' => [0]]]])]);
        foreach ([
            ['destination', 'variable', 'line', 'string'],
            ['fromDate', 'variable', 'from_date', 'string'],
            ['toDate', 'variable', 'to_date', 'string'],
            ['allStatus', 'static', 'true', 'boolean'],
        ] as $i => [$key, $valueType, $value, $dataType]) {
            $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
                            (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                          VALUES (?, 'receive', 'body', 'gateway', NULL, ?, ?, ?, ?, 'active', ?, ?)")
               ->execute([$gatewayId, $key, $valueType, $value, $dataType, $i, "{$gatewayId}:receive:body:gateway::{$key}"]);
        }
        if ($routeLine) {
            $suffix = bin2hex(random_bytes(3));
            $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
            $providerId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
               ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
            $routeId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
               ->execute([$this->line, 'default', $routeId, 'active', $this->line . ':default']);
        }
        sms_pricing_cache_reset();
        gateway_cache_reset();
        return $gatewayId;
    }

    private function providerHas(array $messages): void
    {
        file_put_contents(self::$recordFile . '.mo', json_encode($messages, JSON_UNESCAPED_UNICODE));
    }

    private function stored(int $gatewayId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_inbound_messages WHERE gateway_id = ? ORDER BY id');
        $st->execute([$gatewayId]);
        return $st->fetchAll();
    }

    private function allowNextPoll(int $gatewayId): void
    {
        db()->prepare('UPDATE ellsms_sms_gateway_receive_connectors SET last_polled_at = NULL WHERE gateway_id = ?')->execute([$gatewayId]);
    }

    public function testMessagesArePulledPerLineAndStoredWithTehranTime(): void
    {
        $gatewayId = $this->makeGateway();
        // 2026-09-28 10:00:00 UTC = 13:30 in Tehran.
        $this->providerHas([
            ['originator' => '09121110001', 'destination' => $this->line, 'content' => 'سلام', 'insertDate' => 1790589600000],
            ['originator' => '09121110002', 'destination' => $this->line, 'content' => '11', 'insertDate' => 1790589660000],
            ['originator' => '09121110003', 'destination' => '5000999999', 'content' => 'other line', 'insertDate' => 1790589600000],
        ]);

        $stats = gateway_receive_poll_pass();

        $this->assertSame(1, $stats['gateways']);
        $rows = $this->stored($gatewayId);
        $this->assertCount(2, $rows, 'only the routed line is asked for, and both of its messages are stored');
        $this->assertSame('989121110001', $rows[0]['originator']);
        $this->assertSame($this->line, $rows[0]['destination']);
        $this->assertSame('سلام', $rows[0]['content']);
        $this->assertSame('2026-09-28 13:30:00', $rows[0]['received_at']);
        $this->assertGreaterThanOrEqual(1000000000000000, (int)$rows[0]['id'], 'ids live above every backend inbound id');

        $request = json_decode((string)json_decode((string)file_get_contents(self::$recordFile), true)['body'], true);
        $this->assertSame($this->line, $request['destination']);
        $this->assertTrue($request['allStatus']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $request['fromDate'], "Vesal's yyyy-MM-dd'T'HH:mm:ss");
    }

    public function testRepollingTheLookbackWindowNeverDuplicates(): void
    {
        $gatewayId = $this->makeGateway();
        $this->providerHas([['originator' => '09121110001', 'destination' => $this->line, 'content' => 'x', 'insertDate' => 1790589600000]]);
        gateway_receive_poll_pass();
        $this->allowNextPoll($gatewayId);
        gateway_receive_poll_pass();
        $this->assertCount(1, $this->stored($gatewayId));
    }

    public function testAGatewayIsPolledAtMostOncePerInterval(): void
    {
        $gatewayId = $this->makeGateway();
        $this->assertSame(1, gateway_receive_poll_pass()['gateways']);
        $this->assertSame(0, gateway_receive_poll_pass()['gateways'], 'the interval has not elapsed');
    }

    public function testAFailedPollRecordsTheErrorAndStoresNothing(): void
    {
        $gatewayId = $this->makeGateway('/vesal/pull-error');
        $stats = gateway_receive_poll_pass();
        $this->assertSame(1, $stats['errors']);
        $this->assertSame([], $this->stored($gatewayId));
        $st = db()->prepare('SELECT last_error, last_success_at FROM ellsms_sms_gateway_receive_connectors WHERE gateway_id = ?');
        $st->execute([$gatewayId]);
        $row = $st->fetch();
        $this->assertNotEmpty($row['last_error']);
        $this->assertNull($row['last_success_at']);
    }

    public function testALineRoutedElsewhereIsNotAskedFor(): void
    {
        $gatewayId = $this->makeGateway('/vesal/pullReceivedMessages', false);
        $stats = gateway_receive_poll_pass();
        $this->assertSame(0, $stats['requests']);
    }

    public function testTheInboxReadsBothStoresNewestFirstAndPagesAcrossThem(): void
    {
        $gatewayId = $this->makeGateway();
        $line = '5000900399';
        db()->prepare('INSERT INTO inbound_message (originator, destination, content, received_at) VALUES (?,?,?,?)')
            ->execute(['989120000001', $line, 'old backend', '2026-09-27 10:00:00']);
        $this->providerHas([]);
        $ins = db()->prepare("INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key) VALUES (?,?,?,?,?,?)");
        $ins->execute([$gatewayId, '989120000002', $line, 'gateway 1', '2026-09-28 10:00:00', hash('sha256', 'a' . random_bytes(4))]);
        $ins->execute([$gatewayId, '989120000003', $line, 'gateway 2', '2026-09-28 11:00:00', hash('sha256', 'b' . random_bytes(4))]);

        $where = 'destination = ?';
        $this->assertSame(3, backend_inbound_count($where, [$line]));
        $page1 = backend_inbound_rows($where, [$line], 2, 0);
        $page2 = backend_inbound_rows($where, [$line], 2, 2);
        $this->assertSame(['gateway 2', 'gateway 1'], array_column($page1, 'content'));
        $this->assertSame(['old backend'], array_column($page2, 'content'));
        $this->assertSame(['gateway 2', 'gateway 1', 'old backend'], array_column(iterator_to_array(backend_inbound_export_rows($where, [$line], 10), false), 'content'));
    }

    public function testTheAutoResponderSeesGatewayInboundWithItsOwnCursor(): void
    {
        $gatewayId = $this->makeGateway();
        $userId = $this->makeUser();
        wallet_credit($userId, 1000, 'purchase', 'test', 'seed:' . $userId, 'seed:' . $userId);
        db()->prepare("INSERT INTO ellsms_autoreply_rules (user_id, originator, keyword, match_type, reply_content, is_active)
                       VALUES (?, ?, 'hi', 'exact', 'auto reply', 1)")->execute([$userId, $this->line]);
        set_setting('autoreply_last_gateway_inbound_id', '0');
        set_setting('autoreply_last_inbound_id', (string)(int)db()->query('SELECT COALESCE(MAX(id),0) FROM inbound_message')->fetchColumn());
        db()->prepare("INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key) VALUES (?,?,?,?,NOW(),?)")
            ->execute([$gatewayId, '989121110009', $this->line, 'hi', hash('sha256', random_bytes(8))]);
        $inboundId = (int)db()->lastInsertId();

        run_autoreply_pass();

        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_autoreply_log WHERE inbound_message_id = ?');
        $st->execute([$inboundId]);
        $this->assertSame(1, (int)$st->fetchColumn(), 'the rule matched a message that arrived through the gateway');
        $this->assertSame((string)$inboundId, setting_fresh('autoreply_last_gateway_inbound_id'));
    }

    public function testTheAutoReplyCursorAdvancesWithinOneLongRunningProcess(): void
    {
        // Regression: the cursor was read through setting()'s process-lifetime cache, so a worker
        // kept rescanning from its start-up value — past 100 new messages it never moved on.
        $start = (int)db()->query('SELECT COALESCE(MAX(id),0) FROM inbound_message')->fetchColumn();
        set_setting('autoreply_last_inbound_id', (string)$start);
        setting('autoreply_last_inbound_id'); // warm setting()'s static cache the way a worker would
        $ins = db()->prepare("INSERT INTO inbound_message (originator, destination, content) VALUES ('989120000009', '5000900398', 'x')");
        for ($i = 0; $i < 150; $i++) {
            $ins->execute();
        }
        $last = (int)db()->lastInsertId();

        run_autoreply_pass();
        run_autoreply_pass();

        $this->assertSame((string)$last, setting_fresh('autoreply_last_inbound_id'), 'two passes must cover all 150 rows');
    }
}
