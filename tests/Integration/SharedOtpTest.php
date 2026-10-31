<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Request.php';
require_once __DIR__ . '/../../app/Api/Handlers/Messages.php';
require_once __DIR__ . '/../../app/Api/Handlers/SharedOtp.php';

/**
 * Shared OTP (app/SharedOtp.php, app/Api/Handlers/SharedOtp.php) end to end against the real schema:
 * a customer with NO line of their own sends an approved template from a shared platform line, through
 * a recording gateway standing in for the provider.
 */
final class SharedOtpTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $lineA = '5000700101';
    private string $lineB = '5000700202';
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_shared_otp_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        @file_put_contents(self::$recordFile, '');

        // The customer has no sender line at all.
        $this->userId = $this->makeUser();
        $this->organizationId = (int)create_organization($this->userId, 'Shared OTP Org')['organization_id'];
        wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);

        // Two platform lines, owned by nobody, each on its own gateway.
        $this->makeLine($this->lineA, '/vesal/otp-gw-a');
        $this->makeLine($this->lineB, '/vesal/otp-gw-b');

        set_setting('shared_otp_enabled', '1');
        set_setting('shared_otp_lines', $this->lineA . ',' . $this->lineB);
        set_setting('shared_otp_min_interval_seconds', '60');

        $key = api_key_create($this->organizationId, $this->userId, 'otp', [\ApiScopes::OTP_SEND]);
        $this->principal = api_key_authenticate($key['raw_key']);
        $this->resetCaches();
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        $this->resetCaches();
        parent::tearDown();
    }

    private function resetCaches(): void
    {
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    private function makeLine(string $line, string $path): int
    {
        $db = db();
        $code = 'otpgw_' . bin2hex(random_bytes(3));
        $db->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                      VALUES (?,?, 'active', 'batch', 1, 0, 0, 1)")->execute([$code, $code]);
        $gatewayId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_sms_gateway_send_connectors
                        (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify, auth_type, success_rule_json, batch_mapping_json)
                      VALUES (?,?, 'POST','application/json',5000,30000,1,'none',?,?)")
           ->execute([$gatewayId, self::$baseUrl . $path,
                      json_encode(['http' => ['min' => 200, 'max' => 299], 'require_json' => true, 'rules' => []]),
                      json_encode(['correlation_mode' => 'position', 'provider_ids_path' => 'references'])]);
        foreach ([['sender', 'sender', 'string', 20], ['destinations', 'recipients_array', 'string_array', 30], ['contents', 'messages_array', 'string_array', 40]] as [$key, $value, $dataType, $sort]) {
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
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$line, 'default', (int)$db->lastInsertId(), 'active', $line . ':default']);
        $db->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$line, 'shared otp']);
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

    private function user(): array
    {
        return ['id' => $this->userId, 'role' => 'user', 'organization_id' => $this->organizationId];
    }

    private function approvedTemplate(string $content = 'فروشگاه نمونه — کد ورود شما: {code}'): int
    {
        $created = shared_otp_template_create($this->user(), 'ورود', $content);
        $this->assertTrue($created['ok'], (string)($created['error'] ?? ''));
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->assertTrue(shared_otp_template_review($admin, $created['id'], 'approve')['ok']);
        return $created['id'];
    }

    /** @return array{0:int, 1:array} [http status, decoded body] */
    private function call(array $body, string $handler = 'api_handle_shared_otp_send', array $params = []): array
    {
        http_response_code(200);
        ob_start();
        $handler(['principal' => $this->principal, 'params' => $params, 'query' => [], 'body' => $body, 'raw' => json_encode($body)]);
        $out = (string)ob_get_clean();
        return [(int)http_response_code(), json_decode($out, true) ?? []];
    }

    /** Which line a recipient starts on — so a test can pick the gateway it expects to be called. */
    private function firstLineFor(string $msisdn): string
    {
        return shared_otp_line_order([$this->lineA, $this->lineB], $msisdn)[0];
    }

    public function testACustomerWithoutALineSendsAnApprovedTemplateFromASharedLine(): void
    {
        $templateId = $this->approvedTemplate();
        $before = wallet_balance($this->userId)['available'];

        [$status, $body] = $this->call(['template_id' => $templateId, 'destination' => '09121230001', 'parameters' => ['code' => '4821']]);

        $this->assertSame(201, $status, json_encode($body, JSON_UNESCAPED_UNICODE));
        $line = $this->firstLineFor('989121230001');
        $this->assertSame('sent', $body['data']['status']);
        $this->assertNull($body['data']['originator'], 'the customer never learns the shared line');
        $this->assertSame('989121230001', $body['data']['destination']);

        $sent = $this->recorded($line === $this->lineA ? '/vesal/otp-gw-a' : '/vesal/otp-gw-b');
        $this->assertCount(1, $sent, 'one provider call, from the shared line');
        $payload = json_decode($sent[0]['body'], true);
        $this->assertSame($line, (string)$payload['sender']);
        $this->assertSame(['فروشگاه نمونه — کد ورود شما: 4821'], $payload['contents']);
        $this->assertGreaterThan(0, $before - wallet_balance($this->userId)['available'], 'charged to the customer\'s own wallet');

        $row = db()->query('SELECT * FROM ellsms_otp_messages WHERE id = ' . (int)$body['data']['id'])->fetch();
        $this->assertSame('sent', $row['status']);
        $this->assertSame('api', $row['source']);
    }

    public function testTheSharedLineNeverBecomesTheCustomersOwnSender(): void
    {
        $this->approvedTemplate();
        $user = $this->user();
        $this->assertFalse(can_use_originator($user, $this->lineA));
        $this->assertNotContains($this->lineA, sendable_originators($user));
        [$ok, $info] = dispatch_message($user, $this->lineA, ['989121230002'], 'تبلیغ آزاد از خط اشتراکی');
        $this->assertFalse($ok, 'a plain send from the shared line is refused');
        $this->assertSame(NO_ASSIGNED_LINE_MESSAGE, $info);
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-a'));
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-b'));
    }

    public function testAPendingOrRejectedTemplateCannotSend(): void
    {
        $pending = shared_otp_template_create($this->user(), 'p', 'فروشگاه نمونه: {code}')['id'];
        [$status, $body] = $this->call(['template_id' => $pending, 'destination' => '09121230003', 'parameters' => ['code' => '1']]);
        $this->assertSame(422, $status);
        $this->assertSame(['not_approved'], $body['error']['fields']['template_id']);

        $admin = $this->makeUser(['is_admin' => 1]);
        $this->assertSame('note_required', shared_otp_template_review($admin, $pending, 'reject')['error_code']);
        $this->assertTrue(shared_otp_template_review($admin, $pending, 'reject', 'متن نامشخص')['ok']);
        [$status] = $this->call(['template_id' => $pending, 'destination' => '09121230003', 'parameters' => ['code' => '1']]);
        $this->assertSame(422, $status);
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-a'));
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-b'));
    }

    public function testEditingAnApprovedTemplateSendsItBackToReview(): void
    {
        $id = $this->approvedTemplate();
        $r = shared_otp_template_update($this->user(), $id, 'ورود', 'فروشگاه نمونه — کد شما: {code}. تخفیف ویژه!');
        $this->assertSame('pending', $r['status']);
        [$status] = $this->call(['template_id' => $id, 'destination' => '09121230004', 'parameters' => ['code' => '1']]);
        $this->assertSame(422, $status);

        // A title-only change keeps the approval.
        $id2 = $this->approvedTemplate();
        $this->assertSame('approved', shared_otp_template_update($this->user(), $id2, 'عنوان تازه', 'فروشگاه نمونه — کد ورود شما: {code}')['status']);
    }

    public function testTheCallerCannotChooseTheSenderOrSendFreeText(): void
    {
        $id = $this->approvedTemplate();
        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230005', 'parameters' => ['code' => '1'], 'originator' => '5000111']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('originator', $body['error']['fields']);

        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230005', 'parameters' => ['code' => 'خرید با تخفیف در shop.ir']]);
        $this->assertSame(422, $status);
        $this->assertSame(['links_not_allowed'], $body['error']['fields']['parameters.code']);

        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230005', 'parameters' => ['code' => '1', 'extra' => 'x']]);
        $this->assertSame(422, $status);
        $this->assertSame(['unknown_variable'], $body['error']['fields']['parameters.extra']);

        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230005', 'parameters' => ['code' => '1'], 'message_type' => 'promotional']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('message_type', $body['error']['fields']);
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-a'));
        $this->assertCount(0, $this->recorded('/vesal/otp-gw-b'));
    }

    public function testASecondCodeToTheSameNumberTooSoonIsRateLimited(): void
    {
        $id = $this->approvedTemplate();
        [$first] = $this->call(['template_id' => $id, 'destination' => '09121230006', 'parameters' => ['4821']]);
        $this->assertSame(201, $first);
        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230006', 'parameters' => ['4822']]);
        $this->assertSame(429, $status);
        $this->assertSame('too_frequent', $body['error']['reason']);
        [$other] = $this->call(['template_id' => $id, 'destination' => '09121230007', 'parameters' => ['4823']]);
        $this->assertSame(201, $other, 'another number is not affected');
    }

    public function testAnotherOrganizationsTemplateLooksMissing(): void
    {
        $id = $this->approvedTemplate();
        $otherUser = $this->makeUser();
        $otherOrg = (int)create_organization($otherUser, 'Other')['organization_id'];
        $other = ['id' => $otherUser, 'role' => 'user', 'organization_id' => $otherOrg];
        $r = shared_otp_send($other, $id, '09121230008', ['code' => '1']);
        $this->assertSame('template_not_found', $r['error_code']);
        $this->assertNull(shared_otp_template_find($other, $id));
    }

    public function testWhenTheFirstLinesGatewayIsDownTheNextLineIsUsed(): void
    {
        $id = $this->approvedTemplate();
        $msisdn = '989121230009';
        $first = $this->firstLineFor($msisdn);
        $second = $first === $this->lineA ? $this->lineB : $this->lineA;
        // Point the first line's gateway at an address nothing listens on.
        db()->prepare("UPDATE ellsms_sms_gateway_send_connectors c
                       JOIN ellsms_sms_routes r ON r.gateway_id = c.gateway_id
                       JOIN ellsms_sender_routes s ON s.route_id = r.id
                       SET c.endpoint_url = 'http://127.0.0.1:9/down' WHERE s.sender = ?")->execute([$first]);
        $this->resetCaches();

        $before = wallet_balance($this->userId)['available'];
        $r = shared_otp_send($this->user(), $id, $msisdn, ['code' => '4821']);
        $this->assertTrue($r['ok'], (string)($r['error'] ?? ''));
        $this->assertSame($second, $r['originator']);
        $this->assertCount(1, $this->recorded($second === $this->lineA ? '/vesal/otp-gw-a' : '/vesal/otp-gw-b'));
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type = 'shared_otp' AND status = 'active'")->fetchColumn(),
            'the failed line\'s reservation was released, nothing is left held');
        $charged = $before - wallet_balance($this->userId)['available'];
        $this->assertGreaterThan(0, $charged);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(DISTINCT reference_id) FROM ellsms_wallet_transactions WHERE reference_type = 'shared_otp' AND user_id = {$this->userId} AND amount < 0")->fetchColumn(),
            'only the line that sent was charged');
    }

    public function testADisabledServiceAnswers503(): void
    {
        $id = $this->approvedTemplate();
        set_setting('shared_otp_enabled', '0');
        [$status, $body] = $this->call(['template_id' => $id, 'destination' => '09121230010', 'parameters' => ['code' => '1']]);
        $this->assertSame(503, $status);
        $this->assertSame('service_unavailable', $body['error']['code']);
    }

    public function testTemplatesAndASendCanBeReadBack(): void
    {
        $id = $this->approvedTemplate('فروشگاه نمونه — {name} عزیز، کد: {code}');
        shared_otp_template_create($this->user(), 'دوم', 'فروشگاه نمونه: {code}');
        [$status, $body] = $this->call([], 'api_handle_shared_otp_templates');
        $this->assertSame(200, $status);
        $this->assertCount(2, $body['data']);
        $mine = array_values(array_filter($body['data'], static fn($t) => $t['id'] === $id))[0];
        $this->assertSame(['name', 'code'], $mine['variables']);
        $this->assertSame('approved', $mine['status']);

        [, $sent] = $this->call(['template_id' => $id, 'destination' => '09121230011', 'parameters' => ['code' => '77', 'name' => 'سارا']]);
        [$status, $got] = $this->call([], 'api_handle_shared_otp_get', ['id' => (string)$sent['data']['id']]);
        $this->assertSame(200, $status);
        $this->assertSame('sent', $got['data']['status']);
        $this->assertArrayNotHasKey('content', $got['data'], 'the code is never stored or returned');
    }
}
