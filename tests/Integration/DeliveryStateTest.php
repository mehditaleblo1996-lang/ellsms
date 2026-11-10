<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once __DIR__ . '/../../app/Api/Response.php';
require_once __DIR__ . '/../../app/Api/Request.php';
require_once __DIR__ . '/../../app/Api/Handlers/Messages.php';
require_once __DIR__ . '/../../app/Api/Handlers/SharedOtp.php';
require_once __DIR__ . '/../../app/Api/Handlers/DeliveryState.php';

/**
 * GET /api/v1/{messages,otp,bulk-jobs}/{id}/state (app/Api/Handlers/DeliveryState.php) against the
 * real schema: real sends through a recording gateway (POST /messages, POST /otp), the delivery
 * poller's own write (gateway_status_record()), then the state endpoint — plus tenant isolation,
 * bulk pagination and the ?state= filter.
 */
final class DeliveryStateTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $ownLine = '5000700303';
    private string $sharedLine = '5000700404';
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_delivery_state_' . bin2hex(random_bytes(6)) . '.jsonl';
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

        $this->userId = $this->makeUser(['originator' => $this->ownLine]);
        $this->organizationId = (int)create_organization($this->userId, 'Delivery State Org')['organization_id'];
        wallet_credit($this->userId, 1000000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);

        $this->makeLine($this->ownLine, '/vesal/ds-own');
        $this->makeLine($this->sharedLine, '/vesal/ds-shared');
        set_setting('shared_otp_enabled', '1');
        set_setting('shared_otp_lines', $this->sharedLine);
        set_setting('shared_otp_min_interval_seconds', '0');

        $key = api_key_create($this->organizationId, $this->userId, 'state', [
            \ApiScopes::MESSAGES_SEND, \ApiScopes::MESSAGES_READ, \ApiScopes::OTP_SEND, \ApiScopes::BULK_READ,
        ]);
        $this->principal = api_key_authenticate($key['raw_key']);
        sms_pricing_cache_reset();
        gateway_cache_reset();
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    /** Same recording gateway + route setup as SharedOtpTest. */
    private function makeLine(string $line, string $path): void
    {
        $db = db();
        $code = 'dsgw_' . bin2hex(random_bytes(3));
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
        $db->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$line, 'delivery state']);
    }

    /** @return array{0:int, 1:array} [http status, decoded body] */
    private function call(string $handler, array $params = [], array $body = [], array $query = [], ?array $principal = null): array
    {
        http_response_code(200);
        ob_start();
        send_context_with(['channel' => 'api'], fn() => $handler([
            'principal' => $principal ?? $this->principal, 'params' => $params, 'query' => $query,
            'body' => $body, 'raw' => json_encode($body),
        ]));
        $out = (string)ob_get_clean();
        return [(int)http_response_code(), json_decode($out, true) ?? []];
    }

    private function attemptsFor(string $destination): array
    {
        $st = db()->prepare("SELECT id, delivery_status FROM ellsms_message_attempts WHERE user_id = ? AND destination = ? AND status = 'accepted' ORDER BY id");
        $st->execute([$this->userId, $destination]);
        return $st->fetchAll();
    }

    private function otherOrgPrincipal(array $scopes): array
    {
        $other = $this->makeUser();
        $org = (int)create_organization($other, 'Other Org')['organization_id'];
        return api_key_authenticate(api_key_create($org, $other, 'other', $scopes)['raw_key']);
    }

    public function testMessageStateFollowsTheDeliveryReports(): void
    {
        [$status, $sent] = $this->call('api_handle_messages_send', [], [
            'originator' => $this->ownLine, 'destinations' => ['09121110001', '09121110002'], 'content' => 'Delivery state test',
        ]);
        $this->assertSame(201, $status, json_encode($sent, JSON_UNESCAPED_UNICODE));
        $id = $sent['data']['id'];

        [$status, $body] = $this->call('api_handle_messages_state', ['id' => $id]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('sent', $body['data']['send_status']);
        $this->assertSame('pending', $body['data']['state']);
        $this->assertFalse($body['data']['final']);
        $this->assertSame(['989121110001', '989121110002'], array_column($body['data']['recipients'], 'destination'));
        $this->assertSame(['pending', 'pending'], array_column($body['data']['recipients'], 'state'));

        // The poller's own write.
        $a1 = $this->attemptsFor('989121110001')[0];
        $a2 = $this->attemptsFor('989121110002')[0];
        $this->assertTrue(gateway_status_record('attempt', (int)$a1['id'], $a1['delivery_status'], 'delivered', '2026-11-10 10:00:30', 'DELIVRD'));
        $this->assertTrue(gateway_status_record('attempt', (int)$a2['id'], $a2['delivery_status'], 'expired', null, 'EXPIRED'));

        [, $body] = $this->call('api_handle_messages_state', ['id' => $id]);
        $d = $body['data'];
        $this->assertSame('partially_delivered', $d['state']);
        $this->assertTrue($d['final']);
        $this->assertSame('delivered', $d['recipients'][0]['state']);
        $this->assertSame('2026-11-10 10:00:30', $d['recipients'][0]['delivered_at']);
        $this->assertSame('undelivered', $d['recipients'][1]['state']);
        $this->assertSame(1, $d['summary']['delivered']);
        $this->assertSame(1, $d['summary']['undelivered']);
    }

    public function testASecondMessageToTheSameNumberGetsItsOwnState(): void
    {
        [, $first] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110003'], 'content' => 'first']);
        [, $second] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110003'], 'content' => 'second']);
        $attempts = $this->attemptsFor('989121110003');
        $this->assertCount(2, $attempts);
        $this->assertTrue(gateway_status_record('attempt', (int)$attempts[0]['id'], $attempts[0]['delivery_status'], 'delivered', null, 'DELIVRD'));

        $this->assertSame('delivered', $this->call('api_handle_messages_state', ['id' => $first['data']['id']])[1]['data']['state']);
        $this->assertSame('pending', $this->call('api_handle_messages_state', ['id' => $second['data']['id']])[1]['data']['state']);
    }

    public function testMessageStateIsTenantScoped(): void
    {
        [, $sent] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110004'], 'content' => 'mine']);
        $other = $this->otherOrgPrincipal([\ApiScopes::MESSAGES_READ]);
        [$status, $body] = $this->call('api_handle_messages_state', ['id' => $sent['data']['id']], [], [], $other);
        $this->assertSame(404, $status);
        $this->assertSame('not_found', $body['error']['code']);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'abc'])[0]);
    }

    public function testOtpStateFollowsTheDeliveryReport(): void
    {
        $created = shared_otp_template_create(['id' => $this->userId, 'role' => 'user', 'organization_id' => $this->organizationId], 'ورود', 'کد ورود: {code}');
        $this->assertTrue($created['ok'], (string)($created['error'] ?? ''));
        $admin = $this->makeUser(['is_admin' => 1]);
        $this->assertTrue(shared_otp_template_review($admin, $created['id'], 'approve')['ok']);

        [$status, $sent] = $this->call('api_handle_shared_otp_send', [], ['template_id' => $created['id'], 'destination' => '09121110005', 'parameters' => ['code' => '1234']]);
        $this->assertSame(201, $status, json_encode($sent, JSON_UNESCAPED_UNICODE));
        $id = (string)$sent['data']['id'];
        [, $second] = $this->call('api_handle_shared_otp_send', [], ['template_id' => $created['id'], 'destination' => '09121110005', 'parameters' => ['code' => '5678']]);

        [$status, $body] = $this->call('api_handle_shared_otp_state', ['id' => $id]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('pending', $body['data']['state']);
        $this->assertFalse($body['data']['final']);
        $this->assertSame('989121110005', $body['data']['destination']);

        $attempts = $this->attemptsFor('989121110005');
        $this->assertCount(2, $attempts);
        $this->assertTrue(gateway_status_record('attempt', (int)$attempts[0]['id'], $attempts[0]['delivery_status'], 'delivered', '2026-11-10 10:00:40', 'DELIVRD'));

        [, $body] = $this->call('api_handle_shared_otp_state', ['id' => $id]);
        $this->assertSame('delivered', $body['data']['state']);
        $this->assertTrue($body['data']['final']);
        $this->assertSame('2026-11-10 10:00:40', $body['data']['delivered_at']);
        // The second code to the same number is still waiting for its own report.
        $this->assertSame('pending', $this->call('api_handle_shared_otp_state', ['id' => (string)$second['data']['id']])[1]['data']['state']);

        $other = $this->otherOrgPrincipal([\ApiScopes::OTP_SEND]);
        $this->assertSame(404, $this->call('api_handle_shared_otp_state', ['id' => $id], [], [], $other)[0]);
    }

    private function makeBulkJob(string $jobStatus, array $items): int
    {
        $db = db();
        $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows) VALUES (?,?, 'p2p', 'state test', ?, ?, ?)")
           ->execute([$this->userId, $this->organizationId, $this->ownLine, $jobStatus, count($items)]);
        $jobId = (int)$db->lastInsertId();
        foreach ($items as [$mobile, $status, $delivery, $deliveredAt]) {
            $db->prepare('INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status, delivered_at) VALUES (?,?,?,?,?,?)')
               ->execute([$jobId, $mobile, 'hi', $status, $delivery, $deliveredAt]);
        }
        return $jobId;
    }

    public function testBulkStateCountsPaginatesAndFilters(): void
    {
        $jobId = $this->makeBulkJob('done', [
            ['989121110011', 'sent', 'delivered', '2026-11-10 10:01:00'],
            ['989121110012', 'sent', 'rejected', null],
            ['989121110013', 'sent', 'sent', null],
            ['989121110014', 'failed', null, null],
            ['989121110015', 'cancelled', null, null],
        ]);

        [$status, $body] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], ['limit' => '2']);
        $this->assertSame(200, $status, json_encode($body));
        $d = $body['data'];
        $this->assertSame('done', $d['job_status']);
        $this->assertSame('pending', $d['state']);
        $this->assertFalse($d['final']);
        $this->assertSame(5, $d['total_rows']);
        $this->assertSame(1, $d['summary']['delivered']);
        $this->assertSame(1, $d['summary']['undelivered']);
        $this->assertSame(1, $d['summary']['pending']);
        $this->assertSame(1, $d['summary']['failed']);
        $this->assertSame(1, $d['summary']['cancelled']);
        $this->assertSame(['989121110011', '989121110012'], array_column($d['recipients'], 'destination'));
        $this->assertSame(['delivered', 'undelivered'], array_column($d['recipients'], 'state'));
        $this->assertNotNull($d['next_cursor']);

        // Walk the remaining pages.
        $seen = array_column($d['recipients'], 'destination');
        $cursor = $d['next_cursor'];
        while ($cursor !== null) {
            [, $page] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], ['limit' => '2', 'after' => $cursor]);
            $seen = array_merge($seen, array_column($page['data']['recipients'], 'destination'));
            $cursor = $page['data']['next_cursor'];
        }
        $this->assertSame(['989121110011', '989121110012', '989121110013', '989121110014', '989121110015'], $seen);

        [, $filtered] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], ['state' => 'undelivered']);
        $this->assertSame(['989121110012'], array_column($filtered['data']['recipients'], 'destination'));
        $this->assertSame(5, $filtered['data']['total_rows'], 'the summary always covers the whole job');

        [$status, $bad] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], ['state' => 'nope']);
        $this->assertSame(422, $status);
        $this->assertSame('validation_failed', $bad['error']['code']);

        $other = $this->otherOrgPrincipal([\ApiScopes::BULK_READ]);
        $this->assertSame(404, $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], [], $other)[0]);
    }

    public function testBulkJobStillSendingIsNotFinal(): void
    {
        $jobId = $this->makeBulkJob('processing', [
            ['989121110021', 'sent', 'delivered', null],
            ['989121110022', 'pending', null, null],
        ]);
        [, $body] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId]);
        $this->assertSame('sending', $body['data']['state']);
        $this->assertFalse($body['data']['final']);
        $this->assertSame(1, $body['data']['summary']['queued']);
        $this->assertSame('queued', $body['data']['recipients'][1]['state']);
    }
}
