<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #40 — prohibited words: normalization, whole-word vs contains, and refusal on every send path
 * before anything is charged. Sends go to a recording gateway so the test sees what reached it.
 */
final class ContentPolicyTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900800';
    private int $userId;

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_policy_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        @unlink(self::$recordFile . '.vesal');
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SMS_GATEWAY_TRANSPORT=1');
        db()->exec('UPDATE ellsms_prohibited_words SET active = 0');
        content_policy_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        $this->makeGateway();
        $this->forbid('قمار', 'contains');
        $this->forbid('bet', 'word');
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        content_policy_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    private function forbid(string $pattern, string $type): int
    {
        db()->prepare('INSERT INTO ellsms_prohibited_words (pattern, match_type) VALUES (?,?) ON DUPLICATE KEY UPDATE active = 1')->execute([$pattern, $type]);
        content_policy_reset();
        return (int)db()->query("SELECT id FROM ellsms_prohibited_words WHERE pattern = " . db()->quote($pattern) . " AND match_type = " . db()->quote($type))->fetchColumn();
    }

    private function makeGateway(): void
    {
        $db = db();
        $code = 'policy_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)")
           ->execute([$gatewayId, self::$baseUrl . '/vesal/ManyToMany',
                      json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
                      json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'])]);
        foreach ([['destinations', 'recipients_array', 'string_array', 30], ['contents', 'messages_array', 'string_array', 40]] as [$key, $value, $dataType, $sort]) {
            $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
                            (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                          VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)")
               ->execute([$gatewayId, $key, $value, $dataType, $sort, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        $suffix = bin2hex(random_bytes(3));
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $routeId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->line, 'default', $routeId, 'active', $this->line . ':default']);
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function requests(): int
    {
        return count(array_filter(explode("\n", (string)file_get_contents(self::$recordFile))));
    }

    private function user(): array
    {
        return backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    public function testNormalizationCatchesTrivialVariants(): void
    {
        $this->assertNotNull(content_policy_violation('سایت قمار آنلاین'));
        $this->assertNotNull(content_policy_violation('سایت قمـار'), 'tatweel');
        $this->assertNotNull(content_policy_violation("ق\u{200C}مار"), 'ZWNJ inside the word');
        $this->forbid('كازينو', 'contains');
        $this->assertNotNull(content_policy_violation('کازینو'), 'Arabic ك/ي in the pattern match Persian ک/ی in the text');
        $this->assertNull(content_policy_violation('سلام، سفارش شما ارسال شد'));
    }

    public function testWholeWordDoesNotMatchInsideAnotherWord(): void
    {
        $this->assertNotNull(content_policy_violation('place your BET now'));
        $this->assertNull(content_policy_violation('the best alphabet'), '"bet" inside "alphabet"/"best" is not the word bet');
        $this->assertNotNull(content_policy_violation('قمارخانه'), '"contains" matches inside a longer word');
    }

    public function testAnInactiveRuleIsIgnored(): void
    {
        db()->exec("UPDATE ellsms_prohibited_words SET active = 0 WHERE pattern = 'قمار'");
        content_policy_reset();
        $this->assertNull(content_policy_violation('قمار'));
    }

    public function testADirectSendIsRefusedBeforeAnythingIsReservedOrSent(): void
    {
        wallet_credit($this->userId, 1000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
        $before = wallet_balance($this->userId);
        [$ok, $info, $retryable] = dispatch_message($this->user(), $this->line, ['989121260001'], 'بازی قمار');
        $this->assertFalse($ok);
        $this->assertSame(CONTENT_POLICY_ERROR, $info);
        $this->assertFalse($retryable);
        $this->assertSame(0, $this->requests());
        $this->assertSame($before, wallet_balance($this->userId));
    }

    public function testAScheduledOrAutoReplySendIsAPermanentRefusal(): void
    {
        [$ok, $info, $retryable] = dispatch_message_retryable($this->user(), $this->line, ['989121260002'], 'قمار', 'schedule', 'policy-1', 1);
        $this->assertFalse($ok);
        $this->assertSame(CONTENT_POLICY_ERROR, $info);
        $this->assertFalse($retryable, 'retrying cannot make the text acceptable');
    }

    public function testTheRawDispatchSafetyNetRefusesToo(): void
    {
        [$ok, $info] = dispatch_message_raw($this->user(), $this->line, ['989121260003'], 'قمار');
        $this->assertFalse($ok);
        $this->assertSame(CONTENT_POLICY_ERROR, $info);
        $this->assertSame(0, $this->requests());
    }

    public function testQueueingABulkJobWithAProhibitedRowIsRefusedWithTheRowCount(): void
    {
        $result = bulk_queue_job($this->user(), 'p2p', 'policy', $this->line, null, [
            ['mobile' => '989121260004', 'content' => 'سلام'],
            ['mobile' => '989121260005', 'content' => 'قمار'],
            ['mobile' => '989121260006', 'content' => 'bet today'],
        ]);
        $this->assertFalse($result[0]);
        $this->assertStringContainsString('۲', $result[1]);
        $this->assertSame('content_prohibited', $result[3] ?? null);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_bulk_jobs WHERE title = 'policy' AND user_id = " . $this->userId)->fetchColumn());
    }

    public function testABulkRowWithProhibitedTextFailsWithAReasonAndTheRestSend(): void
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, title, originator, total_rows, status) VALUES (?, 'policy-run', ?, 2, 'processing')")
           ->execute([$this->userId, $this->line]);
        $jobId = (int)$db->lastInsertId();
        foreach ([['989121260007', 'قمار'], ['989121260008', 'سلام']] as [$mobile, $text]) {
            $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?,?,?, 'pending')")->execute([$jobId, $mobile, $text]);
        }
        bulk_send_claimed_items($db, bulk_claim_items($db, 'j.id = ?', [$jobId], 100));

        $rows = $db->query('SELECT mobile, status, error FROM ellsms_bulk_items WHERE job_id = ' . $jobId . ' ORDER BY id')->fetchAll();
        $this->assertSame(['failed', CONTENT_POLICY_ERROR], [$rows[0]['status'], $rows[0]['error']]);
        $this->assertSame('sent', $rows[1]['status']);
        $body = json_decode((string)json_decode((string)file_get_contents(self::$recordFile), true)['body'], true);
        $this->assertSame(['989121260008'], $body['destinations']);
    }
}
