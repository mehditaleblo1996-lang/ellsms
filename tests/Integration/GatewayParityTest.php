<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * STEP 47 — LEGACY BEHAVIOR PARITY, the hard acceptance criterion for the gateway connector builder.
 *
 * The claim being tested is byte-level: a request built from the migrated `legacy_rest` gateway
 * configuration must be indistinguishable from the one app/backend.php has always sent. So this test
 * sends BOTH, through a real socket, to a receiver that records exactly what arrived
 * (tests/fixtures/recording_gateway_server.php), and compares the recordings.
 *
 * Comparing the built request structure instead would prove only that two functions in this repo
 * agree with each other — which is exactly the mistake that lets a signing or encoding difference
 * reach production unnoticed.
 *
 * The two request-scoped values that CANNOT be identical between two separate calls (the HMAC
 * timestamp and the request id) are excluded from the byte comparison and verified separately: the
 * signature is recomputed from the recorded timestamp and body and must match, which is a stronger
 * check than equality would have been.
 */
final class GatewayParityTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static int $port = 0;
    private static string $recordFile = '';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '') {
            self::markTestSkipped('ELLSMS_TEST_DB_HOST not set — see Makefile target test-integration.');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is not available in this PHP build.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_gateway_parity_' . bin2hex(random_bytes(6)) . '.jsonl';

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
        // The endpoint is configured through the environment rather than the settings table: setting()
        // caches process-wide on first call, so a value written inside this test's transaction would
        // not be seen (IntegrationTestCase's own docblock explains the same constraint).
        putenv('API_BASE_URL=' . self::$baseUrl);
        putenv('APP_ENV=testing');
        gateway_cache_reset();
        gateway_counters_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void {
        putenv('API_BASE_URL');
        putenv('BACKEND_SERVICE_ID');
        putenv('BACKEND_SERVICE_SECRET');
        gateway_cache_reset();
        parent::tearDown();
    }

    /** @return list<array> the recorded requests, in arrival order */
    private function recordings(): array {
        $raw = (string)@file_get_contents(self::$recordFile);
        $records = [];
        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    /**
     * Builds the legacy gateway the same way cron/sms-gateway-backfill.php does.
     *
     * Deliberately duplicated here rather than shelling out to the command: the command commits, and
     * this test's isolation depends on its transaction being rolled back. The VALUES are the thing
     * under test, so they are kept identical to the command's, and the operational command has its own
     * smoke coverage.
     */
    private function createLegacyGateway(bool $withHmac): int {
        $db = db();
        $db->prepare(
            "INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, default_slot, config_version)
             VALUES ('legacy_rest','درگاه فعلی (REST)','active','batch',1,0,1,1,1)"
        )->execute();
        $gatewayId = (int)$db->lastInsertId();

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors
               (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms,
                tls_verify, auth_type, auth_config_json, success_rule_json, response_mapping_json, batch_mapping_json)
             VALUES (?,?,'POST','application/json',5000,30000,1,?,?,?,?,?)"
        )->execute([
            $gatewayId,
            self::$baseUrl . '/api/messages/send',
            $withHmac ? 'ellsms_hmac' : 'none',
            $withHmac ? json_encode(['service_id_env' => 'BACKEND_SERVICE_ID', 'service_secret_env' => 'BACKEND_SERVICE_SECRET']) : null,
            json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
            json_encode(new \stdClass()),
            json_encode([
                'rows_path' => '', 'destination_key' => 'destination', 'status_key' => 'status',
                'success_values' => ['sent'], 'message_id_key' => 'id',
            ]),
        ]);

        foreach ([
            ['sender_user_id', 'sender_user_id', 'integer',     10],
            ['originator',     'sender',         'numeric',     20],
            ['destinations',   'recipients',     'string_list', 30],
            ['content',        'message',        'string',      40],
        ] as [$key, $value, $dataType, $sortOrder]) {
            $db->prepare(
                "INSERT INTO ellsms_sms_gateway_parameters
                   (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                 VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)"
            )->execute([$gatewayId, $key, $value, $dataType, $sortOrder, "{$gatewayId}:send:body:gateway::{$key}"]);
        }

        gateway_cache_reset();
        return $gatewayId;
    }

    public function testGatewayRequestIsByteIdenticalToLegacyRequest(): void {
        $gatewayId = $this->createLegacyGateway(false);
        $connector = gateway_compiled($gatewayId);
        $this->assertNotNull($connector, 'the migrated legacy gateway must compile');

        $senderUserId = 42;
        $originator   = '5000435800';
        $destinations = ['989121234567', '989351112233'];
        $content      = 'سلام — یک پیام آزمایشی with ASCII too';

        // 1. the legacy path
        backend_api_send($senderUserId, $originator, $destinations, $content);
        // 2. the configured gateway
        gateway_send($connector, [
            'sender' => $originator, 'recipients' => $destinations, 'message' => $content,
            'sender_user_id' => $senderUserId,
        ], null, null);

        $records = $this->recordings();
        $this->assertCount(2, $records, 'both paths must have reached the receiver');
        [$legacy, $gateway] = $records;

        $this->assertSame($legacy['method'], $gateway['method']);
        $this->assertSame($legacy['path'], $gateway['path']);
        $this->assertSame($legacy['query'], $gateway['query']);
        $this->assertSame($legacy['headers']['Content-Type'] ?? null, $gateway['headers']['Content-Type'] ?? null);
        // The claim in full: identical bytes, including JSON key order, numeric typing of
        // `originator`, the destinations array, and unescaped Persian content.
        $this->assertSame($legacy['body'], $gateway['body'], 'gateway request body must be byte-identical to the legacy one');
    }

    public function testHmacSignedGatewayRequestMatchesLegacySigning(): void {
        putenv('BACKEND_SERVICE_ID=svc-parity');
        putenv('BACKEND_SERVICE_SECRET=' . str_repeat('s', 40));

        $gatewayId = $this->createLegacyGateway(true);
        $connector = gateway_compiled($gatewayId);
        $this->assertNotNull($connector);

        backend_api_send(7, '3000', ['989121234567'], 'signed');
        gateway_send($connector, ['sender' => '3000', 'recipients' => ['989121234567'], 'message' => 'signed', 'sender_user_id' => 7], null, null);

        $records = $this->recordings();
        $this->assertCount(2, $records);
        [$legacy, $gateway] = $records;

        $this->assertSame($legacy['body'], $gateway['body']);
        $this->assertSame($legacy['headers']['X-Ellsms-Service-Id'], $gateway['headers']['X-Ellsms-Service-Id']);
        $this->assertArrayHasKey('X-Ellsms-Request-Id', $gateway['headers']);

        // The timestamp legitimately differs between two calls, so the signature is re-derived from
        // what each request actually carried. Both must verify under the same canonical string — a
        // check that would fail if the gateway signed a different body, path, or field order.
        foreach ([$legacy, $gateway] as $record) {
            $expected = hash_hmac('sha256', implode("\n", [
                'POST', '/api/messages/send', $record['headers']['X-Ellsms-Timestamp'],
                hash('sha256', $record['body']), $record['headers']['X-Ellsms-Service-Id'],
            ]), str_repeat('s', 40));
            $this->assertSame($expected, $record['headers']['X-Ellsms-Signature']);
        }
    }

    public function testGatewayReportsTheSameAcceptedSubsetAsTheLegacyPath(): void {
        // The receiver rejects any destination containing "000", so this exercises partial success —
        // the case where "how many sent" and "WHICH sent" diverge, which pricing depends on.
        $gatewayId = $this->createLegacyGateway(false);
        $connector = gateway_compiled($gatewayId);

        $destinations = ['989121234567', '989120001111', '989351112233'];
        [$legacyOk, , $legacyRows] = backend_api_send(9, '5000', $destinations, 'partial');
        $legacySent = [];
        foreach ((array)$legacyRows as $row) {
            if (($row['status'] ?? '') === 'sent') {
                $legacySent[] = (string)$row['destination'];
            }
        }

        $result = gateway_send($connector, ['sender' => '5000', 'recipients' => $destinations, 'message' => 'partial', 'sender_user_id' => 9], null, null);

        $this->assertTrue($legacyOk);
        $this->assertTrue($result['ok']);
        $this->assertSame($legacySent, $result['sent'], 'the gateway must accept exactly the subset the legacy path accepted');
        $this->assertCount(2, $result['sent']);
        $this->assertSame('1000', (string)$result['message_ids']['989121234567'], 'provider message ids must be captured per destination');
    }

    /**
     * STEP 33/67 — the performance budget. 1000 sends must compile the connector ONCE and decrypt
     * secrets ONCE. Asserted with counters rather than wall-clock: a timing assertion would be flaky
     * and could not distinguish "cached" from "the machine was idle".
     */
    public function testThousandSendsCompileAndDecryptExactlyOnce(): void {
        $gatewayId = $this->createLegacyGateway(false);
        gateway_counters_reset();

        for ($i = 0; $i < 1000; $i++) {
            $connector = gateway_compiled($gatewayId);
            $this->assertNotNull($connector);
            // The whole per-message hot path except the socket write.
            gateway_build_request($connector, 'send', gateway_send_context([
                'sender' => '5000', 'recipients' => ['98912000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)],
                'message' => 'budget', 'sender_user_id' => 1,
            ]), null, null);
        }

        $counters = gateway_counters_snapshot();
        $this->assertSame(1, $counters['compile'], '1000 sends must compile the connector exactly once');
        $this->assertSame(1, $counters['config_load'], '1000 sends must read gateway configuration exactly once');
        $this->assertSame(1, $counters['secret_decrypt'], '1000 sends must decrypt secrets exactly once');
        $this->assertSame(999, $counters['cache_hit']);
        $this->assertSame(0, $counters['reload']);
    }

    /** A configuration change must reach a long-running worker without a restart — and cost one reload. */
    public function testConfigVersionBumpCausesExactlyOneReload(): void {
        $gatewayId = $this->createLegacyGateway(false);
        putenv('SMS_GATEWAY_VERSION_CHECK_SECONDS=0');   // no version-cache TTL, so the change is seen at once
        gateway_counters_reset();

        $before = gateway_compiled($gatewayId);
        $this->assertSame(1, $before['config_version']);

        db()->prepare('UPDATE ellsms_sms_gateway_parameters SET value = ? WHERE gateway_id = ? AND param_key = ?')
            ->execute(['message', $gatewayId, 'content']);
        db()->prepare('UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE id = ?')->execute([$gatewayId]);

        $after = gateway_compiled($gatewayId);
        putenv('SMS_GATEWAY_VERSION_CHECK_SECONDS');

        $this->assertSame(2, $after['config_version'], 'the worker must pick up the new version without a restart');
        $counters = gateway_counters_snapshot();
        $this->assertSame(1, $counters['reload']);
        $this->assertSame(2, $counters['compile']);
    }
}
