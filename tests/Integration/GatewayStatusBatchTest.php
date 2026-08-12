<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * STATUS CONNECTOR BATCH CLOSURE — `provider_message_ids`, `integer_list`, and per-item correlation.
 *
 * Modelled on a real provider:
 *
 *   POST /rest/messageState
 *   {"username":"…","password":"…","referenceids":[7310136179845801812, 776846774851635393]}
 *   -> {"states":[{"id":7310136179845801812,"state":1}, …], "errorModel":{"errorCode":0}}
 *
 * Two things make this hard, and both are tested against bytes on a socket rather than internal
 * structures:
 *
 *  1. The ids are 19 digits. PHP's float has 53 bits of mantissa, so a single `(float)` anywhere turns
 *     7310136179845801812 into 7310136179845801800 — and a status lookup for an id that is off by
 *     three at the end returns nothing, which looks exactly like "the provider has no record".
 *  2. The provider answers in whatever order it likes, may omit an id, may repeat one, and may include
 *     one nobody asked about. Position-based correlation turns every one of those into a delivery
 *     state written onto the wrong message.
 */
final class GatewayStatusBatchTest extends IntegrationTestCase
{
    private static $server = null;
    private static int $port = 0;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private const ID_A = '7310136179845801812';
    private const ID_B = '776846774851635393';
    private const ID_C = '3717114266477167711';

    private const MASTER_KEY = 'status-batch-master-key-0123456789abcdef';

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_status_batch_' . bin2hex(random_bytes(6)) . '.jsonl';

        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/fixtures/fake_message_state_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (self::$server === false) {
            self::markTestSkipped('Could not start the messageState fixture.');
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(50000);
        }
        self::markTestSkipped('messageState fixture did not become reachable in time.');
    }

    public static function tearDownAfterClass(): void {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$recordFile !== '' && is_file(self::$recordFile)) {
            @unlink(self::$recordFile);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void {
        parent::setUp();
        putenv('APP_ENV=testing');
        putenv('SMS_GATEWAY_MASTER_KEY=' . self::MASTER_KEY);
        gateway_cache_reset();
        gateway_counters_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void {
        putenv('SMS_GATEWAY_MASTER_KEY');
        putenv('SMS_GATEWAY_STATUS_REQUEST_MAX');
        gateway_cache_reset();
        parent::tearDown();
    }

    /* ================= the exact production configuration ================= */

    public function testTheRealConfigurationProducesTheExactRequestJson(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $this->makeAttempt($gatewayId, self::ID_A);
        $this->makeAttempt($gatewayId, self::ID_B);
        $this->makeAttempt($gatewayId, self::ID_C);

        gateway_status_poll_pass();

        $records = $this->recordings();
        $this->assertCount(1, $records, 'three compatible messages must travel in ONE request');
        $this->assertSame('/rest/messageState', $records[0]['path']);

        // The exact bytes. Not a decoded comparison: the whole point is that the ids are unquoted
        // JSON numbers rather than strings, and a decode-then-compare would hide that difference.
        $this->assertSame(
            '{"username":"gateway-user","password":"gateway-pass","referenceids":['
            . self::ID_A . ',' . self::ID_B . ',' . self::ID_C . ']}',
            $records[0]['body']
        );
    }

    public function testASingleIdStillProducesAOneElementArray(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $this->makeAttempt($gatewayId, self::ID_A);

        gateway_status_poll_pass();

        $body = $this->recordings()[0]['body'];
        // Not a bare number, and not a quoted string.
        $this->assertStringContainsString('"referenceids":[' . self::ID_A . ']', $body);
        $this->assertStringNotContainsString('"referenceids":' . self::ID_A, $body);
        $this->assertStringNotContainsString('"' . self::ID_A . '"', $body);
    }

    public function testTheLongIdsSurviveIntactOnTheWire(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $this->makeAttempt($gatewayId, self::ID_A);

        gateway_status_poll_pass();

        $body = $this->recordings()[0]['body'];
        $this->assertStringContainsString(self::ID_A, $body, 'the exact 19 digits must appear');
        // What float corruption would have produced. Asserting its absence is what makes this test
        // fail loudly if a `(int)`/`(float)` ever creeps back into the path.
        $this->assertStringNotContainsString('7310136179845801800', $body);
    }

    public function testSecretsAreResolvedInTheRequestButMaskedInThePreview(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $connector = gateway_compiled($gatewayId);
        $context = gateway_status_context(['provider_message_ids' => [self::ID_A]]);
        $request = gateway_build_request($connector, 'status', $context, null, null);

        $this->assertStringContainsString('"username":"gateway-user"', (string)$request['body'], 'the real request carries the credential');
        $preview = json_encode($request['preview'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('gateway-user', (string)$preview, 'a preview must never reveal a credential');
        $this->assertStringNotContainsString('gateway-pass', (string)$preview);
        $this->assertStringContainsString('•', (string)$preview);
        // ...while the non-secret list is still legible rather than rendering as "Array".
        $this->assertStringContainsString(self::ID_A, (string)$preview);
    }

    /* ================= correlation ================= */

    public function testResponseOrderDoesNotMatter(): void {
        // The fixture answers /rest/messageState/reversed with the states in the opposite order and
        // with A=2 (delivered), B=3 (failed).
        $gatewayId = $this->makeBatchGateway('/rest/messageState/reversed');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        $rowB = $this->makeAttempt($gatewayId, self::ID_B);

        gateway_status_poll_pass();

        $this->assertSame('delivered', $this->attempt($rowA)['delivery_status'], self::ID_A . ' must take ITS OWN state');
        $this->assertSame('failed', $this->attempt($rowB)['delivery_status']);
    }

    public function testCanonicalStateMappingIsApplied(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');   // A=1, B=3, C=2
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        $rowB = $this->makeAttempt($gatewayId, self::ID_B);
        $rowC = $this->makeAttempt($gatewayId, self::ID_C);

        gateway_status_poll_pass();

        $this->assertSame('sent', $this->attempt($rowA)['delivery_status']);
        $this->assertSame('failed', $this->attempt($rowB)['delivery_status']);
        $this->assertSame('delivered', $this->attempt($rowC)['delivery_status']);
    }

    public function testAnUnmappedProviderStateBecomesUnknownNeverDelivered(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState/unmapped');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);

        gateway_status_poll_pass();

        // The provider sent state 97, which the connector maps to nothing. It resolves to `unknown` —
        // never to `delivered`, which is the one wrong guess with real-world consequences.
        $connector = gateway_compiled($gatewayId);
        $this->assertSame('unknown', gateway_status_map($connector['status']['statuses'], '97'));

        // And `unknown` does not overwrite the state already known: "the provider said something we
        // do not understand" is strictly less information than "accepted for delivery", so the row
        // keeps `sent` (gateway_status_may_transition()). It is emphatically not upgraded.
        $row = $this->attempt($rowA);
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame(1, (int)$row['delivery_attempts'], 'the poll still counted, so it stays bounded');
    }

    public function testAnUnmappedStateDoesFillAnEmptyDeliveryState(): void {
        // Where there is nothing to lose, `unknown` IS recorded — that is what makes the gap visible
        // to an operator rather than leaving a row looking un-polled.
        $gatewayId = $this->makeBatchGateway('/rest/messageState/unmapped');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        db()->prepare('UPDATE ellsms_message_attempts SET delivery_status = NULL WHERE id = ?')->execute([$rowA]);

        gateway_status_poll_pass();

        $this->assertSame('unknown', $this->attempt($rowA)['delivery_status']);
    }

    public function testAMissingResponseItemLeavesItsRowUntouchedAndCostsAnAttempt(): void {
        // The fixture omits ID_B entirely.
        $gatewayId = $this->makeBatchGateway('/rest/messageState/missing');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        $rowB = $this->makeAttempt($gatewayId, self::ID_B);
        $rowC = $this->makeAttempt($gatewayId, self::ID_C);

        $stats = gateway_status_poll_pass();

        $this->assertSame('sent', $this->attempt($rowA)['delivery_status']);
        $this->assertSame('delivered', $this->attempt($rowC)['delivery_status']);

        $missing = $this->attempt($rowB);
        $this->assertSame('sent', $missing['delivery_status'], 'the omitted message keeps its non-terminal state');
        $this->assertSame(1, (int)$missing['delivery_attempts'], 'and its attempt was counted, so polling is bounded');
        $this->assertSame(1, $stats['unmatched']);
    }

    public function testADuplicateResponseIdIsRefusedRatherThanResolvedArbitrarily(): void {
        // The fixture answers with ID_A twice, carrying two DIFFERENT states.
        $gatewayId = $this->makeBatchGateway('/rest/messageState/duplicate');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        $rowB = $this->makeAttempt($gatewayId, self::ID_B);

        $stats = gateway_status_poll_pass();

        // Picking the first or last occurrence would look exactly like a correct answer, so the
        // ambiguous id is dropped entirely and treated like a missing one.
        $this->assertSame('sent', $this->attempt($rowA)['delivery_status'], 'an ambiguous answer must not be applied');
        $this->assertSame(1, (int)$this->attempt($rowA)['delivery_attempts']);
        $this->assertSame(1, $stats['unmatched']);
        // The unambiguous sibling in the same response is unaffected.
        $this->assertSame('failed', $this->attempt($rowB)['delivery_status']);
    }

    public function testAnUnrequestedResponseIdCannotMutateAnyRow(): void {
        // The fixture includes an id nobody asked about, carrying 'delivered'.
        $gatewayId = $this->makeBatchGateway('/rest/messageState/unknown-id');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);
        // A row that exists but was NOT part of this request (already terminal, so not polled).
        $rowC = $this->makeAttempt($gatewayId, self::ID_C, 'delivered');

        gateway_status_poll_pass();

        $this->assertSame('sent', $this->attempt($rowA)['delivery_status'], 'the requested id keeps its own answer');
        $this->assertSame('delivered', $this->attempt($rowC)['delivery_status']);
    }

    public function testAProviderLevelErrorMeansNoStateIsRead(): void {
        // HTTP 200, well-formed body, but errorModel.errorCode = 12 — and `states` still present.
        $gatewayId = $this->makeBatchGateway('/rest/messageState/error');
        $rowA = $this->makeAttempt($gatewayId, self::ID_A);

        $stats = gateway_status_poll_pass();

        $this->assertSame('sent', $this->attempt($rowA)['delivery_status'],
            'a failed lookup must never be read as delivery data');
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, (int)$this->attempt($rowA)['delivery_attempts']);
    }

    /* ================= grouping ================= */

    public function testMessagesFromDifferentGatewaysAreNeverBatchedTogether(): void {
        $first = $this->makeBatchGateway('/rest/messageState');
        $second = $this->makeBatchGateway('/rest/messageState');
        $this->makeAttempt($first, self::ID_A);
        $this->makeAttempt($second, self::ID_B);

        gateway_status_poll_pass();

        $records = $this->recordings();
        $this->assertCount(2, $records, 'two gateways, two requests');
        foreach ($records as $record) {
            $ids = json_decode($record['body'], true)['referenceids'];
            $this->assertCount(1, $ids, 'each gateway asked only about its own message');
        }
    }

    public function testRowsWithDifferentOperatorOverridesAreNotBatchedTogether(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $operatorId = (int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
        // An operator-scoped status parameter makes that operator's request materially different.
        $this->putParameter($gatewayId, 'status', 'operator', $operatorId, 'channel', 'static', 'mci-only');

        $this->makeAttempt($gatewayId, self::ID_A, 'sent', $operatorId);
        $this->makeAttempt($gatewayId, self::ID_B, 'sent', null);

        gateway_status_poll_pass();

        $records = $this->recordings();
        $this->assertCount(2, $records, 'incompatible override sets must not share a request');
        $bodies = array_map(static fn($r) => json_decode($r['body'], true), $records);
        $withOverride = array_values(array_filter($bodies, static fn($b) => isset($b['channel'])));
        $this->assertCount(1, $withOverride);
        $this->assertSame([self::ID_A], array_map('strval', $withOverride[0]['referenceids']));
    }

    public function testAConnectorReadingAPerMessageVariableIsNeverBatched(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        // Adding a parameter that reads ONE message's id makes the request single-message by nature.
        $this->putParameter($gatewayId, 'status', 'gateway', null, 'single', 'variable', 'provider_message_id');

        $this->makeAttempt($gatewayId, self::ID_A);
        $this->makeAttempt($gatewayId, self::ID_B);

        gateway_status_poll_pass();

        $this->assertCount(2, $this->recordings(), 'one request per message when a per-message variable is used');
    }

    public function testAGroupIsCappedSoOneRequestCannotGrowUnbounded(): void {
        putenv('SMS_GATEWAY_STATUS_REQUEST_MAX=2');
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        $this->makeAttempt($gatewayId, self::ID_A);
        $this->makeAttempt($gatewayId, self::ID_B);
        $this->makeAttempt($gatewayId, self::ID_C);

        gateway_status_poll_pass();

        $records = $this->recordings();
        $this->assertCount(2, $records, 'three ids at a cap of two becomes two requests');
        $counts = array_map(static fn($r) => count(json_decode($r['body'], true)['referenceids']), $records);
        sort($counts);
        $this->assertSame([1, 2], $counts);
    }

    /* ================= performance ================= */

    public function testAThousandStatusRowsCompileAndDecryptExactlyOnce(): void {
        $gatewayId = $this->makeBatchGateway('/rest/messageState');
        putenv('SMS_GATEWAY_STATUS_BATCH=1000');
        putenv('SMS_GATEWAY_STATUS_REQUEST_MAX=1000');

        // Bulk-inserted so the fixture cost does not dominate; every row is a real pollable record.
        $values = [];
        $params = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[] = "(?, ?, 'direct_send', 'perf', '', 'accepted', ?, 1, ?, ?, 'sent', 0, NOW(), NOW(), ?)";
            $providerId = (string)(7310136179845800000 + $i);
            array_push($params, null, $this->ownerId(), $gatewayId, '98912' . str_pad((string)$i, 7, '0', STR_PAD_LEFT), $providerId, $gatewayId . ':' . $providerId);
        }
        db()->prepare(
            'INSERT INTO ellsms_message_attempts
               (organization_id, user_id, reference_type, reference_id, error_code, status,
                gateway_id, gateway_config_version, destination, provider_message_id,
                delivery_status, delivery_attempts, attempted_at, completed_at, provider_slot)
             VALUES ' . implode(',', $values)
        )->execute($params);

        gateway_cache_reset();
        gateway_counters_reset();
        $stats = gateway_status_poll_pass();
        putenv('SMS_GATEWAY_STATUS_BATCH');

        $counters = gateway_counters_snapshot();
        $this->assertSame(1, $counters['compile'], '1000 status rows must compile the connector once');
        $this->assertSame(1, $counters['config_load'], 'one configuration read for the whole pass');
        $this->assertSame(1, $counters['secret_decrypt'], 'one decryption pass for the whole pass');
        $this->assertSame(0, $counters['reload']);
        $this->assertSame(1000, $stats['polled']);
        $this->assertSame(1, $stats['requests'], 'one provider request, not one thousand');
    }

    /* ================= helpers ================= */

    private ?int $ownerId = null;

    private function ownerId(): int {
        return $this->ownerId ??= $this->makeUser();
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

    private function attempt(int $id): array {
        $st = db()->prepare('SELECT * FROM ellsms_message_attempts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: [];
    }

    /** The exact three parameters from the production configuration, plus the response mapping. */
    private function makeBatchGateway(string $path): int {
        $db = db();
        $code = 'sb_' . bin2hex(random_bytes(4));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, config_version)
                      VALUES (?,?, 'active','batch',1,1,1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, success_rule_json) VALUES (?,?,?)")
           ->execute([$gatewayId, self::$baseUrl . '/send', json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []])]);

        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_status_connectors
               (gateway_id, endpoint_url, http_method, content_type, success_rule_json, response_mapping_json, status_mapping_json,
                poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
             VALUES (?,?, 'POST', 'application/json', ?, ?, ?, 0, 6, 86400)"
        )->execute([
            $gatewayId, self::$baseUrl . $path,
            json_encode(['rules' => [['path' => 'errorModel.errorCode', 'operator' => 'equals', 'values' => [0]]]]),
            json_encode(['items_path' => 'states', 'id_path' => 'id', 'status_path' => 'state']),
            json_encode(['1' => 'sent', '2' => 'delivered', '3' => 'failed']),
        ]);

        gateway_secret_put($gatewayId, 'status_username', 'gateway-user');
        gateway_secret_put($gatewayId, 'status_password', 'gateway-pass');

        $this->putParameter($gatewayId, 'status', 'gateway', null, 'username', 'secret', 'status_username', 'string', 10);
        $this->putParameter($gatewayId, 'status', 'gateway', null, 'password', 'secret', 'status_password', 'string', 20);
        $this->putParameter($gatewayId, 'status', 'gateway', null, 'referenceids', 'variable', 'provider_message_ids', 'integer_list', 30);

        gateway_cache_reset();
        return $gatewayId;
    }

    private function putParameter(int $gatewayId, string $connector, string $scope, ?int $scopeId, string $key, string $valueType, string $value, string $dataType = 'string', int $sortOrder = 100): void {
        db()->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
             VALUES (?, ?, 'body', ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
        )->execute([
            $gatewayId, $connector, $scope, $scopeId, $key, $valueType, $value, $dataType, $sortOrder,
            "{$gatewayId}:{$connector}:body:{$scope}:" . ($scopeId ?? '') . ":{$key}",
        ]);
        gateway_cache_reset();
    }

    private function makeAttempt(int $gatewayId, string $providerMessageId, string $deliveryStatus = 'sent', ?int $operatorId = null): int {
        db()->prepare(
            "INSERT INTO ellsms_message_attempts
               (organization_id, user_id, reference_type, reference_id, error_code, status,
                gateway_id, gateway_config_version, operator_id, destination, provider_message_id,
                delivery_status, delivery_attempts, attempted_at, completed_at, provider_slot)
             VALUES (NULL, ?, 'direct_send', ?, '', 'accepted', ?, 1, ?, '989121234567', ?, ?, 0, NOW(), NOW(), ?)"
        )->execute([
            $this->ownerId(), 'ref-' . $providerMessageId, $gatewayId, $operatorId,
            $providerMessageId, $deliveryStatus, $gatewayId . ':' . $providerMessageId,
        ]);
        return (int)db()->lastInsertId();
    }
}
