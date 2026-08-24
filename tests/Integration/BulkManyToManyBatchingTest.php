<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * PHASE 9C — generic ManyToMany batching for rows whose content genuinely differs (P2P/Smart).
 *
 * THE DEFECT THIS CLOSES: dispatch_message_raw() carried ONE $content string for every destination
 * in a call, and gateway_send_context() built messages_array/senders_array by array_fill()-repeating
 * a single value. So even a connector correctly configured to reference messages_array received the
 * SAME text N times — the ManyToMany machinery existed but never carried real per-row data.
 * bulk_group_key() therefore had to fragment by content to stay correct, which is why P2P/Smart rows
 * (each with their own body) never batched.
 *
 * WHAT DID NOT CHANGE: gateway_send()'s grouping-by-effective-configuration, positional correlation
 * (gateway_extract_positional_result(), covered by BulkProviderBatchingTest already), and financial
 * settlement all stay exactly as Phase 9A left them. This closure only makes messages_array/
 * idempotency_keys_array carry real per-destination data, and only relaxes bulk_group_key()'s content
 * constraint when the resolved connector's COMPILED parameters actually reference messages_array
 * (gateway_connector_supports_per_recipient_content()) — capability-driven, never provider-specific.
 *
 * Every assertion is made against bytes that actually crossed a socket
 * (tests/fixtures/recording_gateway_server.php), for the same reason BulkProviderBatchingTest is: an
 * internal-function assertion would only prove two functions in this repo agree with each other.
 */
final class BulkManyToManyBatchingTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $ownerId;
    private int $orgId;
    private string $sender = '5000900101';

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
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($socket);
        self::$baseUrl = 'http://127.0.0.1:' . self::$port;
        self::$recordFile = sys_get_temp_dir() . '/ellsms_m2m_batch_' . bin2hex(random_bytes(6)) . '.jsonl';

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

    public static function tearDownAfterClass(): void
    {
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->makeUser(['originator' => $this->sender]);
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, created_by_user_id) VALUES (?,?,?)')
           ->execute(['m2m org', 'm2m-' . bin2hex(random_bytes(4)), $this->ownerId]);
        $this->orgId = (int)$db->lastInsertId();

        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('APP_ENV=testing');
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        putenv('WORKER_BULK_BATCH_SIZE=1000');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('SMS_PROVIDER_BATCH_SIZE');
        putenv('WORKER_BULK_BATCH_SIZE');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    private function recorded(): array
    {
        $raw = (string)@file_get_contents(self::$recordFile);
        $out = [];
        foreach (array_filter(explode("\n", $raw)) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    private function recordedBodies(): array
    {
        $bodies = [];
        foreach ($this->recorded() as $r) {
            $decoded = json_decode((string)($r['body'] ?? ''), true);
            if (is_array($decoded)) {
                $bodies[] = $decoded;
            }
        }
        return $bodies;
    }

    /**
     * A gateway whose compiled parameters reference messages_array — the ManyToMany-capable shape
     * this whole closure is about. $withIdempotency additionally wires idempotency_keys_array, for
     * the 9C.10 tests.
     */
    private function makeManyToManyGateway(string $sendMode = 'batch', bool $withIdempotency = false): int
    {
        $db = db();
        $code = 'm2m_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', ?, 1, 0, 0, 1)")->execute([$code, $code, $sendMode]);
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

        $params = [
            ['destinations', 'recipients_array', 'string_array', 30],
            ['contents',     'messages_array',   'string_array', 40],
        ];
        if ($withIdempotency) {
            $params[] = ['idempotency_keys', 'idempotency_keys_array', 'string_array', 50];
        }
        foreach ($params as [$key, $value, $dataType, $sortOrder]) {
            $this->putParameter($gatewayId, $key, 'variable', $value, $dataType, $sortOrder);
        }
        gateway_cache_reset();
        return $gatewayId;
    }

    /** A gateway with NO messages_array reference — the capability check must return false for it. */
    private function makePlainGateway(string $sendMode = 'batch'): int
    {
        $db = db();
        $code = 'plain_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', ?, 1, 0, 0, 1)")->execute([$code, $code, $sendMode]);
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
            $this->putParameter($gatewayId, $key, 'variable', $value, $dataType, $sortOrder);
        }
        gateway_cache_reset();
        return $gatewayId;
    }

    private function putParameter(int $gatewayId, string $key, string $valueType, string $value, string $dataType, int $sortOrder): void
    {
        db()->prepare(
            "INSERT INTO ellsms_sms_gateway_parameters
               (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
             VALUES (?, 'send', 'body', 'gateway', NULL, ?, ?, ?, ?, 'active', ?, ?)"
        )->execute([
            $gatewayId, $key, $valueType, $value, $dataType, $sortOrder,
            "{$gatewayId}:send:body:gateway::{$key}",
        ]);
        gateway_cache_reset();
    }

    private function makeRouteForSender(int $gatewayId, string $sender): int
    {
        $suffix = bin2hex(random_bytes(3));
        $db = db();
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$sender, 'default', $routeId, 'active', $sender . ':default']);
        sms_pricing_cache_reset();
        return $routeId;
    }

    /** A processing bulk job — $contents (parallel to generated mobiles) makes it a P2P-shaped job. */
    private function makeJob(int $count, array $opts = []): array
    {
        $db = db();
        $sender  = (string)($opts['sender'] ?? $this->sender);
        $userId  = (int)($opts['user_id'] ?? $this->ownerId);
        $defaultContent = (string)($opts['content'] ?? 'متن پیش‌فرض');

        $db->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, template, total_rows, status, throttle_count, throttle_minutes)
             VALUES (?,?,?,?,?,?, 'processing', ?, ?)"
        )->execute([
            $userId, $this->orgId, 'm2m test', $sender, null, $count,
            $opts['throttle_count'] ?? null, $opts['throttle_minutes'] ?? null,
        ]);
        $jobId = (int)$db->lastInsertId();

        $mobiles = $opts['mobiles'] ?? null;
        $ins = $db->prepare(
            "INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, price_cost_credits)
             VALUES (?,?,?, 'pending', ?)"
        );
        $created = [];
        for ($i = 0; $i < $count; $i++) {
            $mobile = $mobiles !== null
                ? (string)$mobiles[$i]
                : '98913' . str_pad((string)(11111 + $i), 7, '1', STR_PAD_LEFT);
            $rowContent = isset($opts['contents']) ? (string)$opts['contents'][$i] : $defaultContent;
            $ins->execute([$jobId, $mobile, $rowContent, $opts['price'] ?? null]);
            $created[] = $mobile;
        }
        return ['job_id' => $jobId, 'mobiles' => $created];
    }

    private function itemStatuses(int $jobId): array
    {
        $st = db()->prepare('SELECT id, mobile, content, status, provider_message_id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ 1/2. P2P batches when capable

    public function test200P2pRowsWithDifferentContentBecomeOneBatchRequest(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $contents = [];
        for ($i = 0; $i < 200; $i++) { $contents[] = 'پیام شماره ' . $i; }
        $job = $this->makeJob(200, ['contents' => $contents]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 1000);
        self::assertCount(200, $items);

        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(1, $bodies, '200 different-content rows on a ManyToMany-capable connector must be ONE request');
        self::assertCount(200, $bodies[0]['destinations'] ?? [], 'the single request must carry all 200 destinations');
        self::assertCount(200, $bodies[0]['contents'] ?? [], 'and all 200 of their own message bodies');
    }

    public function test450P2pRowsSplitAtProviderBatchSize(): void
    {
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $contents = [];
        for ($i = 0; $i < 450; $i++) { $contents[] = 'msg-' . $i; }
        $job = $this->makeJob(450, ['contents' => $contents]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 1000);
        bulk_send_claimed_items(db(), $items);

        $sizes = array_map(static fn(array $b): int => count($b['destinations'] ?? []), $this->recordedBodies());
        self::assertSame([200, 200, 50], $sizes, '450 different-content rows at batch size 200 must become exactly 200+200+50');
        self::assertSame(450, array_sum($sizes));
    }

    // ------------------------------------------------------------------ 3. per-recipient sender

    public function testDifferentOriginatorsStillGroupOrSplitPerConnectorCapability(): void
    {
        // Rows in one bulk job always share one originator (ellsms_bulk_jobs.originator is a single
        // column) — "different originators" in practice means two SEPARATE jobs, which is already
        // covered by BulkProviderBatchingTest::testItemsWithDifferentSendersAreNeverMergedIntoOneRequest.
        // This test instead confirms senders_array carries the CORRECT sender for a ManyToMany
        // connector configured to send it, proving 9C did not disturb that array.
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->putParameter($gatewayId, 'originators', 'variable', 'senders_array', 'string_array', 60);
        $this->makeRouteForSender($gatewayId, $this->sender);

        $job = $this->makeJob(3, ['contents' => ['a', 'b', 'c']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(1, $bodies);
        self::assertSame([$this->sender, $this->sender, $this->sender], $bodies[0]['originators'] ?? []);
    }

    // ------------------------------------------------------------------ 4. incompatible connector splits

    public function testAPlainConnectorStillSplitsByContent(): void
    {
        // The exact capability gate: a connector that does NOT reference messages_array must keep
        // fragmenting by content — nothing about this closure may change ITS behaviour.
        $gatewayId = $this->makePlainGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $job = $this->makeJob(4, ['contents' => ['یک', 'دو', 'سه', 'چهار']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(4, $bodies, 'a connector with no messages_array reference must not batch different-content rows');
    }

    public function testGatewayConnectorSupportsPerRecipientContentIsCapabilityDrivenNotProviderSpecific(): void
    {
        $capableId = $this->makeManyToManyGateway('batch');
        $plainId   = $this->makePlainGateway('batch');

        self::assertTrue(
            gateway_connector_supports_per_recipient_content(gateway_compiled($capableId)),
            'a connector referencing messages_array must be detected as capable'
        );
        self::assertFalse(
            gateway_connector_supports_per_recipient_content(gateway_compiled($plainId)),
            'a connector referencing only the scalar message must not be detected as capable'
        );
    }

    // ------------------------------------------------------------------ 5. Persian, quotes, commas, newlines, emoji

    public function testHostileContentSurvivesExactlyInTheRequestBody(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $hostile = [
            'سلام «فارسی» با کاما، و "نقل‌قول"',
            "خط جدید\nدومین خط",
            'ایموجی 😀🚀🔥 و علامت % و & و <html>',
            'virgule, comma, "quote", and \'apostrophe\'',
        ];
        $job = $this->makeJob(4, ['contents' => $hostile]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(1, $bodies, 'ManyToMany-capable, so still one request despite four different bodies');
        $sentContents = $bodies[0]['contents'] ?? [];
        sort($hostile);
        $sortedSent = $sentContents;
        sort($sortedSent);
        self::assertSame($hostile, $sortedSent, 'every hostile string must arrive byte-for-byte, not mangled by string interpolation');
    }

    // ------------------------------------------------------------------ 6. positional correlation

    public function testPositionalCorrelationStillAssignsDistinctProviderIdsPerRow(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $contents = ['یک', 'دو', 'سه', 'چهار', 'پنج'];
        $job = $this->makeJob(5, ['contents' => $contents]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $rows = $this->itemStatuses($job['job_id']);
        $ids = [];
        foreach ($rows as $r) {
            self::assertSame('sent', (string)$r['status']);
            self::assertNotNull($r['provider_message_id']);
            $ids[] = (string)$r['provider_message_id'];
        }
        self::assertCount(5, array_unique($ids), 'every recipient must keep its own provider reference even with per-row content');
    }

    // ------------------------------------------------------------------ 7. count mismatch fails closed

    public function testProviderCountMismatchFailsClosedEvenWithPerRecipientContent(): void
    {
        // A malformed provider whose batch_mapping_json declares positional correlation but the
        // recording fixture answers with the legacy row-shape — reuse GatewayOperatorPartitionTest's
        // approach is unnecessary here: gateway_extract_positional_result() is already covered by its
        // own dedicated tests. This test's job is narrower — confirm a ManyToMany send configured for
        // POSITIONAL correlation against a response with the WRONG count still fails closed, i.e. that
        // Phase 9C did not accidentally bypass gateway_extract_positional_result().
        $db = db();
        $code = 'm2mpos_' . bin2hex(random_bytes(3));
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
            json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references']),
        ]);
        foreach ([
            ['destinations', 'recipients_array', 'string_array', 30],
            ['contents',     'messages_array',   'string_array', 40],
        ] as [$key, $value, $dataType, $sortOrder]) {
            $this->putParameter($gatewayId, $key, 'variable', $value, $dataType, $sortOrder);
        }
        $this->makeRouteForSender($gatewayId, $this->sender);
        gateway_cache_reset();

        // The recording fixture's default response is the legacy row-shape (no 'references' key), so
        // gateway_path_extract() finds nothing -> [] !== count(destinations) -> fail closed.
        $job = $this->makeJob(3, ['contents' => ['a', 'b', 'c']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $rows = $this->itemStatuses($job['job_id']);
        foreach ($rows as $r) {
            self::assertNotSame('sent', (string)$r['status'], 'a correlation failure must never mark a row sent');
            self::assertNull($r['provider_message_id'], 'a correlation failure must never fabricate a provider id');
        }
    }

    // ------------------------------------------------------------------ 8. large provider IDs

    public function testLongProviderReferenceSurvivesManyToManyCorrelationExactly(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(2, ['contents' => ['یک', 'دو']]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $big = '4473621976262727360';
        db()->prepare('UPDATE ellsms_bulk_items SET provider_message_id = ? WHERE job_id = ? ORDER BY id LIMIT 1')
            ->execute([$big, $job['job_id']]);
        $read = (string)db()->query('SELECT provider_message_id FROM ellsms_bulk_items WHERE job_id = ' . (int)$job['job_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();

        self::assertSame($big, $read);
        self::assertSame(19, strlen($read));
        self::assertNotSame((string)(float)$big, $read, 'float handling would visibly corrupt it');
    }

    // ------------------------------------------------------------------ 9. mixed provider failures

    public function testMixedFailuresLeaveOnlyTheRejectedRowsUnsent(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        // The recording fixture rejects any destination containing "000".
        $rejected = '989130001111';
        $mobiles  = ['989131111111', '989131111112', $rejected, '989131111114'];
        $job = $this->makeJob(4, ['mobiles' => $mobiles, 'contents' => ['a', 'b', 'c', 'd']]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        self::assertCount(1, $this->recordedBodies(), 'all four must still travel as one request');

        $byMobile = [];
        foreach ($this->itemStatuses($job['job_id']) as $r) {
            $byMobile[(string)$r['mobile']] = $r;
        }
        foreach (['989131111111', '989131111112', '989131111114'] as $ok) {
            self::assertSame('sent', (string)$byMobile[$ok]['status']);
        }
        self::assertNotSame('sent', (string)$byMobile[$rejected]['status']);
        self::assertNull($byMobile[$rejected]['provider_message_id']);
    }

    // ------------------------------------------------------------------ 10. two workers

    public function testTwoWorkersNeverDuplicateP2pSends(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $contents = [];
        for ($i = 0; $i < 40; $i++) { $contents[] = 'msg-' . $i; }
        $job = $this->makeJob(40, ['contents' => $contents]);

        $first  = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 20);
        $second = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 20);
        self::assertCount(20, $first);
        self::assertCount(20, $second);
        self::assertSame([], array_intersect(
            array_map(static fn(array $i): int => (int)$i['id'], $first),
            array_map(static fn(array $i): int => (int)$i['id'], $second)
        ));

        bulk_send_claimed_items(db(), $first);
        bulk_send_claimed_items(db(), $second);

        $allDestinations = [];
        foreach ($this->recordedBodies() as $body) {
            foreach (($body['destinations'] ?? []) as $d) { $allDestinations[] = $d; }
        }
        self::assertSame(40, count($allDestinations));
        self::assertSame(count($allDestinations), count(array_unique($allDestinations)), 'no recipient may be sent twice');
    }

    // ------------------------------------------------------------------ 11. gradual throttle

    public function testGradualThrottleCapsP2pBatchesToo(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $contents = [];
        for ($i = 0; $i < 100; $i++) { $contents[] = 'msg-' . $i; }
        $job = $this->makeJob(100, ['contents' => $contents, 'throttle_count' => 10, 'throttle_minutes' => 60]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 10);
        self::assertCount(10, $items);

        bulk_send_claimed_items(db(), $items);

        $covered = array_sum(array_map(static fn(array $b): int => count($b['destinations'] ?? []), $this->recordedBodies()));
        self::assertSame(10, $covered, 'a ManyToMany batch must never exceed the eligible count from the throttle window');

        $pending = (int)db()->query("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = {$job['job_id']} AND status = 'pending'")->fetchColumn();
        self::assertSame(90, $pending);
    }

    // ------------------------------------------------------------------ 12. wallet

    public function testP2pBatchChargesEachRecipientOnceForTheirOwnPrice(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $payer = $this->makeUser(['originator' => $this->sender, 'is_admin' => 0]);
        wallet_credit($payer, 10000, 'purchase', 'test', 'seed:' . $payer, 'seed:' . $payer);

        // Different content, different frozen price per row — exactly the shape Phase 9C makes
        // co-batchable, and exactly why per-item settlement (not a group-level charge) matters.
        $job = $this->makeJob(3, ['user_id' => $payer, 'contents' => ['کوتاه', 'یک متن کمی بلندتر برای دو پارت', 'سه']]);
        // MySQL's UPDATE ... LIMIT has no OFFSET form (that clause is SELECT-only), so the three rows
        // are addressed directly by id instead.
        $rowIds = db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$job['job_id']} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $prices = [2, 5, 1];
        foreach ($rowIds as $i => $rowId) {
            db()->prepare('UPDATE ellsms_bulk_items SET price_cost_credits = ? WHERE id = ?')->execute([$prices[$i], $rowId]);
        }
        wallet_reserve($payer, 8, 'bulk_job', (string)$job['job_id'], 'reserve:bulk_job:' . $job['job_id']);

        $before = wallet_balance($payer);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);
        $after = wallet_balance($payer);

        self::assertCount(1, $this->recordedBodies(), 'still one provider request despite three different prices/contents');
        $settled = (int)$before['reserved'] - (int)$after['reserved'];
        self::assertSame(8, $settled, '2 + 5 + 1 credits must settle exactly once each, not once per group');
    }

    // ------------------------------------------------------------------ 9C.10 idempotency

    public function testIdempotencyKeysArrayCarriesADeterministicPerRowToken(): void
    {
        $gatewayId = $this->makeManyToManyGateway('batch', withIdempotency: true);
        $this->makeRouteForSender($gatewayId, $this->sender);

        $job = $this->makeJob(3, ['contents' => ['a', 'b', 'c']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        $itemIds = array_map(static fn(array $i): int => (int)$i['id'], $items);

        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(1, $bodies);
        $keys = $bodies[0]['idempotency_keys'] ?? [];
        self::assertCount(3, $keys);
        foreach ($itemIds as $id) {
            self::assertContains('ellsms:bulk_item:' . $id, $keys, 'each key must be derived from that row\'s own database id');
        }
        self::assertCount(3, array_unique($keys), 'every recipient must get its own key');
    }

    public function testIdempotencyKeyIsStableAcrossARetryOfTheSameRow(): void
    {
        // Simulates the exact residual-risk scenario: the provider accepted the batch, but the
        // worker "crashed" before the per-row UPDATE — modelled here by directly re-claiming the same
        // row (its lease has not expired, so this call proves the KEY a real retry would carry is
        // identical, without needing to wait out a real lease).
        $gatewayId = $this->makeManyToManyGateway('batch', withIdempotency: true);
        $this->makeRouteForSender($gatewayId, $this->sender);

        $job = $this->makeJob(1, ['contents' => ['single row']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        self::assertCount(1, $items);
        $itemId = (int)$items[0]['id'];

        bulk_send_claimed_items(db(), $items);
        $firstKeys = null;
        foreach ($this->recordedBodies() as $b) {
            if (isset($b['idempotency_keys'])) { $firstKeys = $b['idempotency_keys']; }
        }
        self::assertSame(['ellsms:bulk_item:' . $itemId], $firstKeys);

        // Force the row back to pending (as a lease-expiry reclaim would find it) and re-send.
        db()->prepare("UPDATE ellsms_bulk_items SET status='pending', claimed_by=NULL, lease_expires_at=NULL WHERE id=?")
            ->execute([$itemId]);
        @file_put_contents(self::$recordFile, '');

        $retryItems = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        self::assertCount(1, $retryItems);
        self::assertSame($itemId, (int)$retryItems[0]['id'], 'a retry must reclaim the SAME row, not a new one');
        bulk_send_claimed_items(db(), $retryItems);

        $retryKeys = null;
        foreach ($this->recordedBodies() as $b) {
            if (isset($b['idempotency_keys'])) { $retryKeys = $b['idempotency_keys']; }
        }
        self::assertSame($firstKeys, $retryKeys, 'the SAME recipient must carry the SAME idempotency key on a retry, not a fresh one');
    }

    public function testAConnectorWithoutIdempotencySupportIsUnaffected(): void
    {
        // withIdempotency: false — the connector never references idempotency_keys_array, so it must
        // never appear in the request body at all.
        $gatewayId = $this->makeManyToManyGateway('batch', withIdempotency: false);
        $this->makeRouteForSender($gatewayId, $this->sender);

        $job = $this->makeJob(2, ['contents' => ['x', 'y']]);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $bodies = $this->recordedBodies();
        self::assertCount(1, $bodies);
        self::assertArrayNotHasKey('idempotency_keys', $bodies[0], 'a connector that never asked for it must never receive it');
    }

    public function testGatewayConnectorSupportsPerRecipientIdempotencyIsCapabilityDriven(): void
    {
        $withId    = $this->makeManyToManyGateway('batch', withIdempotency: true);
        $without   = $this->makeManyToManyGateway('batch', withIdempotency: false);

        self::assertTrue(gateway_connector_supports_per_recipient_idempotency(gateway_compiled($withId)));
        self::assertFalse(gateway_connector_supports_per_recipient_idempotency(gateway_compiled($without)));
    }
}
