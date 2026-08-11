<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * STEP 48/49 — the send path actually routing through a configured gateway, and the many-gateway,
 * per-operator and per-route override behaviour that the connector builder exists to provide.
 *
 * Everything here goes through dispatch_message_raw(), not through gateway_send() directly: the claim
 * under test is that the REAL send path reaches the configured gateway, and a test that called the
 * transport itself would prove only that the transport works when someone calls it.
 */
final class GatewayDispatchTest extends IntegrationTestCase
{
    private static $serverProcess = null;
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

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_gateway_dispatch_' . bin2hex(random_bytes(6)) . '.jsonl';

        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (self::$serverProcess === false) {
            self::markTestSkipped('Could not start the recording receiver.');
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('Recording receiver did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
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
        gateway_counters_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('API_BASE_URL');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= fixtures ================= */

    private function actor(): array {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null, 'originator' => $this->sender];
    }

    /** @return list<array> the recorded requests, in arrival order */
    private function recordings(): array {
        $records = [];
        foreach (array_filter(array_map('trim', explode("\n", (string)@file_get_contents(self::$recordFile)))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) { $records[] = $decoded; }
        }
        return $records;
    }

    private function makeGateway(string $code, string $path = '/api/messages/send', bool $isDefault = false): int {
        $db = db();
        $db->prepare(
            "INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, default_slot, config_version)
             VALUES (?,?,'active','batch',1,0,?,?,1)"
        )->execute([$code, $code, $isDefault ? 1 : 0, $isDefault ? 1 : null]);
        $gatewayId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors
               (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms,
                tls_verify, auth_type, success_rule_json, batch_mapping_json)
             VALUES (?,?,'POST','application/json',5000,30000,1,'none',?,?)"
        )->execute([
            $gatewayId, self::$baseUrl . $path,
            json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
            json_encode(['rows_path' => '', 'destination_key' => 'destination', 'status_key' => 'status',
                         'success_values' => ['sent'], 'message_id_key' => 'id']),
        ]);

        foreach ([
            ['sender_user_id', 'sender_user_id', 'integer',     10],
            ['originator',     'sender',         'numeric',     20],
            ['destinations',   'recipients',     'string_list', 30],
            ['content',        'message',        'string',      40],
        ] as [$key, $value, $dataType, $sortOrder]) {
            $this->putParameter($gatewayId, 'gateway', null, $key, 'variable', $value, $dataType, $sortOrder);
        }
        gateway_cache_reset();
        return $gatewayId;
    }

    private function putParameter(int $gatewayId, string $scope, ?int $scopeId, string $key, string $valueType, string $value, string $dataType = 'string', int $sortOrder = 100, string $location = 'body'): void {
        db()->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
             VALUES (?, 'send', ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        )->execute([
            $gatewayId, $location, $scope, $scopeId, $key, $valueType, $value, $dataType, $sortOrder,
            "{$gatewayId}:send:{$location}:{$scope}:" . ($scopeId ?? '') . ":{$key}",
        ]);
        gateway_cache_reset();
    }

    /**
     * A route reachable only through this test's own sender assignment — never the global default,
     * which the seeded catalog already owns (and whose uniqueness the schema enforces).
     */
    private function makeRoute(int $gatewayId = 0, bool $isDefault = false): int {
        $suffix = bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'prov', 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$providerId, 'route_' . $suffix, 'route', 'default', 'active', $isDefault ? 1 : 0, $isDefault ? 'default' : null, $gatewayId ?: null]);
        $routeId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
        return $routeId;
    }

    /**
     * The operator the ENGINE resolves for a number, rather than a freshly created one.
     *
     * The shipped catalog already claims 0912/0935/0922, and the prefix table enforces one active
     * owner per prefix — so a test that invented its own operator for 0912 would be testing a
     * catalog that cannot exist. Asking the resolver is also what the send path itself does.
     */
    private function operatorFor(string $msisdn): int {
        $operator = sms_resolve_operator($msisdn);
        $this->assertNotNull($operator['operator_id'], "the seeded catalog must recognise {$msisdn}");
        return (int)$operator['operator_id'];
    }

    /* ================= tests ================= */

    public function testSendGoesThroughTheConfiguredGatewayWhenTheTransportIsEnabled(): void {
        $gatewayId = $this->makeGateway('gw_primary', '/gw/primary');
        $this->makeRoute($gatewayId);

        [$ok, , $sentCount] = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'through the gateway');

        $this->assertTrue($ok);
        $this->assertSame(1, $sentCount);
        $records = $this->recordings();
        $this->assertCount(1, $records);
        $this->assertSame('/gw/primary', $records[0]['path'], 'the send must have reached the ROUTE\'s gateway, not the legacy endpoint');
    }

    public function testTransportDisabledKeepsUsingTheLegacyEndpoint(): void {
        putenv('SMS_GATEWAY_TRANSPORT=0');
        $gatewayId = $this->makeGateway('gw_unused', '/gw/unused');
        $this->makeRoute($gatewayId);

        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'legacy please');

        $records = $this->recordings();
        $this->assertCount(1, $records);
        $this->assertSame('/api/messages/send', $records[0]['path'], 'with the flag off, nothing may change about the live path');
    }

    public function testARouteWithNoGatewayFallsBackToLegacyRatherThanFailing(): void {
        // Mid-rollout: the transport is on, but this route has not been pointed at a gateway yet.
        // Refusing the send would turn incomplete configuration into an outage.
        $this->makeRoute(0);

        [$ok] = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'no gateway yet');

        $this->assertTrue($ok);
        $records = $this->recordings();
        $this->assertSame('/api/messages/send', $records[0]['path']);
    }

    public function testTwoRoutesReachTheirOwnGatewaysInTheSameProcess(): void {
        $first  = $this->makeGateway('gw_a', '/gw/a');
        $second = $this->makeGateway('gw_b', '/gw/b');

        $routeId = $this->makeRoute($first);
        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'first');

        db()->prepare('UPDATE ellsms_sms_routes SET gateway_id = ? WHERE id = ?')->execute([$second, $routeId]);
        sms_pricing_cache_reset();
        dispatch_message_raw($this->actor(), $this->sender, ['989351234567'], 'second');

        $records = $this->recordings();
        $this->assertCount(2, $records);
        $this->assertSame('/gw/a', $records[0]['path']);
        $this->assertSame('/gw/b', $records[1]['path'], 'each route must reach its OWN gateway — no cross-contamination through the shared cache');
    }

    public function testOperatorOverrideBeatsRouteOverrideBeatsGatewayDefault(): void {
        $gatewayId = $this->makeGateway('gw_scoped', '/gw/scoped');
        $routeId = $this->makeRoute($gatewayId);
        $operatorId = $this->operatorFor('989121234567');

        $this->putParameter($gatewayId, 'gateway', null, 'channel', 'static', 'gateway-default');
        $this->putParameter($gatewayId, 'route', $routeId, 'channel', 'static', 'route-override');
        $this->putParameter($gatewayId, 'operator', $operatorId, 'channel', 'static', 'operator-override');

        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'scoped');

        $body = json_decode($this->recordings()[0]['body'], true);
        $this->assertSame('operator-override', $body['channel'], 'operator scope is the final word (gateway < route < operator)');
    }

    public function testRouteOverrideAppliesWhenTheOperatorHasNoOverride(): void {
        $gatewayId = $this->makeGateway('gw_route_scope', '/gw/route-scope');
        $routeId = $this->makeRoute($gatewayId);
        $this->putParameter($gatewayId, 'gateway', null, 'channel', 'static', 'gateway-default');
        $this->putParameter($gatewayId, 'route', $routeId, 'channel', 'static', 'route-override');

        dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'scoped');

        $body = json_decode($this->recordings()[0]['body'], true);
        $this->assertSame('route-override', $body['channel']);
    }

    public function testAnUnassignedOperatorIsRefusedRatherThanSentOnTheWrongGateway(): void {
        $gatewayId = $this->makeGateway('gw_restricted', '/gw/restricted');
        $this->makeRoute($gatewayId);
        $carried = $this->operatorFor('989351234567');
        // The gateway carries ONLY the 0935 operator.
        db()->prepare("INSERT INTO ellsms_sms_gateway_operators (gateway_id, operator_id, status) VALUES (?,?, 'active')")
            ->execute([$gatewayId, $carried]);
        gateway_cache_reset();

        [$okCarried] = dispatch_message_raw($this->actor(), $this->sender, ['989351234567'], 'carried');
        [$okOther, , , , , $retryable] = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'not carried');

        $this->assertTrue($okCarried);
        $this->assertFalse($okOther, 'an operator this gateway does not carry must not be sent through it');
        $this->assertFalse($retryable, 'refusing an unassigned operator is permanent — retrying cannot help');
        $this->assertCount(1, $this->recordings(), 'the refused send must never have touched the network');
    }

    public function testGatewayIdAndConfigVersionAreReportedWithTheSend(): void {
        $gatewayId = $this->makeGateway('gw_meta', '/gw/meta');
        $this->makeRoute($gatewayId);

        $result = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'meta');
        $meta = $result[7] ?? null;

        $this->assertIsArray($meta, 'the send must report which gateway and which config version produced it');
        $this->assertSame($gatewayId, $meta['gateway_id']);
        $this->assertSame(1, $meta['gateway_config_version']);
        $this->assertSame('1000', (string)$meta['provider_message_ids']['989121234567']);
    }

    public function testAnUnreachableGatewayIsRetryableAndRecordsALocalAttempt(): void {
        $gatewayId = $this->makeGateway('gw_dead', '/gw/dead');
        // Port 1 is closed on every sane host: a real connection failure, not a simulated one.
        db()->prepare('UPDATE ellsms_sms_gateway_send_connectors SET endpoint_url = ? WHERE gateway_id = ?')
            ->execute(['http://127.0.0.1:1/send', $gatewayId]);
        $this->makeRoute($gatewayId);
        gateway_cache_reset();

        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_message_attempts')->fetchColumn();
        [$ok, , , , , $retryable] = dispatch_message_raw($this->actor(), $this->sender, ['989121234567'], 'dead');
        $after = (int)db()->query('SELECT COUNT(*) FROM ellsms_message_attempts')->fetchColumn();

        $this->assertFalse($ok);
        $this->assertTrue($retryable, 'a transport failure must stay retryable, exactly as on the legacy path');
        $this->assertSame($before + 1, $after, 'the attempt must be recorded in ELLSMS\'s own table');
    }

    public function testPartialSuccessReportsExactlyTheAcceptedDestinations(): void {
        $gatewayId = $this->makeGateway('gw_partial', '/gw/partial');
        $this->makeRoute($gatewayId);

        // The receiver rejects any destination containing "000".
        $result = dispatch_message_raw($this->actor(), $this->sender, ['989121234567', '989120001111'], 'partial');

        $this->assertTrue($result[0]);
        $this->assertSame(1, $result[2]);
        $this->assertSame(2, $result[3]);
        $this->assertSame(['989121234567'], $result[6], 'WHICH destinations sent drives settlement, not merely how many');
    }
}
