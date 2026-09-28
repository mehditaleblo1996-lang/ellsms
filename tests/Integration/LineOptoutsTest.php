<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #38 — "11" opts a recipient out of ONE line, "12" back in; every send path then skips them from that
 * line without charging. Sends go to a recording gateway so the test sees exactly which recipients
 * reached the provider.
 */
final class LineOptoutsTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900500';
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_optout_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        line_optout_settings_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        $this->makeGateway();
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        line_optout_settings_reset();
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    private function makeGateway(): void
    {
        $db = db();
        $code = 'optout_' . bin2hex(random_bytes(3));
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

    /** Recipients that received $text (the opt-out confirmation SMS goes through the same gateway). */
    private function sentTo(string $text): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            if (($r['path'] ?? '') !== '/vesal/ManyToMany') continue;
            $body = json_decode((string)$r['body'], true);
            foreach ($body['destinations'] ?? [] as $i => $destination) {
                if (($body['contents'][$i] ?? null) === $text) $out[] = $destination;
            }
        }
        return $out;
    }

    public function testTheOptOutIsConfirmedBySmsFromTheSameLine(): void
    {
        line_optout_process_inbound($this->inbound('09121230015', $this->line, '11'));
        $expected = strtr(LINE_OPTOUT_STOP_TEXT_DEFAULT, ['{line}' => to_persian_digits($this->line)]);
        $this->assertSame(['989121230015'], $this->sentTo($expected));
        $st = db()->prepare("SELECT confirmed FROM ellsms_line_optout_events WHERE mobile = '989121230015'");
        $st->execute();
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    private function inbound(string $from, string $to, string $content): array
    {
        db()->prepare('INSERT INTO inbound_message (originator, destination, content) VALUES (?,?,?)')->execute([$from, $to, $content]);
        return ['id' => (int)db()->lastInsertId(), 'originator' => $from, 'destination' => $to, 'content' => $content];
    }

    private function user(): array
    {
        return backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    public function testKeywordsAreRecognisedInAnyDigitScript(): void
    {
        foreach (['11', ' ۱۱ ', '١١', "1\u{200C}1"] as $text) {
            $this->assertSame('stop', line_optout_keyword_action($text), "'{$text}'");
        }
        $this->assertSame('start', line_optout_keyword_action('۱۲'));
        $this->assertNull(line_optout_keyword_action('111'));
        $this->assertNull(line_optout_keyword_action('سلام 11'));
    }

    public function testElevenOptsOutOfThatLineOnlyAndTwelveOptsBackIn(): void
    {
        $this->assertSame('stop', line_optout_process_inbound($this->inbound('09121230001', $this->line, '11')));
        $this->assertSame(['989121230001' => true], line_optout_filter($this->line, ['989121230001', '989121230002']));
        $this->assertSame([], line_optout_filter('5000900599', ['989121230001']), 'another line is unaffected');

        $this->assertSame('start', line_optout_process_inbound($this->inbound('09121230001', $this->line, '12')));
        $this->assertSame([], line_optout_filter($this->line, ['989121230001']));
    }

    public function testTheSameInboundMessageIsAppliedOnlyOnce(): void
    {
        $msg = $this->inbound('09121230003', $this->line, '11');
        $this->assertSame('stop', line_optout_process_inbound($msg));
        line_optout_process_inbound($this->inbound('09121230003', $this->line, '12'));
        $this->assertNull(line_optout_process_inbound($msg), 'replaying an already-handled "11" must not re-opt-out');
        $this->assertSame([], line_optout_filter($this->line, ['989121230003']));
    }

    public function testADirectSendSkipsOptedOutRecipientsAndSendsTheRest(): void
    {
        line_optout_process_inbound($this->inbound('09121230004', $this->line, '11'));
        [$ok, , , , , , $sent] = dispatch_message_raw($this->user(), $this->line, ['989121230004', '989121230005'], 'hello');
        $this->assertTrue($ok);
        $this->assertSame(['989121230005'], $this->sentTo('hello'), 'the opted-out number never reaches the provider');
        $this->assertSame(['989121230005'], $sent, 'and is not among the accepted (charged) recipients');
    }

    public function testASendOnlyToOptedOutRecipientsDoesNotReachTheProvider(): void
    {
        line_optout_process_inbound($this->inbound('09121230006', $this->line, '11'));
        [$ok, $info] = dispatch_message_raw($this->user(), $this->line, ['989121230006'], 'hello');
        $this->assertFalse($ok);
        $this->assertSame(LINE_OPTOUT_ERROR, $info);
        $this->assertSame([], $this->sentTo('hello'));
    }

    public function testAnOtpStillReachesAnOptedOutNumber(): void
    {
        line_optout_process_inbound($this->inbound('09121230007', $this->line, '11'));
        dispatch_message_raw($this->user(), $this->line, ['989121230007'], 'code 1234', null, true, null, null, 'otp');
        $this->assertSame(['989121230007'], $this->sentTo('code 1234'));
    }

    public function testBulkItemsOfOptedOutRecipientsFailWithAReasonAndTheRestSend(): void
    {
        line_optout_process_inbound($this->inbound('09121230008', $this->line, '11'));
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, title, originator, total_rows, status) VALUES (?, 'optout', ?, 3, 'processing')")
           ->execute([$this->userId, $this->line]);
        $jobId = (int)$db->lastInsertId();
        foreach (['989121230008', '989121230009', '989121230010'] as $mobile) {
            $db->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status) VALUES (?,?, 'hi', 'pending')")->execute([$jobId, $mobile]);
        }
        bulk_send_claimed_items($db, bulk_claim_items($db, 'j.id = ?', [$jobId], 100));

        $this->assertSame(['989121230009', '989121230010'], $this->sentTo('hi'));
        $st = $db->prepare('SELECT mobile, status, error FROM ellsms_bulk_items WHERE job_id = ? ORDER BY id');
        $st->execute([$jobId]);
        $rows = $st->fetchAll();
        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame(LINE_OPTOUT_ERROR, $rows[0]['error']);
        $this->assertSame('sent', $rows[1]['status']);
        $counts = $db->query('SELECT sent_rows, failed_rows FROM ellsms_bulk_jobs WHERE id = ' . $jobId)->fetch();
        $this->assertSame([2, 1], [(int)$counts['sent_rows'], (int)$counts['failed_rows']]);
    }

    public function testTheAutoReplyPassRecordsOptOutsFromBothInboundStores(): void
    {
        set_setting('autoreply_last_inbound_id', (string)(int)db()->query('SELECT COALESCE(MAX(id),0) FROM inbound_message')->fetchColumn());
        set_setting('autoreply_last_gateway_inbound_id', (string)(int)db()->query('SELECT COALESCE(MAX(id),0) FROM ellsms_inbound_messages')->fetchColumn());
        $this->inbound('09121230011', $this->line, '11');
        $gatewayId = (int)db()->query('SELECT MAX(id) FROM ellsms_sms_gateways')->fetchColumn();
        db()->prepare("INSERT INTO ellsms_inbound_messages (gateway_id, originator, destination, content, received_at, dedupe_key) VALUES (?,?,?,?,NOW(),?)")
            ->execute([$gatewayId, '989121230012', $this->line, '۱۱', hash('sha256', random_bytes(8))]);

        run_autoreply_pass();

        $this->assertSame(['989121230011' => true, '989121230012' => true], line_optout_filter($this->line, ['989121230011', '989121230012']));
    }

    public function testNothingIsFilteredWhenTheFeatureIsOff(): void
    {
        line_optout_process_inbound($this->inbound('09121230013', $this->line, '11'));
        set_setting('line_optout_enabled', '0');
        line_optout_settings_reset();
        $this->assertSame([], line_optout_filter($this->line, ['989121230013']));
        $this->assertNull(line_optout_process_inbound($this->inbound('09121230014', $this->line, '11')));
    }
}
