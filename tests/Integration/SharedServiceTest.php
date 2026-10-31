<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Request.php';
require_once __DIR__ . '/../../app/Api/Handlers/Messages.php';
require_once __DIR__ . '/../../app/Api/Handlers/SharedOtp.php';
require_once __DIR__ . '/../../app/Api/Handlers/SharedService.php';

/**
 * Shared sending service (app/SharedService.php, app/Api/Handlers/SharedService.php) end to end against
 * the real schema: a customer with NO line of their own sends recipient + text; the platform picks the
 * line from its pool and sends through a recording gateway standing in for the provider.
 */
final class SharedServiceTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $serviceA = '5000800101';
    private string $serviceB = '5000800202';
    private string $promoLine = '5000800303';
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_shared_service_' . bin2hex(random_bytes(6)) . '.jsonl';
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

        $this->userId = $this->makeUser();
        $this->organizationId = (int)create_organization($this->userId, 'Shared Service Org')['organization_id'];
        wallet_credit($this->userId, 100000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);

        $this->makeLine($this->serviceA, '/vesal/ss-a');
        $this->makeLine($this->serviceB, '/vesal/ss-b');
        $this->makeLine($this->promoLine, '/vesal/ss-promo');
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->assertTrue(shared_service_line_add($admin, $this->serviceA, 'service', 100)['ok']);
        $this->assertTrue(shared_service_line_add($admin, $this->serviceB, 'service', 200)['ok']);
        $this->assertTrue(shared_service_line_add($admin, $this->promoLine, 'promo', 100)['ok']);

        set_setting('shared_service_enabled', '1');
        set_setting('shared_service_default_trust', 'trusted');
        set_setting('shared_service_promo_start', '00:00');
        set_setting('shared_service_promo_end', '00:00'); // all day unless a test narrows it
        set_setting('shared_service_hold_hours', '24');

        $key = api_key_create($this->organizationId, $this->userId, 'shared', [\ApiScopes::SHARED_SEND]);
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
        $code = 'ssgw_' . bin2hex(random_bytes(3));
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
        $db->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$line, 'shared service']);
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

    /** @return array{0:int, 1:array} [http status, decoded body] */
    private function call(array $body, string $handler = 'api_handle_shared_service_send', array $params = []): array
    {
        http_response_code(200);
        ob_start();
        $handler(['principal' => $this->principal, 'params' => $params, 'query' => [], 'body' => $body, 'raw' => json_encode($body)]);
        $out = (string)ob_get_clean();
        return [(int)http_response_code(), json_decode($out, true) ?? []];
    }

    private function admin(): int
    {
        return $this->makeUser(['is_admin' => 1]);
    }

    /** @param array<int,string> $rows price-row slot => Rial, saved in ONE save (two saves in one second take effect a second apart) */
    private function setPriceRows(?int $userId, array $rows): void
    {
        $cells = [];
        foreach ($rows as $slot => $rial) {
            foreach (gateway_price_matrix_columns() as $column) {
                foreach (['fa', 'en'] as $encoding) {
                    $cells[gateway_price_key($slot, $column['key'], $encoding)] = sms_pricing_credits_to_millicredits($rial);
                }
            }
        }
        $r = gateway_prices_save($userId, $cells, $this->admin());
        $this->assertTrue($r['ok'], (string)$r['reason']);
        $this->resetCaches();
    }

    public function testANewTextIsHeldThenApprovedOnceAndLaterMessagesGoStraightOut(): void
    {
        [$status, $body] = $this->call(['destination' => '09121240001', 'text' => 'سفارش 1001 شما ارسال شد.']);
        $this->assertSame(202, $status, json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('held', $body['data']['status']);
        $this->assertSame('new_text', $body['data']['hold_reason']);
        $this->assertNull($body['data']['originator']);
        $this->assertCount(0, $this->recorded('/vesal/ss-a'), 'nothing leaves before review');

        $textId = (int)db()->query('SELECT text_id FROM ellsms_shared_messages WHERE id = ' . (int)$body['data']['id'])->fetchColumn();
        $before = wallet_balance($this->userId)['available'];
        $r = shared_service_review($this->admin(), 'approve', $textId);
        $this->assertSame(['ok' => true, 'count' => 1], $r);

        $row = shared_service_message_get((int)$body['data']['id']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame($this->serviceA, $row['originator'], 'the highest-priority service line');
        $sent = $this->recorded('/vesal/ss-a');
        $this->assertCount(1, $sent);
        $payload = json_decode($sent[0]['body'], true);
        $this->assertSame($this->serviceA, (string)$payload['sender']);
        $this->assertSame(['سفارش 1001 شما ارسال شد.'], $payload['contents']);
        $this->assertGreaterThan(0, $before - wallet_balance($this->userId)['available'], 'charged to the customer when sent');

        // Same shape, another order number: no review.
        [$status, $body] = $this->call(['destination' => '09121240002', 'text' => 'سفارش ۲۰۰۲ شما ارسال شد.']);
        $this->assertSame(201, $status, json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame('sent', $body['data']['status']);
        $this->assertSame('service', $body['data']['line_type']);
        $this->assertCount(2, $this->recorded('/vesal/ss-a'));
    }

    public function testASupervisedCustomerIsAlwaysReviewed(): void
    {
        db()->prepare("INSERT INTO ellsms_shared_trust (user_id, trust, updated_at) VALUES (?, 'supervised', ?)")->execute([$this->userId, date('Y-m-d H:i:s')]);
        $first = shared_service_submit($this->user(), 'service', '09121240003', 'نوبت شما ساعت 10 است.');
        $textId = (int)shared_service_message_get($first['id'])['text_id'];
        shared_service_review($this->admin(), 'approve', $textId);

        $second = shared_service_submit($this->user(), 'service', '09121240004', 'نوبت شما ساعت 11 است.');
        $this->assertSame('held', $second['status']);
        $this->assertSame('supervised', $second['hold_reason'], 'an approved text does not skip review for a supervised customer');

        // One message at a time.
        $this->assertSame(1, shared_service_review($this->admin(), 'approve', null, $second['id'])['count']);
        $this->assertSame('sent', shared_service_message_get($second['id'])['status']);
    }

    public function testARejectedTextIsRefusedFromThenOn(): void
    {
        $held = shared_service_submit($this->user(), 'service', '09121240005', 'وام فوری بدون ضامن 50 میلیون');
        $textId = (int)shared_service_message_get($held['id'])['text_id'];
        $this->assertFalse(shared_service_review($this->admin(), 'reject', $textId)['ok'], 'a reason is required');
        $this->assertTrue(shared_service_review($this->admin(), 'reject', $textId, null, 'محتوای تبلیغاتی در خط خدماتی')['ok']);
        $this->assertSame('rejected', shared_service_message_get($held['id'])['status']);
        $this->assertSame(1, (int)db()->query("SELECT COUNT(*) FROM ellsms_notifications WHERE user_id = {$this->userId} AND event_key = 'shared_service.rejected'")->fetchColumn());

        [$status, $body] = $this->call(['destination' => '09121240006', 'text' => 'وام فوری بدون ضامن 80 میلیون']);
        $this->assertSame(422, $status);
        $this->assertSame('text_rejected', $body['error']['reason']);
        $this->assertCount(0, $this->recorded('/vesal/ss-a'));
    }

    public function testTheCallerCannotChooseTheSenderOrThePrice(): void
    {
        [$status, $body] = $this->call(['destination' => '09121240007', 'text' => 'سلام', 'originator' => $this->serviceA]);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('originator', $body['error']['fields']);
        [$status, $body] = $this->call(['destination' => '09121240007', 'text' => 'سلام', 'message_type' => 'otp']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('message_type', $body['error']['fields']);
        [$status, $body] = $this->call(['destination' => '09121240007', 'text' => 'سلام', 'line_type' => 'vip']);
        $this->assertSame(422, $status);
        $this->assertArrayHasKey('line_type', $body['error']['fields']);

        // And the pool line is never the customer's own sender.
        $this->assertFalse(can_use_originator($this->user(), $this->serviceA));
        [$ok, $info] = dispatch_message($this->user(), $this->serviceA, ['989121240007'], 'ارسال آزاد از خط اشتراکی');
        $this->assertFalse($ok);
        $this->assertSame(NO_ASSIGNED_LINE_MESSAGE, $info);
    }

    public function testProhibitedWordsAreRefusedOutright(): void
    {
        db()->prepare("INSERT INTO ellsms_prohibited_words (pattern, match_type, active, created_at) VALUES ('قمار', 'contains', 1, ?)")->execute([date('Y-m-d H:i:s')]);
        content_policy_reset();
        $r = shared_service_submit($this->user(), 'service', '09121240008', 'بهترین سایت قمار');
        content_policy_reset();
        $this->assertFalse($r['ok']);
        $this->assertSame('prohibited_content', $r['error_code']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_shared_messages WHERE user_id = {$this->userId}")->fetchColumn());
    }

    public function testSwitchingProviderIsDisablingOneLineAndTheCustomerSeesNothing(): void
    {
        $approve = function (string $text): void {
            $r = shared_service_submit($this->user(), 'service', '09121240009', $text);
            shared_service_review($this->admin(), 'approve', (int)shared_service_message_get($r['id'])['text_id']);
        };
        $approve('پرداخت شما ثبت شد. 1');

        $lineId = (int)db()->query("SELECT id FROM ellsms_shared_lines WHERE number = '{$this->serviceA}'")->fetchColumn();
        $this->assertTrue(shared_service_line_update($this->admin(), $lineId, ['enabled' => false])['ok']);
        [$status, $body] = $this->call(['destination' => '09121240010', 'text' => 'پرداخت شما ثبت شد. 2']);
        $this->assertSame(201, $status);
        $this->assertSame($this->serviceB, $body['data']['originator']);
        $this->assertCount(1, $this->recorded('/vesal/ss-b'));
    }

    public function testWhenTheFirstLinesGatewayIsDownTheNextOneSends(): void
    {
        db()->prepare("UPDATE ellsms_sms_gateway_send_connectors c
                       JOIN ellsms_sms_routes r ON r.gateway_id = c.gateway_id
                       JOIN ellsms_sender_routes s ON s.route_id = r.id
                       SET c.endpoint_url = 'http://127.0.0.1:9/down' WHERE s.sender = ?")->execute([$this->serviceA]);
        $this->resetCaches();
        $r = shared_service_submit($this->user(), 'service', '09121240011', 'رسید پرداخت 77');
        shared_service_review($this->admin(), 'approve', (int)shared_service_message_get($r['id'])['text_id']);
        $row = shared_service_message_get($r['id']);
        $this->assertSame('sent', $row['status'], (string)$row['error']);
        $this->assertSame($this->serviceB, $row['originator']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_wallet_reservations WHERE reference_type = 'shared_service' AND status = 'active'")->fetchColumn());
    }

    public function testSharedPriceRowsPriceTheMessageWhateverLineSendsIt(): void
    {
        $this->setPriceRows(null, [GATEWAY_PRICE_SHARED_SERVICE => '777', GATEWAY_PRICE_SHARED_PROMO => '333']);
        $r = shared_service_submit($this->user(), 'service', '09121240012', 'Your order 5 shipped');
        $before = wallet_balance($this->userId)['available'];
        shared_service_review($this->admin(), 'approve', (int)shared_service_message_get($r['id'])['text_id']);
        $this->assertSame(777, $before - wallet_balance($this->userId)['available'], 'the global shared-service row');

        // The account's own row wins.
        $this->setPriceRows($this->userId, [GATEWAY_PRICE_SHARED_SERVICE => '555']);
        $before = wallet_balance($this->userId)['available'];
        $sent = shared_service_submit($this->user(), 'service', '09121240013', 'Your order 6 shipped');
        $this->assertSame('sent', $sent['status']);
        $this->assertSame(555, $before - wallet_balance($this->userId)['available']);

        // A promotional message uses the promotional row, not the service one.
        $promo = shared_service_submit($this->user(), 'promo', '09121240014', 'Sale 20 percent');
        $before = wallet_balance($this->userId)['available'];
        shared_service_review($this->admin(), 'approve', (int)shared_service_message_get($promo['id'])['text_id']);
        $this->assertSame('sent', shared_service_message_get($promo['id'])['status']);
        $this->assertSame($this->promoLine, shared_service_message_get($promo['id'])['originator']);
        $this->assertSame(333, $before - wallet_balance($this->userId)['available']);

        // The same lines priced outside the shared service are untouched.
        $this->assertNull(gateway_price_row_override());
    }

    public function testAPromotionalMessageOutsideTheAllowedHoursWaitsForTheWindow(): void
    {
        $now = new \DateTimeImmutable();
        $start = $now->modify('+2 hours')->format('H:i');
        $end = $now->modify('+3 hours')->format('H:i');
        set_setting('shared_service_promo_start', $start);
        set_setting('shared_service_promo_end', $end);

        $r = shared_service_submit($this->user(), 'promo', '09121240015', 'حراج پاییزه 30 درصد');
        shared_service_review($this->admin(), 'approve', (int)shared_service_message_get($r['id'])['text_id']);
        $row = shared_service_message_get($r['id']);
        $this->assertSame('queued', $row['status']);
        $this->assertNotNull($row['send_after']);
        $this->assertSame($start, substr((string)$row['send_after'], 11, 5));
        $this->assertCount(0, $this->recorded('/vesal/ss-promo'));

        // An approved text later in the day is queued at submission too.
        [$status, $body] = $this->call(['destination' => '09121240016', 'text' => 'حراج پاییزه 40 درصد', 'line_type' => 'promo']);
        $this->assertSame(202, $status);
        $this->assertSame('queued', $body['data']['status']);

        $stats = shared_service_worker_pass(new \DateTimeImmutable((string)$row['send_after'] . ' +1 minute'));
        $this->assertSame(2, $stats['sent']);
        $this->assertCount(2, $this->recorded('/vesal/ss-promo'));
    }

    public function testAPromotionalMessageToABlacklistedOrOptedOutNumberIsRefused(): void
    {
        db()->prepare('INSERT INTO ellsms_blacklist (user_id, mobile) VALUES (?, ?)')->execute([$this->userId, '989121240017']);
        $r = shared_service_submit($this->user(), 'promo', '09121240017', 'حراج');
        $this->assertSame('blacklisted', $r['error_code']);
        // Service messages are not affected by the customer's marketing blacklist.
        $this->assertTrue(shared_service_submit($this->user(), 'service', '09121240017', 'قبض شما صادر شد')['ok']);
    }

    public function testAHeldMessageNobodyReviewsExpires(): void
    {
        $r = shared_service_submit($this->user(), 'service', '09121240018', 'متن بررسی‌نشده');
        db()->prepare('UPDATE ellsms_shared_messages SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 25 * 3600), $r['id']]);
        $stats = shared_service_worker_pass();
        $this->assertSame(1, $stats['expired']);
        $this->assertSame('expired', shared_service_message_get($r['id'])['status']);
    }

    public function testAMessageCanBeReadBackOnlyByItsOrganization(): void
    {
        [, $body] = $this->call(['destination' => '09121240019', 'text' => 'یادآوری جلسه']);
        [$status, $got] = $this->call([], 'api_handle_shared_service_get', ['id' => (string)$body['data']['id']]);
        $this->assertSame(200, $status);
        $this->assertSame('held', $got['data']['status']);

        $other = $this->makeUser();
        $otherOrg = (int)create_organization($other, 'Other')['organization_id'];
        $this->assertNull(shared_service_message_find(['id' => $other, 'role' => 'user', 'organization_id' => $otherOrg], (int)$body['data']['id']));
    }
}
