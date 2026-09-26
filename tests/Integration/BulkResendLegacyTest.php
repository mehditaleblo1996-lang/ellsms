<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__, 2) . '/app/BulkFastWorker.php';
require_once dirname(__DIR__, 2) . '/app/bulk_resend.php';

/**
 * cron/bulk-resend-legacy.php: bulk rows that went out through the legacy backend API (marked 'sent'
 * with no gateway_id / provider id) are queued again and sent through the sender's gateway — each row
 * with its OWN stored text (smart job), nobody already reached through a gateway gets it twice, and
 * the owner is not charged a second time.
 */
final class BulkResendLegacyTest extends IntegrationTestCase
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_resend_legacy_' . bin2hex(random_bytes(6)) . '.jsonl';

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
           ->execute(['resend org', 'resend-' . bin2hex(random_bytes(4)), $this->ownerId]);
        $this->orgId = (int)$db->lastInsertId();
        \wallet_credit($this->ownerId, 100000, 'purchase', 'test', 'seed:' . $this->ownerId, 'test:credit:' . $this->ownerId);

        putenv('SMS_GATEWAY_TRANSPORT=1');
        putenv('APP_ENV=testing');
        putenv('SMS_PROVIDER_BATCH_SIZE=200');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('SMS_PROVIDER_BATCH_SIZE');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    public function testLegacyRowsAreResentThroughTheGatewayWithTheirOwnTextAndNoSecondCharge(): void
    {
        $this->makeGatewayAndRoute();
        $db = db();

        // A finished smart job: three rows with their own text, all sent via the legacy API and
        // already paid for (3 credits each), reservation released at the end.
        $mobiles = ['989131112221', '989131112222', '989131112223'];
        $texts = ['شما 3 شانس دارید', 'شما 7 شانس دارید', 'شما 12 شانس دارید'];
        $jobId = $this->makeJob('done', 3);
        $itemIds = [];
        foreach ($mobiles as $i => $mobile) {
            $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, price_cost_credits) VALUES (?,?,?, 'sent', 3)")
               ->execute([$jobId, $mobile, $texts[$i]]);
            $itemIds[] = (int)$db->lastInsertId();
        }
        \wallet_reserve($this->ownerId, 9, 'bulk_job', (string)$jobId, 'test:reserve:' . $jobId);
        foreach ($itemIds as $id) {
            \wallet_commit_reservation('bulk_job', (string)$jobId, 3, 'commit:bulk_item:' . $id);
        }
        \wallet_release_reservation('bulk_job', (string)$jobId);

        // The first mobile already got exactly this text through a gateway in another job.
        $otherJob = $this->makeJob('done', 1);
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, provider_message_id) VALUES (?,?,?, 'sent', 1, 'pm-x')")
           ->execute([$otherJob, $mobiles[0], $texts[0]]);

        $candidates = $db->prepare("SELECT id, mobile, content FROM ellsms_bulk_items WHERE job_id = ? AND status='sent' AND gateway_id IS NULL AND provider_message_id IS NULL");
        $candidates->execute([$jobId]);
        $rows = bulk_resend_drop_gateway_sent($db, $candidates->fetchAll());
        self::assertSame([$mobiles[1], $mobiles[2]], array_column($rows, 'mobile'), 'the gateway-reached mobile is not resent');

        $balanceBefore = wallet_balance($this->ownerId);
        self::assertSame(2, bulk_resend_queue_rows($db, $jobId, array_map('intval', array_column($rows, 'id'))));

        $job = $db->query("SELECT status, sent_rows FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetch();
        self::assertSame('processing', $job['status']);
        self::assertSame(1, (int)$job['sent_rows']);

        $items = bulk_claim_items($db, 'j.id = ?', [$jobId], 1000);
        self::assertCount(2, $items);
        bulk_send_claimed_items_fast($db, $items);

        $sentTexts = [];
        foreach ($this->recordedBodies() as $body) {
            foreach ($body['destinations'] ?? [] as $i => $destination) {
                $sentTexts[$destination] = $body['contents'][$i] ?? null;
            }
        }
        self::assertSame([$mobiles[1] => $texts[1], $mobiles[2] => $texts[2]], $sentTexts, 'each recipient gets its own text');

        $st = $db->prepare('SELECT mobile, status, gateway_id FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        foreach ($st->fetchAll() as $row) {
            self::assertSame('sent', $row['status']);
            if ($row['mobile'] !== $mobiles[0]) {
                self::assertNotNull($row['gateway_id'], 'resent rows now carry the gateway');
            }
        }
        self::assertSame(3, (int)$db->query("SELECT sent_rows FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn());
        self::assertSame($balanceBefore, wallet_balance($this->ownerId), 'the resend charges nothing again');
    }

    public function testRowsAlreadySentThroughAGatewayAreNeverRequeued(): void
    {
        $db = db();
        $jobId = $this->makeJob('done', 1);
        $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, gateway_id, provider_message_id) VALUES (?,?,?, 'sent', 1, 'pm-1')")
           ->execute([$jobId, '989131112229', 'x']);
        $id = (int)$db->lastInsertId();

        self::assertSame(0, bulk_resend_queue_rows($db, $jobId, [$id]));
        self::assertSame('sent', $db->query("SELECT status FROM ellsms_bulk_items WHERE id = {$id}")->fetchColumn());
        self::assertSame('done', $db->query("SELECT status FROM ellsms_bulk_jobs WHERE id = {$jobId}")->fetchColumn());
    }

    private function makeJob(string $status, int $sentRows): int
    {
        db()->prepare(
            "INSERT INTO ellsms_bulk_jobs (user_id, organization_id, title, originator, template, total_rows, sent_rows, status, type)
             VALUES (?,?,?,?,NULL,?,?,?, 'smart')"
        )->execute([$this->ownerId, $this->orgId, 'resend test', $this->sender, $sentRows, $sentRows, $status]);
        return (int)db()->lastInsertId();
    }

    private function makeGatewayAndRoute(): void
    {
        $db = db();
        $code = 'rs_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
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
        foreach ([['destinations', 'recipients_array', 30], ['contents', 'messages_array', 40]] as [$key, $value, $sort]) {
            $db->prepare(
                "INSERT INTO ellsms_sms_gateway_parameters
                   (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                 VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, 'string_array', 'active', ?, ?)"
            )->execute([$gatewayId, $key, $value, $sort, "{$gatewayId}:send:body:gateway::{$key}"]);
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
    }

    private function recordedBodies(): array
    {
        $bodies = [];
        foreach (array_filter(explode("\n", (string)@file_get_contents(self::$recordFile))) as $line) {
            $record = json_decode($line, true);
            $body = is_array($record) ? json_decode((string)($record['body'] ?? ''), true) : null;
            if (is_array($body)) {
                $bodies[] = $body;
            }
        }
        return $bodies;
    }
}
