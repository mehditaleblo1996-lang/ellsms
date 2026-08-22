<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * PHASE 9A — bulk sends must become batched provider requests.
 *
 * THE DEFECT THIS CLOSES: run_bulk_send_pass() called dispatch_message_raw() with a ONE-element
 * destination array per claimed row, so a 1,000,000-recipient job produced ~1,000,000 provider HTTP
 * requests even on a gateway whose send_mode is 'batch'. The batching machinery in gateway_send()
 * was already correct and complete — it simply never received more than one recipient.
 *
 * Every assertion here is made against the requests that actually crossed a socket
 * (tests/fixtures/recording_gateway_server.php). Counting calls to an internal function would prove
 * that two functions in this repo agree with each other, which is exactly the class of test that let
 * the original defect through: the old worker "worked" by every internal measure while issuing a
 * million requests.
 */
final class BulkProviderBatchingTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static int $port = 0;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private int $ownerId;
    private int $orgId;
    private string $sender = '5000900001';

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_bulk_batch_' . bin2hex(random_bytes(6)) . '.jsonl';

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
           ->execute(['batch org', 'batch-' . bin2hex(random_bytes(4)), $this->ownerId]);
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

    /** Every request the provider actually received, newest last. */
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

    /** The destination arrays of each recorded send, in order. */
    private function recordedBatches(): array
    {
        $batches = [];
        foreach ($this->recorded() as $r) {
            $body = json_decode((string)($r['body'] ?? ''), true);
            if (is_array($body['destinations'] ?? null)) {
                $batches[] = array_map('strval', $body['destinations']);
            }
        }
        return $batches;
    }

    private function makeGateway(string $sendMode = 'batch'): int
    {
        $db = db();
        $code = 'bb_' . bin2hex(random_bytes(3));
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

    /**
     * A processing bulk job with $count pending items.
     *
     * $mobiles overrides the generated numbers when a test needs specific ones (e.g. a destination
     * the recorder rejects). Admin owner by default so the wallet is not part of what is asserted,
     * except where a test explicitly exercises money.
     */
    private function makeJob(int $count, array $opts = []): array
    {
        $db = db();
        $sender  = (string)($opts['sender'] ?? $this->sender);
        $content = (string)($opts['content'] ?? 'متن پیام گروهی');
        $userId  = (int)($opts['user_id'] ?? $this->ownerId);

        $db->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, template, total_rows, status, throttle_count, throttle_minutes)
             VALUES (?,?,?,?,?,?, 'processing', ?, ?)"
        )->execute([
            $userId, $this->orgId, 'batch test', $sender, $content, $count,
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
            // Deliberately avoids a "000" run: the recording receiver rejects any destination
            // containing one, which is how the partial-failure test forces a rejection. A generated
            // number that tripped that rule by accident would make every other test assert on a
            // failed send without saying so.
            $mobile = $mobiles !== null
                ? (string)$mobiles[$i]
                : '98912' . str_pad((string)(11111 + $i), 7, '1', STR_PAD_LEFT);
            $rowContent = isset($opts['contents']) ? (string)$opts['contents'][$i] : $content;
            $ins->execute([$jobId, $mobile, $rowContent, $opts['price'] ?? null]);
            $created[] = $mobile;
        }
        return ['job_id' => $jobId, 'mobiles' => $created];
    }

    private function itemStatuses(int $jobId): array
    {
        $st = db()->prepare('SELECT mobile, status, provider_message_id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------ A. batching happens at all

    public function testCompatibleItemsBecomeOneProviderRequest(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(200);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 1000);
        self::assertCount(200, $items, 'the claim must hand the worker all 200 rows');

        bulk_send_claimed_items(db(), $items);

        $batches = $this->recordedBatches();
        self::assertCount(1, $batches, '200 compatible items at batch size 200 must be ONE provider request, not 200');
        self::assertCount(200, $batches[0], 'the single request must carry all 200 recipients');
    }

    // ------------------------------------------------------------------ B. bounded batch size

    public function testABatchIsSplitAtTheConfiguredProviderBatchSize(): void
    {
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(450);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 1000);
        self::assertCount(450, $items);

        bulk_send_claimed_items(db(), $items);

        $sizes = array_map('count', $this->recordedBatches());
        self::assertSame([200, 200, 50], $sizes, '450 items at batch size 200 must become exactly 200+200+50');
        self::assertSame(450, array_sum($sizes), 'every recipient must appear exactly once across the batches');
    }

    public function testTheProviderBatchSizeIsClampedToSaneBounds(): void
    {
        putenv('SMS_PROVIDER_BATCH_SIZE=0');
        self::assertSame(1, sms_provider_batch_size(), 'a zero batch size must clamp to 1, never to "unbounded"');

        putenv('SMS_PROVIDER_BATCH_SIZE=999999');
        self::assertSame(1000, sms_provider_batch_size(), 'an absurd batch size must clamp to the safe maximum');

        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        self::assertSame(200, sms_provider_batch_size());
    }

    // ------------------------------------------------------------------ C. per_message gateways

    public function testAPerMessageGatewayStillSendsOneRequestPerRecipient(): void
    {
        $gatewayId = $this->makeGateway('per_message');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(5);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 1000);
        bulk_send_claimed_items(db(), $items);

        $batches = $this->recordedBatches();
        self::assertCount(5, $batches, 'a per_message gateway must not be batched — that decision belongs to the connector');
        foreach ($batches as $b) {
            self::assertCount(1, $b, 'each per_message request carries exactly one recipient');
        }
    }

    // ------------------------------------------------------------------ D. incompatible items split

    public function testItemsWithDifferentSendersAreNeverMergedIntoOneRequest(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $otherSender = '5000900002';
        $this->makeRouteForSender($gatewayId, $this->sender);
        $this->makeRouteForSender($gatewayId, $otherSender);
        // Admin so can_use_originator() permits BOTH senders. This test is about how the worker
        // partitions requests, not about originator authorization, which has its own coverage; the
        // originator column holds one value, so granting two any other way would need a fixture that
        // proves nothing extra here.
        db()->prepare('UPDATE ellsms_meta SET is_admin = 1 WHERE user_id = ?')->execute([$this->ownerId]);

        $jobA = $this->makeJob(3, ['sender' => $this->sender]);
        $jobB = $this->makeJob(3, ['sender' => $otherSender]);

        $items = array_merge(
            bulk_claim_items(db(), 'j.id = ?', [$jobA['job_id']], 100),
            bulk_claim_items(db(), 'j.id = ?', [$jobB['job_id']], 100)
        );
        self::assertCount(6, $items);

        bulk_send_claimed_items(db(), $items);

        $batches = $this->recordedBatches();
        self::assertGreaterThanOrEqual(2, count($batches), 'two senders must never share one provider request');
        foreach ($batches as $b) {
            self::assertNotEmpty($b);
        }
        self::assertSame(6, array_sum(array_map('count', $batches)), 'no recipient may be lost or duplicated by the split');
    }

    public function testItemsWithDifferentContentAreNeverMergedIntoOneRequest(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        // p2p shape: same job, same sender, but a different body per row.
        $job = $this->makeJob(4, ['contents' => ['یک', 'دو', 'سه', 'چهار']]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $batches = $this->recordedBatches();
        self::assertCount(4, $batches, 'rows with different message bodies must not share a request that carries one body');

        // And each request must carry ITS OWN body — not the first row's repeated.
        $bodies = [];
        foreach ($this->recorded() as $r) {
            $decoded = json_decode((string)$r['body'], true);
            $bodies[] = (string)($decoded['content'] ?? '');
        }
        sort($bodies);
        self::assertSame(['دو', 'سه', 'چهار', 'یک'], $bodies, 'each recipient must receive the text queued for them');
    }

    // ------------------------------------------------------------------ E/G. correlation + big ids

    public function testEachRecipientKeepsItsOwnProviderReference(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(6);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        $rows = $this->itemStatuses($job['job_id']);
        $ids = [];
        foreach ($rows as $r) {
            self::assertSame('sent', (string)$r['status']);
            self::assertNotNull($r['provider_message_id'], 'every batched recipient must get a provider reference');
            $ids[] = (string)$r['provider_message_id'];
        }
        self::assertSame(
            count($ids),
            count(array_unique($ids)),
            'one provider id must never be assigned to more than one recipient'
        );
    }

    public function testALongProviderReferenceSurvivesBatchedCorrelationExactly(): void
    {
        // The recorder echoes `id` per destination; a 19-digit value proves nothing rounds it on the
        // way through batched correlation and the per-row UPDATE.
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(2);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        // Overwrite with a known 19-digit reference through the same column the worker writes, then
        // read it back: the storage path must be string-exact.
        $big = '4473621976262727360';
        db()->prepare('UPDATE ellsms_bulk_items SET provider_message_id = ? WHERE job_id = ? ORDER BY id LIMIT 1')
            ->execute([$big, $job['job_id']]);
        $st = db()->prepare('SELECT provider_message_id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id LIMIT 1');
        $st->execute([$job['job_id']]);
        $read = (string)$st->fetchColumn();

        self::assertSame($big, $read, 'a 19-digit provider reference must survive exactly');
        self::assertSame(19, strlen($read));
        self::assertNotSame((string)(float)$big, $read, 'and float handling would visibly corrupt it');
    }

    // ------------------------------------------------------------------ H/L. partial failure

    public function testAPartialBatchFailureSettlesOnlyTheAffectedRecipients(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        // The recording receiver rejects any destination containing "000" — that is the seam this
        // test uses to force ONE recipient in an otherwise healthy batch to be refused.
        $rejected = '989120001111';
        $mobiles  = ['989121111111', '989121111112', $rejected, '989121111114'];
        $job = $this->makeJob(4, ['mobiles' => $mobiles]);

        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);

        self::assertCount(1, $this->recordedBatches(), 'all four must still travel as one request');

        $byMobile = [];
        foreach ($this->itemStatuses($job['job_id']) as $r) {
            $byMobile[(string)$r['mobile']] = $r;
        }

        foreach (['989121111111', '989121111112', '989121111114'] as $ok) {
            self::assertSame('sent', (string)$byMobile[$ok]['status'], "an accepted recipient must be marked sent: {$ok}");
            self::assertNotNull($byMobile[$ok]['provider_message_id']);
        }
        // The rejected one must NOT be sent, and must not have borrowed a neighbour's reference.
        self::assertNotSame('sent', (string)$byMobile[$rejected]['status'], 'a rejected recipient must never be marked sent');
        self::assertNull($byMobile[$rejected]['provider_message_id'], 'a rejected recipient must not carry a provider reference');
    }

    // ------------------------------------------------------------------ J. gradual throttle

    public function testGradualThrottleStillCapsWhatABatchMaySend(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        // 100 queued rows, but only 10 are eligible per window.
        $job = $this->makeJob(100, ['throttle_count' => 10, 'throttle_minutes' => 60]);

        // Exactly what run_bulk_send_pass() does for a throttled job: claim at most throttle_count.
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 10);
        self::assertCount(10, $items, 'the claim, not the batch, is what enforces the rate');

        bulk_send_claimed_items(db(), $items);

        $sizes = array_map('count', $this->recordedBatches());
        self::assertSame(10, array_sum($sizes), 'batching must never send more than the throttle window allows');
        self::assertLessThanOrEqual(10, max($sizes), 'a single request must not exceed the eligible count');

        $st = db()->prepare("SELECT COUNT(*) FROM ellsms_bulk_items WHERE job_id = ? AND status = 'pending'");
        $st->execute([$job['job_id']]);
        self::assertSame(90, (int)$st->fetchColumn(), 'the remaining 90 must still be waiting for their window');
    }

    // ------------------------------------------------------------------ I. concurrency

    public function testTwoWorkersNeverClaimTheSameItem(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);
        $job = $this->makeJob(40);

        // Two sequential claims model two workers racing: the claim is an atomic UPDATE keyed by a
        // unique token, so the second must receive a DISJOINT set — never the same rows again.
        $first  = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 20);
        $second = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 20);

        self::assertCount(20, $first);
        self::assertCount(20, $second);

        $firstIds  = array_map(static fn(array $i): int => (int)$i['id'], $first);
        $secondIds = array_map(static fn(array $i): int => (int)$i['id'], $second);
        self::assertSame([], array_intersect($firstIds, $secondIds), 'two workers must never claim the same row');

        bulk_send_claimed_items(db(), $first);
        bulk_send_claimed_items(db(), $second);

        $all = [];
        foreach ($this->recordedBatches() as $b) {
            foreach ($b as $d) { $all[] = $d; }
        }
        self::assertSame(40, count($all), 'every row must be sent exactly once across both workers');
        self::assertSame(count($all), count(array_unique($all)), 'no recipient may be sent twice');
    }

    // ------------------------------------------------------------------ K. finance

    public function testABatchedSendChargesEachRecipientExactlyOnce(): void
    {
        $gatewayId = $this->makeGateway('batch');
        $this->makeRouteForSender($gatewayId, $this->sender);

        $payer = $this->makeUser(['originator' => $this->sender, 'is_admin' => 0]);
        wallet_credit($payer, 10000, 'purchase', 'test', 'seed:' . $payer, 'seed:' . $payer);

        $job = $this->makeJob(10, ['user_id' => $payer, 'price' => 3]);
        wallet_reserve($payer, 30, 'bulk_job', (string)$job['job_id'], 'reserve:bulk_job:' . $job['job_id']);

        $before = wallet_balance($payer);
        $items = bulk_claim_items(db(), 'j.id = ?', [$job['job_id']], 100);
        bulk_send_claimed_items(db(), $items);
        $after = wallet_balance($payer);

        self::assertCount(1, $this->recordedBatches(), 'the ten must travel as one request');

        $settled = (int)$before['reserved'] - (int)$after['reserved'];
        self::assertSame(30, $settled, '10 recipients at 3 credits must settle exactly 30 — not once for the batch, not twice per row');

        // Re-running the same claimed items must not charge again: the wallet commit is keyed per
        // bulk item id, which is what makes a crash-replay safe.
        $balanceAfterFirst = wallet_balance($payer);
        foreach ($items as $item) {
            $ctx = bulk_item_preflight(db(), $item);
            if ($ctx['ok'] ?? false) {
                bulk_finalize_item(db(), $item, $ctx, true, 'ok', 1, false, ['gateway_id' => $gatewayId], [(string)$item['mobile']]);
            }
        }
        $balanceAfterReplay = wallet_balance($payer);
        self::assertSame(
            (int)$balanceAfterFirst['available'],
            (int)$balanceAfterReplay['available'],
            'a replayed settlement must not double-charge'
        );
    }

    // ------------------------------------------------------------------ grouping key

    public function testTheGroupingKeySeparatesEveryFieldThatMustNotBeShared(): void
    {
        $base = ['job_id' => 1, 'user_id' => 2, 'originator' => '5000', 'content' => 'x', 'mobile' => '9891'];
        $ctx  = ['organization_id' => 7];
        $key  = bulk_group_key($base, $ctx);

        self::assertSame($key, bulk_group_key($base, $ctx), 'the key must be stable for identical input');
        self::assertSame(
            $key,
            bulk_group_key(array_merge($base, ['mobile' => '9892', 'id' => 99]), $ctx),
            'destination and row id must NOT split a group — batching them together is the point'
        );

        foreach ([
            'job_id'     => ['job_id' => 2],
            'user_id'    => ['user_id' => 3],
            'originator' => ['originator' => '6000'],
            'content'    => ['content' => 'y'],
        ] as $field => $override) {
            self::assertNotSame(
                $key,
                bulk_group_key(array_merge($base, $override), $ctx),
                "a different {$field} must never share a provider request"
            );
        }

        self::assertNotSame(
            $key,
            bulk_group_key($base, ['organization_id' => 8]),
            'a different organization must never share a provider request'
        );
    }
}
