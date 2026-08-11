<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * CLOSURE 2 — direct-send provider identity and status affinity.
 *
 * The gap this closes: `provider_message_id` was recorded only on ellsms_bulk_items, so a direct send
 * (the panel's quick send, a schedule, an auto-reply, the legacy URL API) threw away the provider's id
 * and could never have its delivery status tracked.
 *
 * The affinity claim is the sharp one: a later status lookup must ask THE GATEWAY THAT ISSUED THE ID,
 * not whichever gateway the route resolves to today. A provider message id is meaningful only to its
 * issuer, so asking a different gateway would at best return nothing and at worst match somebody
 * else's message.
 */
final class GatewayDirectSendIdentityTest extends IntegrationTestCase
{
    private static $server = null;
    private static int $port = 0;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $ownerId;
    private string $sender = '5000123456';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }

        self::$recordFile = sys_get_temp_dir() . '/ellsms_gw_direct_' . bin2hex(random_bytes(6)) . '.jsonl';
        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;

        // ONE server for both roles: the recorder answers /gw/* as a send endpoint and /status/* as a
        // delivery-status endpoint. Two processes per class is two ports and two startup waits, and
        // this suite already runs enough local servers for that to become its own failure mode.
        [self::$port, self::$server] = self::startServer('recording_gateway_server.php', $env);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
    }

    /** @return array{0:int, 1:mixed} */
    private static function startServer(string $fixture, array $env): array {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/' . $fixture],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if ($process === false) {
            self::markTestSkipped("Could not start {$fixture}.");
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return [$port, $process]; }
            usleep(50000);
        }
        self::markTestSkipped("{$fixture} did not become reachable in time.");
    }

    public static function tearDownAfterClass(): void {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
        if (self::$recordFile !== '' && is_file(self::$recordFile)) {
            @unlink(self::$recordFile);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('APP_ENV=testing');
        putenv('API_BASE_URL=' . self::$baseUrl);
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('API_BASE_URL');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= persistence ================= */

    public function testADirectSendPersistsItsProviderMessageIdAndTransportIdentity(): void {
        $gatewayId = $this->makeGateway('/status/queued');
        $routeId = $this->makeRoute($gatewayId);

        $result = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'direct');
        $this->assertTrue($result[0]);

        $row = $this->attemptFor('989121234567');
        $this->assertNotNull($row, 'a direct send through a gateway must leave a durable transport record');
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('1000', (string)$row['provider_message_id']);
        $this->assertSame($gatewayId, (int)$row['gateway_id']);
        $this->assertSame(1, (int)$row['gateway_config_version']);
        $this->assertSame($routeId, (int)$row['route_id']);
        $this->assertSame(1, (int)$row['operator_id'], 'the operator resolved for THIS destination');
        $this->assertSame('sent', $row['delivery_status'], 'acceptance is "sent", never "delivered"');
        $this->assertSame('direct_send', $row['reference_type']);
    }

    public function testEachDestinationOfAMultiRecipientDirectSendGetsItsOwnRecord(): void {
        $gatewayId = $this->makeGateway('/status/queued');
        $this->makeRoute($gatewayId);

        dispatch_message_raw($this->actor(), $this->sender, ['989121234567', '989351234567'], 'two');

        $first = $this->attemptFor('989121234567');
        $second = $this->attemptFor('989351234567');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        // A provider message id identifies one message to one recipient, so two recipients cannot
        // share a row.
        $this->assertNotSame($first['provider_message_id'], $second['provider_message_id']);
        $this->assertSame(1, (int)$first['operator_id']);
        $this->assertSame(2, (int)$second['operator_id']);
    }

    public function testARejectedDestinationGetsNoAcceptedRecord(): void {
        $gatewayId = $this->makeGateway('/status/queued');
        $this->makeRoute($gatewayId);

        // The receiver rejects any destination containing "000".
        dispatch_message_raw($this->actor(), $this->sender, ['989121234567', '989120001111'], 'partial');

        $this->assertNotNull($this->attemptFor('989121234567'));
        $this->assertNull($this->attemptFor('989120001111'), 'a destination the provider refused has nothing to track');
    }

    public function testTheLegacyTransportWritesNoTransportRecord(): void {
        putenv('SMS_GATEWAY_TRANSPORT=0');
        $gatewayId = $this->makeGateway('/status/queued');
        $this->makeRoute($gatewayId);

        $before = (int)db()->query("SELECT COUNT(*) FROM ellsms_message_attempts WHERE status = 'accepted'")->fetchColumn();
        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'legacy');
        $after = (int)db()->query("SELECT COUNT(*) FROM ellsms_message_attempts WHERE status = 'accepted'")->fetchColumn();

        $this->assertSame($before, $after, 'the legacy path must behave exactly as it always has');
    }

    public function testAReplayedProviderMessageIdDoesNotCreateASecondRecord(): void {
        $gatewayId = $this->makeGateway('/status/queued');
        $this->makeRoute($gatewayId);

        $transport = ['provider_message_id' => 'dup-1', 'gateway_id' => $gatewayId, 'gateway_config_version' => 1];
        $first  = backend_record_gateway_send(null, $this->ownerId, 'direct_send', 'ref-1', '989121234567', $transport);
        $second = backend_record_gateway_send(null, $this->ownerId, 'direct_send', 'ref-1', '989121234567', $transport);

        $this->assertTrue($first);
        $this->assertFalse($second, 'a retried worker pass must not create a second delivery record for one message');
        $count = db()->prepare("SELECT COUNT(*) FROM ellsms_message_attempts WHERE provider_message_id = 'dup-1'");
        $count->execute();
        $this->assertSame(1, (int)$count->fetchColumn());
    }

    public function testAMissingProviderMessageIdIsNeverFabricated(): void {
        $gatewayId = $this->makeGateway('/status/queued');

        $recorded = backend_record_gateway_send(null, $this->ownerId, 'direct_send', 'ref-2', '989121234567', [
            'provider_message_id' => '', 'gateway_id' => $gatewayId, 'gateway_config_version' => 1,
        ]);

        $this->assertFalse($recorded, 'a send with no provider id must leave no pollable row');
        $this->assertNull($this->attemptFor('989121234567'));
    }

    /* ================= status affinity ================= */

    public function testTheStatusPollerFindsADirectSendAndUsesItsOriginalGateway(): void {
        $originalGateway = $this->makeGateway('/status/delivered');
        $routeId = $this->makeRoute($originalGateway);

        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'affinity');
        $this->assertNotNull($this->attemptFor('989121234567'));

        // The route is re-pointed at a DIFFERENT gateway whose status API would answer 'failed'. The
        // already-sent message must still be asked of the gateway that issued its id.
        $otherGateway = $this->makeGateway('/status/failed');
        db()->prepare('UPDATE ellsms_sms_routes SET gateway_id = ? WHERE id = ?')->execute([$otherGateway, $routeId]);
        sms_pricing_cache_reset();
        gateway_cache_reset();

        $stats = gateway_status_poll_pass();

        $this->assertGreaterThanOrEqual(1, $stats['polled']);
        $row = $this->attemptFor('989121234567');
        $this->assertSame('delivered', $row['delivery_status'],
            'the poll must have used the ORIGINAL gateway, whose API reports delivered — not the route\'s current one');
        $this->assertSame($originalGateway, (int)$row['gateway_id']);
        $this->assertNotNull($row['delivered_at']);
    }

    public function testADirectSendStatusIsMonotonicJustLikeABulkOne(): void {
        $gatewayId = $this->makeGateway('/status/delivered');
        $this->makeRoute($gatewayId);
        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'monotonic');
        gateway_status_poll_pass();

        db()->prepare('UPDATE ellsms_sms_gateway_status_connectors SET endpoint_url = ? WHERE gateway_id = ?')
            ->execute([self::$baseUrl . '/status/sent', $gatewayId]);
        db()->prepare('UPDATE ellsms_message_attempts SET delivery_checked_at = NULL WHERE gateway_id = ?')->execute([$gatewayId]);
        gateway_cache_reset();
        gateway_status_poll_pass();

        $this->assertSame('delivered', $this->attemptFor('989121234567')['delivery_status'],
            'a re-report must never undo a delivery, whichever table the row lives in');
    }

    public function testBulkAndDirectRowsArePolledByTheSamePass(): void {
        $gatewayId = $this->makeGateway('/status/delivered');
        $this->makeRoute($gatewayId);
        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'direct half');
        $bulkItemId = $this->makeBulkItem($gatewayId, 'bulk-pmid-1');

        $stats = gateway_status_poll_pass();

        $this->assertGreaterThanOrEqual(2, $stats['polled'], 'one pass must cover both sources');
        $this->assertSame('delivered', $this->attemptFor('989121234567')['delivery_status']);
        $bulk = db()->prepare('SELECT delivery_status FROM ellsms_bulk_items WHERE id = ?');
        $bulk->execute([$bulkItemId]);
        $this->assertSame('delivered', $bulk->fetchColumn(), 'the bulk path must keep working unchanged');
    }

    /* ================= helpers ================= */

    private function actor(): array {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null, 'originator' => $this->sender];
    }

    private function attemptFor(string $destination): ?array {
        $st = db()->prepare("SELECT * FROM ellsms_message_attempts WHERE destination = ? AND status = 'accepted' ORDER BY id DESC LIMIT 1");
        $st->execute([$destination]);
        return $st->fetch() ?: null;
    }

    private function makeGateway(string $statusPath): int {
        $db = db();
        $code = 'dir_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,1,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors
               (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
             VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)"
        )->execute([
            $gatewayId, self::$baseUrl . '/gw/' . $code,
            json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
            json_encode(['rows_path' => '', 'destination_key' => 'destination', 'status_key' => 'status',
                         'success_values' => ['sent'], 'message_id_key' => 'id']),
        ]);

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_status_connectors
               (gateway_id, endpoint_url, http_method, response_mapping_json, status_mapping_json,
                poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
             VALUES (?,?, 'GET', ?, ?, 0, 6, 86400)"
        )->execute([
            $gatewayId, self::$baseUrl . $statusPath,
            json_encode(['provider_status' => 'status', 'delivered_at' => 'delivered_at']),
            json_encode(['DELIVRD' => 'delivered', 'UNDELIV' => 'failed', 'ENROUTE' => 'queued', 'ACCEPTD' => 'sent']),
        ]);

        foreach ([
            ['destinations', 'recipients', 'string_list', 30],
            ['content',      'message',    'string',      40],
        ] as [$key, $value, $dataType, $sortOrder]) {
            $db->prepare(
                "INSERT INTO ellsms_sms_gateway_parameters
                   (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                 VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)"
            )->execute([$gatewayId, $key, $value, $dataType, $sortOrder, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        // The status request carries the provider's own id — the only thing it can be asked about.
        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, param_key, value_type, value, data_type, status, active_slot)
             VALUES (?, 'status', 'query', 'gateway', 'id', 'variable', 'provider_message_id', 'string', 'active', ?)"
        )->execute([$gatewayId, "{$gatewayId}:status:query:gateway::id"]);

        gateway_cache_reset();
        return $gatewayId;
    }

    private function makeRoute(int $gatewayId): int {
        $suffix = bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'prov', 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
            ->execute([$providerId, 'route_' . $suffix, 'route', 'default', 'active', $gatewayId]);
        $routeId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
        return $routeId;
    }

    private function makeBulkItem(int $gatewayId, string $providerMessageId): int {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (?, 'p2p', 'both sources', ?, 'done', 1)")
           ->execute([$this->ownerId, $this->sender]);
        $jobId = (int)$db->lastInsertId();
        $db->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, gateway_config_version, provider_message_id, delivery_status)
             VALUES (?, '989351234567', 'x', 'sent', ?, 1, ?, 'sent')"
        )->execute([$jobId, $gatewayId, $providerMessageId]);
        return (int)$db->lastInsertId();
    }
}
