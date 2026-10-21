<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Handlers/WebEngage.php';

/**
 * WebEngage SSP integration (app/WebEngage.php, app/Api/Handlers/WebEngage.php), end to end against the
 * real schema: a message sent through a gateway, its delivery report, and the DSN that report produces,
 * posted to a recording receiver standing in for WebEngage's tracking endpoint.
 */
final class WebEngageTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900944';
    private int $userId;
    private int $organizationId;
    private array $principal;

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_webengage_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(str_repeat('k', 32)));
        putenv('WEBHOOK_REQUIRE_HTTPS=0');
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');

        $this->userId = $this->makeUser(['originator' => $this->line]);
        $this->organizationId = (int)create_organization($this->userId, 'WebEngage Test Org')['organization_id'];
        wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
        $gatewayId = $this->makeGateway('/vesal/webengage-gw');
        $suffix = bin2hex(random_bytes(3));
        $db = db();
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $gatewayId]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->line, 'default', (int)$db->lastInsertId(), 'active', $this->line . ':default']);
        $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$this->line, 'test', $this->userId]);

        $key = api_key_create($this->organizationId, $this->userId, 'webengage', [\ApiScopes::MESSAGES_SEND]);
        $this->principal = api_key_authenticate($key['raw_key']);
        $this->saveSettings(['enabled' => true, 'region' => 'custom', 'url' => self::$baseUrl . '/webengage/dsn', 'token' => 'we-dsn-token']);
        $this->resetCaches();
    }

    protected function tearDown(): void
    {
        foreach (['SMS_GATEWAY_TRANSPORT', 'WEBHOOK_MASTER_KEY', 'WEBHOOK_REQUIRE_HTTPS', 'WEBHOOK_ALLOW_PRIVATE_TARGETS', 'WEBENGAGE_DSN_MAX_ATTEMPTS'] as $name) {
            putenv($name);
        }
        $this->resetCaches();
        parent::tearDown();
    }

    private function resetCaches(): void
    {
        sms_pricing_cache_reset();
        gateway_cache_reset();
        webengage_enabled_cache_reset();
    }

    private function saveSettings(array $input): void
    {
        $saved = webengage_settings_save($this->organizationId, $this->userId, $input);
        $this->assertTrue($saved['ok'], (string)$saved['reason']);
    }

    private function makeGateway(string $path): int
    {
        $db = db();
        $code = 'wegw_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)")
           ->execute([$gatewayId, self::$baseUrl . $path,
                      json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
                      json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'])]);
        foreach ([['destinations', 'recipients_array', 'string_array', 30], ['contents', 'messages_array', 'string_array', 40]] as [$key, $value, $dataType, $sort]) {
            $db->prepare("INSERT INTO ellsms_sms_gateway_parameters
                            (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
                          VALUES (?, 'send', 'body', 'gateway', NULL, ?, 'variable', ?, ?, 'active', ?, ?)")
               ->execute([$gatewayId, $key, $value, $dataType, $sort, "{$gatewayId}:send:body:gateway::{$key}"]);
        }
        return $gatewayId;
    }

    /** @return list<array> recorded requests to $path */
    private function recorded(string $path): array
    {
        $rows = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $row = json_decode($line, true);
            if (($row['path'] ?? '') === $path) $rows[] = $row;
        }
        return $rows;
    }

    private function payload(string $messageId, array $sms = []): array
    {
        return [
            'version'  => '1.0',
            'smsData'  => $sms + ['toNumber' => '+989121230001', 'fromNumber' => $this->line, 'body' => 'کد تخفیف شما: ۱۲۳'],
            'metadata' => ['campaignType' => 'PROMOTIONAL', 'timestamp' => '2026-10-21T10:00:00+0330', 'messageId' => $messageId],
        ];
    }

    /** @return array{0:int, 1:array} [http status, decoded body] */
    private function call(array $body): array
    {
        http_response_code(200);
        ob_start();
        api_handle_webengage_sms(['principal' => $this->principal, 'params' => [], 'query' => [], 'body' => $body, 'raw' => json_encode($body)]);
        $out = (string)ob_get_clean();
        return [(int)http_response_code(), json_decode($out, true) ?? []];
    }

    private function row(string $messageId): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_webengage_messages WHERE organization_id = ? AND we_message_id = ?');
        $st->execute([$this->organizationId, $messageId]);
        return $st->fetch() ?: [];
    }

    public function testAcceptedMessageWaitsForItsDeliveryReportThenSendsTheDsn(): void
    {
        [$status, $body] = $this->call($this->payload('we-1'));
        $this->assertSame(200, $status);
        $this->assertSame(['status' => 'sms_accepted'], $body);
        $this->assertCount(1, $this->recorded('/vesal/webengage-gw'), 'one provider call');

        $row = $this->row('we-1');
        $this->assertSame('accepted', $row['status']);
        $this->assertSame('waiting', $row['dsn_status'], 'a gateway send waits for the real report');
        $this->assertNotNull($row['attempt_id']);
        $this->assertSame($this->line, $row['originator']);

        // Nothing is due yet.
        $this->assertSame(0, webengage_dsn_run_pass(10));
        $this->assertCount(0, $this->recorded('/webengage/dsn'));

        $attempt = db()->query('SELECT id, delivery_status FROM ellsms_message_attempts WHERE id = ' . (int)$row['attempt_id'])->fetch();
        $this->assertTrue(gateway_status_record('attempt', (int)$attempt['id'], $attempt['delivery_status'], 'delivered', date('Y-m-d H:i:s'), 'DELIVRD'));
        $this->assertSame('pending', $this->row('we-1')['dsn_status']);

        $this->assertSame(1, webengage_dsn_run_pass(10));
        $dsn = $this->recorded('/webengage/dsn');
        $this->assertCount(1, $dsn);
        $this->assertSame('Bearer we-dsn-token', $dsn[0]['headers']['Authorization'] ?? null);
        $this->assertSame([
            'version' => '1.0', 'messageId' => 'we-1', 'toNumber' => '+989121230001',
            'status' => 'sms_sent', 'statusCode' => 0, 'smsCount' => 1,
        ], json_decode($dsn[0]['body'], true));
        $this->assertSame('sent', $this->row('we-1')['dsn_status']);
        $this->assertSame(0, webengage_dsn_run_pass(10), 'sent exactly once');
    }

    public function testAnUndeliveredMessageReportsSmsFailed(): void
    {
        $this->call($this->payload('we-2'));
        $attemptId = (int)$this->row('we-2')['attempt_id'];
        gateway_status_record('attempt', $attemptId, 'sent', 'failed', null, 'UNDELIV');
        webengage_dsn_run_pass(10);
        $body = json_decode($this->recorded('/webengage/dsn')[0]['body'], true);
        $this->assertSame('sms_failed', $body['status']);
        $this->assertSame(2009, $body['statusCode']);
    }

    public function testAReportThatArrivedBeforeTheLinkIsNotLost(): void
    {
        $this->call($this->payload('we-3'));
        $row = $this->row('we-3');
        // Undo the link and let the report land first, as a fast push DLR can.
        db()->prepare("UPDATE ellsms_webengage_messages SET attempt_id = NULL, dsn_status = 'none' WHERE id = ?")->execute([$row['id']]);
        gateway_status_record('attempt', (int)$row['attempt_id'], 'sent', 'delivered', null, 'DELIVRD');
        $this->assertSame('none', $this->row('we-3')['dsn_status']);

        $this->assertTrue(webengage_link_attempt((int)$row['id'], (int)$row['gateway_id'], (string)$row['provider_message_id']));
        $after = $this->row('we-3');
        $this->assertSame('pending', $after['dsn_status']);
        $this->assertSame('sms_sent', $after['dsn_sms_status']);
    }

    public function testARetriedMessageIdReplaysTheFirstAnswerWithoutSendingAgain(): void
    {
        $first = $this->call($this->payload('we-4'));
        $second = $this->call($this->payload('we-4'));
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->recorded('/vesal/webengage-gw'), 'never sent twice');
        $count = db()->query("SELECT COUNT(*) FROM ellsms_webengage_messages WHERE we_message_id = 'we-4'")->fetchColumn();
        $this->assertSame(1, (int)$count);
    }

    public function testRejectionsUseWebEngageCodesAndOweNoDsn(): void
    {
        [$status, $body] = $this->call($this->payload('we-5', ['fromNumber' => '5000111']));
        $this->assertSame([400, 2004], [$status, $body['statusCode']]);
        $this->assertSame('sms_rejected', $body['status']);
        $this->assertSame('none', $this->row('we-5')['dsn_status']);

        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?, ?)')->execute([$this->userId, '989121230009']);
        [$status, $body] = $this->call($this->payload('we-6', ['toNumber' => '09121230009']));
        $this->assertSame([403, 3000], [$status, $body['statusCode']]);

        [$status, $body] = $this->call($this->payload('we-7', ['body' => ' ']));
        $this->assertSame([400, 2002], [$status, $body['statusCode']]);

        $this->assertCount(0, $this->recorded('/vesal/webengage-gw'), 'no rejected message reached the provider');
    }

    public function testADisabledIntegrationRefusesToSend(): void
    {
        $this->saveSettings(['enabled' => false, 'region' => 'us']);
        [$status, $body] = $this->call($this->payload('we-8'));
        $this->assertSame([403, 2005], [$status, $body['statusCode']]);
        $this->assertSame([], $this->row('we-8'), 'nothing recorded before the integration is on');
    }

    public function testAMessageWhoseReportNeverComesIsReportedSentAfterTheWait(): void
    {
        $this->call($this->payload('we-9'));
        db()->prepare("UPDATE ellsms_webengage_messages SET created_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE we_message_id = 'we-9'")->execute();
        webengage_dsn_run_pass(10);
        $row = $this->row('we-9');
        $this->assertSame('sent', $row['dsn_status']);
        $this->assertSame('no_report', $row['delivery_state']);
        $this->assertSame('sms_sent', json_decode($this->recorded('/webengage/dsn')[0]['body'], true)['status']);
    }

    public function testAFailingDsnEndpointIsRetriedThenGivenUp(): void
    {
        putenv('WEBENGAGE_DSN_MAX_ATTEMPTS=2');
        $this->saveSettings(['enabled' => true, 'region' => 'custom', 'url' => self::$baseUrl . '/status/error']);
        $this->call($this->payload('we-10'));
        gateway_status_record('attempt', (int)$this->row('we-10')['attempt_id'], 'sent', 'delivered', null, 'DELIVRD');

        webengage_dsn_run_pass(10);
        $row = $this->row('we-10');
        $this->assertSame('pending', $row['dsn_status'], '503 is retried');
        $this->assertSame('http_503', $row['dsn_error']);

        db()->prepare("UPDATE ellsms_webengage_messages SET dsn_next_at = NULL WHERE id = ?")->execute([$row['id']]);
        webengage_dsn_run_pass(10);
        $this->assertSame('failed', $this->row('we-10')['dsn_status'], 'attempts exhausted');
        $this->assertCount(2, $this->recorded('/status/error'));
    }

    public function testSettingsKeepTheStoredTokenAndRequireOneToEnable(): void
    {
        $this->saveSettings(['enabled' => true, 'region' => 'in']);
        $settings = webengage_settings($this->organizationId);
        $this->assertTrue($settings['has_token'], 'a blank token keeps the stored one');
        $this->assertSame('we-dsn-token', webhook_decrypt_secret($settings['token_ciphertext'], $settings['token_nonce'], $settings['token_tag']));
        $this->assertSame('https://st.in.webengage.com/tracking/events', webengage_dsn_url($settings));

        $other = (int)create_organization($this->makeUser(), 'Other Org')['organization_id'];
        $this->assertSame('token_required', webengage_settings_save($other, $this->userId, ['enabled' => true, 'region' => 'us'])['reason']);
        $this->assertSame('invalid_url', webengage_settings_save($other, $this->userId, ['enabled' => false, 'region' => 'custom', 'url' => 'ftp://x'])['reason']);
    }
}
