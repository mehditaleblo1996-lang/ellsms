<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #36 — numeric per-recipient idempotency ids and "already accepted" answers, against a Vesal-shaped
 * ManyToMany endpoint (tests/fixtures/recording_gateway_server.php, /vesal/*) that applies Vesal's
 * real rules: userSuppliedIds must be empty or match the destinations' count, and a repeated
 * (userSuppliedId, destination) is answered with -453.
 *
 * The crash case this closes: the provider accepted a batch, the worker died before settling it, the
 * lease expired and the SAME rows were claimed again. Before, the retry either re-sent (no dedup) or,
 * with dedup, got -453 and marked a delivered message FAILED. Now it is settled as sent, once.
 */
final class VesalIdempotencyTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $ownerId;
    private int $orgId;
    private string $sender = '5000900177';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if ((string)getenv('ELLSMS_TEST_DB_HOST') === '' || !function_exists('proc_open')) {
            self::markTestSkipped('needs ELLSMS_TEST_DB_HOST and proc_open()');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::markTestSkipped("Could not allocate a local port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_vesal_idem_' . bin2hex(random_bytes(6)) . '.jsonl';

        $env = getenv();
        $env['ELLSMS_RECORDER_FILE'] = self::$recordFile;
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/recording_gateway_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
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
        @unlink(self::$recordFile . '.vesal');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        db()->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
            ->execute(['vesal org', 'vesal-' . bin2hex(random_bytes(4)), $this->ownerId]);
        $this->orgId = (int)db()->lastInsertId();
        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('SMS_PROVIDER_BATCH_SIZE');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /** A Vesal-like ManyToMany gateway with positional correlation on `references`. */
    private function makeVesalGateway(bool $withIds, array $duplicateValues): int
    {
        $db = db();
        $code = 'vesal_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $mapping = ['correlation_mode' => 'position', 'provider_ids_path' => 'references'];
        if ($duplicateValues !== []) {
            $mapping['duplicate_values'] = $duplicateValues;
        }
        $db->prepare(
            "INSERT INTO ellsms_sms_gateway_send_connectors
               (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
             VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)"
        )->execute([
            $gatewayId, self::$baseUrl . '/vesal/ManyToMany',
            json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
            json_encode($mapping),
        ]);
        $params = [
            ['destinations', 'recipients_array', 'string_array', 30],
            ['contents',     'messages_array',   'string_array', 40],
        ];
        if ($withIds) {
            $params[] = ['userSuppliedIds', 'idempotency_ids_array', 'integer_array', 50];
        }
        foreach ($params as [$key, $value, $dataType, $sortOrder]) {
            $db->prepare(
                "INSERT INTO ellsms_sms_gateway_parameters
                   (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                 VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)"
            )->execute([$gatewayId, $key, $value, $dataType, $sortOrder, "{$gatewayId}:send:body:gateway::{$key}"]);
        }

        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->sender, 'default', $routeId, 'active', $this->sender . ':default']);
        sms_pricing_cache_reset();
        gateway_cache_reset();
        return $gatewayId;
    }

    private function makeJob(array $mobiles): int
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, total_rows, status) VALUES (?,?,?,?,?, 'processing')")
           ->execute([$this->ownerId, $this->orgId, 'vesal idem', $this->sender, count($mobiles)]);
        $jobId = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?,?,?, 'pending')");
        foreach ($mobiles as $i => $mobile) {
            $ins->execute([$jobId, $mobile, 'پیام ' . $i]);
        }
        return $jobId;
    }

    private function items(int $jobId): array
    {
        $st = db()->prepare('SELECT id, mobile, status, provider_message_id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        return $st->fetchAll();
    }

    private function bodies(): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            $out[] = json_decode((string)($r['body'] ?? ''), true);
        }
        return $out;
    }

    private function sendJob(int $jobId): void
    {
        $items = bulk_claim_items(db(), 'j.id = ?', [$jobId], 100);
        bulk_send_claimed_items(db(), $items);
    }

    public function testUserSuppliedIdsAreTheBulkItemIdsAsJsonNumbersInRecipientOrder(): void
    {
        $this->makeVesalGateway(true, ['-453']);
        $jobId = $this->makeJob(['989121000001', '989121000002', '989121000003']);
        $this->sendJob($jobId);

        $bodies = $this->bodies();
        self::assertCount(1, $bodies);
        $items = $this->items($jobId);
        self::assertSame(array_map(static fn($r) => (int)$r['id'], $items), $bodies[0]['userSuppliedIds'],
            'one integer per recipient, positionally aligned, equal to the bulk item id');
        $raw = (string)json_decode((string)file_get_contents(self::$recordFile), true)['body'];
        self::assertMatchesRegularExpression('/"userSuppliedIds":\[\d+,\d+,\d+\]/', $raw, 'ids must be JSON numbers, not strings');
    }

    public function testARetryAfterACrashIsSettledAsSentNotFailedAndNotResent(): void
    {
        $this->makeVesalGateway(true, ['-453']);
        $mobiles = ['989121000011', '989121000012', '989121000013'];
        $jobId = $this->makeJob($mobiles);
        $items = $this->items($jobId);
        // The provider already accepted the first two on an attempt that died before ELLSMS settled it.
        file_put_contents(self::$recordFile . '.vesal', $items[0]['id'] . '|' . $mobiles[0] . "\n" . $items[1]['id'] . '|' . $mobiles[1] . "\n");

        $this->sendJob($jobId);

        $after = $this->items($jobId);
        foreach ($after as $row) {
            self::assertSame('sent', (string)$row['status'], 'an "already accepted" answer is a delivered message, not a failure');
        }
        self::assertNull($after[0]['provider_message_id'], 'the duplicate answer carries no reference id, and none is invented');
        self::assertNull($after[1]['provider_message_id']);
        self::assertNotNull($after[2]['provider_message_id'], 'the genuinely new recipient keeps its real reference');

        $st = db()->prepare('SELECT sent_rows, failed_rows FROM ellsms_bulk_jobs WHERE id = ?');
        $st->execute([$jobId]);
        $counts = $st->fetch();
        self::assertSame(3, (int)$counts['sent_rows']);
        self::assertSame(0, (int)$counts['failed_rows']);
    }

    public function testWithoutDuplicateValuesConfiguredTheOldBehaviourIsUnchanged(): void
    {
        $this->makeVesalGateway(true, []);
        $mobiles = ['989121000021', '989121000022'];
        $jobId = $this->makeJob($mobiles);
        $items = $this->items($jobId);
        file_put_contents(self::$recordFile . '.vesal', $items[0]['id'] . '|' . $mobiles[0] . "\n");

        $this->sendJob($jobId);

        $after = $this->items($jobId);
        self::assertNotSame('sent', (string)$after[0]['status'], '-453 is only "already accepted" when the admin says so');
        self::assertSame('sent', (string)$after[1]['status']);
    }

    public function testAConnectorWithoutIdsSendsNoUserSuppliedIdsField(): void
    {
        $this->makeVesalGateway(false, []);
        $jobId = $this->makeJob(['989121000031']);
        $this->sendJob($jobId);
        self::assertArrayNotHasKey('userSuppliedIds', $this->bodies()[0]);
        self::assertSame('sent', (string)$this->items($jobId)[0]['status']);
    }
}
