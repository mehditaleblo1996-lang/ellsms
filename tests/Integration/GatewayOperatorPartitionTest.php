<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * CLOSURE 1 — per-recipient operator overrides.
 *
 * The defect this closes: batch mode resolved ONE operator (the first destination's) and stamped its
 * overrides on the whole batch, so a mixed MCI/MTN/Rightel send went out with one operator's
 * parameters on all of it.
 *
 * Every assertion here is made against the bytes that actually crossed a socket
 * (tests/fixtures/recording_gateway_server.php). Asserting on a built structure would prove that two
 * functions in this repo agree with each other, which is precisely the class of test that let the
 * original defect through.
 */
final class GatewayOperatorPartitionTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $ownerId;
    private string $sender = '5000123456';

    private const MCI     = '989121234567';
    private const MTN     = '989351234567';
    private const RIGHTEL = '989211234567';

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_gw_partition_' . bin2hex(random_bytes(6)) . '.jsonl';

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

    /* ================= TEST 1 — three operators, three overrides ================= */

    public function testEachOperatorReceivesItsOwnOperatorCodeParameter(): void {
        $gatewayId = $this->makeGateway('gw_ops');
        $this->makeRoute($gatewayId);
        $this->putParameter($gatewayId, 'gateway', null, 'type', 'static', 'sms');
        $this->operatorParameter($gatewayId, 'mci',     'operatorCode', '1');
        $this->operatorParameter($gatewayId, 'mtn',     'operatorCode', '2');
        $this->operatorParameter($gatewayId, 'rightel', 'operatorCode', '3');

        dispatch_message_raw($this->actor(), $this->sender, [self::MCI, self::MTN, self::RIGHTEL], 'mixed');

        $byDestination = $this->operatorCodeByDestination();
        $this->assertSame('1', $byDestination[self::MCI],     'MCI must carry operatorCode=1');
        $this->assertSame('2', $byDestination[self::MTN],     'MTN must carry operatorCode=2');
        $this->assertSame('3', $byDestination[self::RIGHTEL], 'Rightel must carry operatorCode=3');

        // The gateway-scope default must still reach every request; an operator override replaces one
        // key, not the whole parameter set.
        foreach ($this->recordings() as $record) {
            $this->assertSame('sms', json_decode($record['body'], true)['type']);
        }
    }

    /* ================= TEST 2 — no leakage at scale ================= */

    public function testAThousandMixedRecipientsHaveZeroCrossOperatorLeakage(): void {
        $gatewayId = $this->makeGateway('gw_scale');
        $this->makeRoute($gatewayId);
        $this->operatorParameter($gatewayId, 'mci',     'operatorCode', '1');
        $this->operatorParameter($gatewayId, 'mtn',     'operatorCode', '2');
        $this->operatorParameter($gatewayId, 'rightel', 'operatorCode', '3');

        $expected = [];
        $destinations = [];
        foreach ([['0912', '1'], ['0935', '2'], ['0921', '3']] as [$prefix, $code]) {
            for ($i = 0; $i < 333; $i++) {
                $number = '98' . substr($prefix, 1) . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
                $destinations[] = $number;
                $expected[$number] = $code;
            }
        }
        $destinations[] = self::MCI;
        $expected[self::MCI] = '1';
        shuffle($destinations);
        $this->assertCount(1000, $destinations);

        dispatch_message_raw($this->actor(), $this->sender, $destinations, 'scale');

        $byDestination = $this->operatorCodeByDestination();
        $this->assertCount(1000, $byDestination, 'every recipient must be accounted for');
        foreach ($expected as $number => $code) {
            $this->assertSame($code, $byDestination[$number] ?? null, "leaked operator override for {$number}");
        }
        // Three distinct configurations -> three provider requests, not one thousand.
        $this->assertSame(3, count($this->recordings()));
    }

    /* ================= TEST 3 — order independence ================= */

    public function testTheResultDoesNotDependOnWhichRecipientComesFirst(): void {
        $gatewayId = $this->makeGateway('gw_order');
        $this->makeRoute($gatewayId);
        $this->operatorParameter($gatewayId, 'mci',     'operatorCode', '1');
        $this->operatorParameter($gatewayId, 'mtn',     'operatorCode', '2');
        $this->operatorParameter($gatewayId, 'rightel', 'operatorCode', '3');

        $orderings = [
            [self::MTN, self::MCI, self::RIGHTEL, self::MCI, self::MTN],
            [self::RIGHTEL, self::MTN, self::MCI],
            [self::MCI, self::MCI, self::MTN],
        ];

        foreach ($orderings as $index => $ordering) {
            @file_put_contents(self::$recordFile, '');
            dispatch_message_raw($this->actor(), $this->sender, $ordering, 'ordering');

            $byDestination = $this->operatorCodeByDestination();
            $this->assertSame('1', $byDestination[self::MCI] ?? null, "ordering #{$index}");
            if (in_array(self::MTN, $ordering, true)) {
                $this->assertSame('2', $byDestination[self::MTN] ?? null, "ordering #{$index}");
            }
            if (in_array(self::RIGHTEL, $ordering, true)) {
                $this->assertSame('3', $byDestination[self::RIGHTEL] ?? null, "ordering #{$index}");
            }
        }
    }

    /* ================= TEST 4 — route overrides do not leak ================= */

    public function testRouteOverridesDoNotLeakBetweenRoutes(): void {
        $gatewayId = $this->makeGateway('gw_routes');
        $routeA = $this->makeRoute($gatewayId);
        $this->putParameter($gatewayId, 'gateway', null, 'channel', 'static', 'gateway-default');
        $this->putParameter($gatewayId, 'route', $routeA, 'channel', 'static', 'route-a');

        dispatch_message_raw($this->actor(), $this->sender, [self::MCI], 'route a');

        // A second sender pointed at a DIFFERENT route on the same gateway, with its own override.
        $secondSender = '5000999888';
        $routeB = $this->makeRouteForSender($gatewayId, $secondSender);
        $this->putParameter($gatewayId, 'route', $routeB, 'channel', 'static', 'route-b');
        db()->prepare('UPDATE ellsms_meta SET originator = ? WHERE user_id = ?')->execute([$secondSender, $this->ownerId]);

        dispatch_message_raw(
            ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null, 'originator' => $secondSender],
            $secondSender, [self::MCI], 'route b'
        );

        $records = $this->recordings();
        $this->assertCount(2, $records);
        $this->assertSame('route-a', json_decode($records[0]['body'], true)['channel']);
        $this->assertSame('route-b', json_decode($records[1]['body'], true)['channel'], 'route A\'s override must not reach route B');
    }

    /* ================= TEST 5 — sender does not leak ================= */

    public function testSenderContextDoesNotLeakBetweenSends(): void {
        $gatewayId = $this->makeGateway('gw_sender');
        $this->makeRoute($gatewayId);
        // A template that reads the sender: two sends from two lines must carry two different values.
        $this->putParameter($gatewayId, 'gateway', null, 'from', 'template', 'line-{{sender}}');

        dispatch_message_raw($this->actor(), $this->sender, [self::MCI], 'first');

        $secondSender = '5000777666';
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$secondSender, 'default', $this->currentRouteId, 'active', $secondSender . ':default']);
        db()->prepare('UPDATE ellsms_meta SET originator = ? WHERE user_id = ?')->execute([$secondSender, $this->ownerId]);
        sms_pricing_cache_reset();

        dispatch_message_raw(
            ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null, 'originator' => $secondSender],
            $secondSender, [self::MCI], 'second'
        );

        $records = $this->recordings();
        $this->assertCount(2, $records);
        $this->assertSame('line-' . $this->sender, json_decode($records[0]['body'], true)['from']);
        $this->assertSame('line-' . $secondSender, json_decode($records[1]['body'], true)['from'], 'the first send\'s sender must not persist');
    }

    /* ================= PERFORMANCE REVALIDATION ================= */

    public function testAThousandMixedRecipientsStillCompileAndDecryptExactlyOnce(): void {
        $gatewayId = $this->makeGateway('gw_budget');
        $this->makeRoute($gatewayId);
        $this->operatorParameter($gatewayId, 'mci',     'operatorCode', '1');
        $this->operatorParameter($gatewayId, 'mtn',     'operatorCode', '2');
        $this->operatorParameter($gatewayId, 'rightel', 'operatorCode', '3');

        $destinations = [];
        foreach ([['0912'], ['0935'], ['0921']] as [$prefix]) {
            for ($i = 0; $i < 334; $i++) {
                $destinations[] = '98' . substr($prefix, 1) . str_pad((string)$i, 7, '0', STR_PAD_LEFT);
            }
        }
        $destinations = array_slice($destinations, 0, 1000);
        shuffle($destinations);

        // Counters are reset AFTER the fixture is built, so the assertions cover the send alone.
        gateway_cache_reset();
        gateway_counters_reset();
        $queriesBefore = $this->gatewayConfigQueryCount();

        dispatch_message_raw($this->actor(), $this->sender, $destinations, 'budget');

        $counters = gateway_counters_snapshot();
        $this->assertSame(1, $counters['compile'], '1000 mixed-operator recipients must compile once');
        $this->assertSame(1, $counters['config_load'], 'one configuration read for the whole send');
        $this->assertSame(1, $counters['secret_decrypt'], 'one decryption pass for the whole send');
        $this->assertSame(0, $counters['reload']);
        $this->assertSame(3, count($this->recordings()), 'three distinct configurations, three requests');
        // The strongest statement of the constraint: no per-recipient configuration lookup happened.
        $this->assertSame(1, $this->gatewayConfigQueryCount() - $queriesBefore,
            'exactly one gateway-config query for 1000 recipients');
    }

    public function testAGatewayWithoutOperatorOverridesStillSendsOneRequestForAMixedBatch(): void {
        // The property that keeps the migrated legacy gateway's byte-level parity intact: partitioning
        // is by effective CONFIGURATION, not by operator identity, so a gateway with no operator
        // overrides does not fragment.
        $gatewayId = $this->makeGateway('gw_nooverrides');
        $this->makeRoute($gatewayId);

        dispatch_message_raw($this->actor(), $this->sender, [self::MCI, self::MTN, self::RIGHTEL], 'one request');

        $records = $this->recordings();
        $this->assertCount(1, $records, 'no operator overrides means no reason to split the batch');
        $body = json_decode($records[0]['body'], true);
        $this->assertSame([self::MCI, self::MTN, self::RIGHTEL], $body['destinations']);
    }

    public function testAParameterReadingThePerRecipientVariableForcesOneRequestPerRecipient(): void {
        // `recipient` is singular: one request carries one value for it, so a batch that reads it
        // cannot be a batch at all. Sending three recipients with recipient #1's value stamped on all
        // of them is exactly the defect class this closure exists to remove.
        $gatewayId = $this->makeGateway('gw_perrecipient');
        $this->makeRoute($gatewayId);
        $this->putParameter($gatewayId, 'gateway', null, 'to', 'variable', 'recipient');

        dispatch_message_raw($this->actor(), $this->sender, [self::MCI, self::MTN, self::RIGHTEL], 'per recipient');

        $records = $this->recordings();
        $this->assertCount(3, $records);
        $seen = [];
        foreach ($records as $record) {
            $seen[] = json_decode($record['body'], true)['to'];
        }
        sort($seen);
        $this->assertSame([self::MCI, self::RIGHTEL, self::MTN], $seen);
    }

    /* ================= helpers ================= */

    private int $currentRouteId = 0;

    private function actor(): array {
        return ['id' => $this->ownerId, 'role' => 'user', 'organization_id' => null, 'originator' => $this->sender];
    }

    /** @return list<array> */
    private function recordings(): array {
        $records = [];
        foreach (array_filter(array_map('trim', explode("\n", (string)@file_get_contents(self::$recordFile)))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) { $records[] = $decoded; }
        }
        return $records;
    }

    /**
     * Every destination mapped to the `operatorCode` of the request that carried it.
     *
     * Built from the recorded requests, so a destination appearing under two operator codes — the
     * leakage this test class exists to detect — is impossible to miss.
     *
     * @return array<string,string>
     */
    private function operatorCodeByDestination(): array {
        $map = [];
        foreach ($this->recordings() as $record) {
            $body = json_decode($record['body'], true);
            $operatorCode = (string)($body['operatorCode'] ?? '');
            foreach (($body['destinations'] ?? []) as $destination) {
                $destination = (string)$destination;
                // A caller may legitimately pass the same number twice, and duplicates land in the
                // same group — so a repeat is fine. What must never happen is one number appearing
                // under two DIFFERENT operator codes, which is precisely what leakage looks like.
                if (array_key_exists($destination, $map)) {
                    $this->assertSame($map[$destination], $operatorCode,
                        "destination {$destination} was sent under two different operator codes");
                    continue;
                }
                $map[$destination] = $operatorCode;
            }
        }
        return $map;
    }

    /** How many times the gateway configuration table has been read, from MySQL's own counters. */
    private function gatewayConfigQueryCount(): int {
        $counters = gateway_counters_snapshot();
        return (int)$counters['config_load'];
    }

    private function makeGateway(string $prefix): int {
        $db = db();
        $code = $prefix . '_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active','batch',1,0,0,1)")->execute([$code, $code]);
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

        foreach ([
            ['destinations', 'recipients', 'string_list', 30],
            ['content',      'message',    'string',      40],
        ] as [$key, $value, $dataType, $sortOrder]) {
            $this->putParameter($gatewayId, 'gateway', null, $key, 'variable', $value, $dataType, $sortOrder);
        }
        gateway_cache_reset();
        return $gatewayId;
    }

    private function putParameter(int $gatewayId, string $scope, ?int $scopeId, string $key, string $valueType, string $value, string $dataType = 'string', int $sortOrder = 100): void {
        db()->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
             VALUES (?, 'send', 'body', ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        )->execute([
            $gatewayId, $scope, $scopeId, $key, $valueType, $value, $dataType, $sortOrder,
            "{$gatewayId}:send:body:{$scope}:" . ($scopeId ?? '') . ":{$key}",
        ]);
        gateway_cache_reset();
    }

    /** An operator-scoped override, addressed by the operator CODE the seeded catalog uses. */
    private function operatorParameter(int $gatewayId, string $operatorCode, string $key, string $value): void {
        $st = db()->prepare('SELECT id FROM ellsms_sms_operators WHERE code = ?');
        $st->execute([$operatorCode]);
        $operatorId = (int)($st->fetchColumn() ?: 0);
        $this->assertGreaterThan(0, $operatorId, "the seeded catalog must define operator '{$operatorCode}'");
        $this->putParameter($gatewayId, 'operator', $operatorId, $key, 'static', $value);
    }

    private function makeRoute(int $gatewayId): int {
        $this->currentRouteId = $this->makeRouteForSender($gatewayId, $this->sender);
        return $this->currentRouteId;
    }

    private function makeRouteForSender(int $gatewayId, string $sender): int {
        $suffix = bin2hex(random_bytes(3));
        db()->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['prov_' . $suffix, 'prov', 'active']);
        $providerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
            ->execute([$providerId, 'route_' . $suffix, 'route', 'default', 'active', $gatewayId]);
        $routeId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
            ->execute([$sender, 'default', $routeId, 'active', $sender . ':default']);
        sms_pricing_cache_reset();
        return $routeId;
    }
}
