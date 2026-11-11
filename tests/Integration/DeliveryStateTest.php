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
        $this->assertSame('sent', $body['data']['state']);
        $this->assertFalse($body['data']['final']);
        $this->assertSame(['989121110001', '989121110002'], array_column($body['data']['recipients'], 'destination'));
        $this->assertSame(['sent', 'sent'], array_column($body['data']['recipients'], 'state'));

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
        $this->assertSame('sent', $this->call('api_handle_messages_state', ['id' => $second['data']['id']])[1]['data']['state']);
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

    public function testASendGetsARandomPublicIdAndIsLinkedToItsTransportRows(): void
    {
        [$status, $sent] = $this->call('api_handle_messages_send', [], [
            'originator' => $this->ownLine, 'destinations' => ['09121110011', '09121110012'], 'content' => 'public id',
        ]);
        $this->assertSame(201, $status, json_encode($sent, JSON_UNESCAPED_UNICODE));
        $id = $sent['data']['id'];
        $this->assertMatchesRegularExpression('/^[1-8]\d{15}$/', $id, 'the API returns the random public id, not the row id');

        $row = db()->query("SELECT id, public_id FROM ellsms_api_messages WHERE content = 'public id'")->fetch();
        $this->assertSame($id, (string)$row['public_id']);
        $this->assertNotSame($id, (string)$row['id']);

        // GET and the state route answer to the public id and show it.
        [$status, $got] = $this->call('api_handle_messages_get', ['id' => $id]);
        $this->assertSame(200, $status);
        $this->assertSame($id, $got['data']['id']);
        [$status, $state] = $this->call('api_handle_messages_state', ['id' => $id]);
        $this->assertSame(200, $status);
        $this->assertSame($id, $state['data']['id']);
        // The row id of a send that HAS a public id is not an id of the API.
        $this->assertSame(404, $this->call('api_handle_messages_get', ['id' => (string)$row['id']])[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => (string)$row['id']])[0]);
        foreach (['4026740600623808506', '99999999999999999999999', '-1', ''] as $bad) {
            $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => $bad])[0], $bad);
        }

        // Both transport rows are linked, and the panel finds «شناسه‌ی API» from either.
        $a1 = (int)$this->attemptsFor('989121110011')[0]['id'];
        $a2 = (int)$this->attemptsFor('989121110012')[0]['id'];
        $linked = \api_message_attempt_ids((int)$row['id']);
        sort($linked);
        $this->assertSame([$a1, $a2], $linked);
        $this->assertSame([$a1 => $id, $a2 => $id], \api_message_ids_for_attempts([$a1, $a2, 999999999]));
    }

    public function testASendFromBeforePublicIdsKeepsAnsweringToItsRowId(): void
    {
        [, $sent] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110013'], 'content' => 'old row']);
        // As the row was before the migration: no public id, no link.
        $rowId = (int)db()->query("SELECT id FROM ellsms_api_messages WHERE content = 'old row'")->fetchColumn();
        db()->prepare('UPDATE ellsms_api_messages SET public_id = NULL WHERE id = ?')->execute([$rowId]);
        db()->prepare('DELETE FROM ellsms_api_message_attempts WHERE api_message_id = ?')->execute([$rowId]);

        [$status, $got] = $this->call('api_handle_messages_get', ['id' => (string)$rowId]);
        $this->assertSame(200, $status);
        $this->assertSame((string)$rowId, $got['data']['id']);
        $this->assertSame(404, $this->call('api_handle_messages_get', ['id' => $sent['data']['id']])[0], 'its former public id is gone');

        // Without a link the state is still found by number, line, text and time.
        $attempt = $this->attemptsFor('989121110013')[0];
        $this->assertTrue(gateway_status_record('attempt', (int)$attempt['id'], $attempt['delivery_status'], 'delivered', null, 'DELIVRD'));
        [$status, $state] = $this->call('api_handle_messages_state', ['id' => (string)$rowId]);
        $this->assertSame(200, $status);
        $this->assertSame((string)$rowId, $state['data']['id']);
        $this->assertSame('delivered', $state['data']['state']);
    }

    public function testTheStateOfALinkedSendIsReadFromItsOwnRows(): void
    {
        [, $sent] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110014'], 'content' => 'linked']);
        $attempt = $this->attemptsFor('989121110014')[0];
        // Nothing the old matching relied on is left: another text, another line, a day earlier.
        db()->prepare("UPDATE ellsms_message_attempts SET content = 'other', originator = '5000999', attempted_at = attempted_at - INTERVAL 1 DAY WHERE id = ?")
            ->execute([(int)$attempt['id']]);
        $this->assertTrue(gateway_status_record('attempt', (int)$attempt['id'], $attempt['delivery_status'], 'delivered', null, 'DELIVRD'));

        $this->assertSame('delivered', $this->call('api_handle_messages_state', ['id' => $sent['data']['id']])[1]['data']['state']);
    }

    public function testTheDeliveryWebhookCarriesTheApiMessageId(): void
    {
        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(random_bytes(32)));
        $created = webhook_endpoint_create($this->organizationId, $this->userId, 'https://example.org/hook', 'state test', [\WebhookEvents::MESSAGE_DELIVERED]);
        $this->assertTrue($created['ok'], json_encode($created));
        webhook_subscriptions_cache_reset();
        [, $sent] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110015'], 'content' => 'hook']);
        $attempt = $this->attemptsFor('989121110015')[0];
        $this->assertTrue(gateway_status_record('attempt', (int)$attempt['id'], $attempt['delivery_status'], 'delivered', '2026-11-10 10:00:30', 'DELIVRD'));

        $st = db()->prepare("SELECT payload_json FROM ellsms_webhook_events WHERE organization_id = ? AND event_type = 'message.delivered' ORDER BY id DESC LIMIT 1");
        $st->execute([$this->organizationId]);
        $payload = json_decode((string)$st->fetchColumn(), true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame($sent['data']['id'], $data['api_message_id'] ?? null, json_encode($payload));
        $this->assertSame('attempt:' . $attempt['id'], $data['message_id']);
    }

    public function testThePanelReferenceOfAnyMessageAnswersTheStateRoute(): void
    {
        // A panel send, as dispatch records it for an account whose session carried no organization.
        $db = db();
        $db->prepare("INSERT INTO ellsms_message_attempts (organization_id, user_id, reference_type, reference_id, status, destination, delivery_status, delivered_at, attempted_at, originator, content)
                      VALUES (NULL, ?, 'direct_send', ?, 'accepted', '09121110031', 'delivered', '2026-11-10 10:00:30', '2026-11-10 10:00:00', ?, 'from the panel')")
           ->execute([$this->userId, 'panel-' . bin2hex(random_bytes(6)), $this->ownLine]);
        $attemptId = (int)$db->lastInsertId();

        [$status, $body] = $this->call('api_handle_messages_state', ['id' => 'D' . $attemptId]);
        $this->assertSame(200, $status, json_encode($body));
        $d = $body['data'];
        $this->assertSame('D' . $attemptId, $d['id']);
        $this->assertSame('sent', $d['send_status']);
        $this->assertSame('delivered', $d['state']);
        $this->assertTrue($d['final']);
        $messageId = \message_public_id('attempt', $attemptId);
        $this->assertSame([['id' => $messageId, 'destination' => '989121110031', 'state' => 'delivered', 'delivered_at' => '2026-11-10 10:00:30', 'checked_at' => null]], $d['recipients']);
        $this->assertSame(1, $d['summary']['delivered']);
        $this->assertSame('2026-11-10 10:00:00', $d['created_at']);
        // Case does not matter; the bare number is not this message (it would be an API id).
        $this->assertSame(200, $this->call('api_handle_messages_state', ['id' => 'd' . $attemptId])[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => (string)$attemptId])[0]);

        // An API send's own row answers to its panel reference too, with the same state.
        [, $sent] = $this->call('api_handle_messages_send', [], ['originator' => $this->ownLine, 'destinations' => ['09121110032'], 'content' => 'by ref']);
        $apiAttempt = (int)$this->attemptsFor('989121110032')[0]['id'];
        $this->assertSame('sent', $this->call('api_handle_messages_state', ['id' => 'D' . $apiAttempt])[1]['data']['state']);

        // Another organization, a reference that does not exist, and a message an admin removed
        // from the customer's panel all look the same.
        $other = $this->otherOrgPrincipal([\ApiScopes::MESSAGES_READ]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'D' . $attemptId], [], [], $other)[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'D' . $apiAttempt], [], [], $other)[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'D999999999'])[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'L' . $attemptId])[0]);
        if (hidden_messages_available()) {
            $db->prepare("INSERT INTO ellsms_hidden_messages (source, message_id, hidden_by, reason) VALUES ('attempt', ?, ?, 'test')")->execute([$attemptId, $this->userId]);
            $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'D' . $attemptId])[0]);
        }
    }

    public function testEveryRecipientHasOneIdSharedByThePanelAndTheApi(): void
    {
        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(random_bytes(32)));
        $this->assertTrue(webhook_endpoint_create($this->organizationId, $this->userId, 'https://example.org/hook', 'ids', [\WebhookEvents::MESSAGE_DELIVERED])['ok']);
        webhook_subscriptions_cache_reset();

        [$status, $sent] = $this->call('api_handle_messages_send', [], [
            'originator' => $this->ownLine, 'destinations' => ['09121110051', '09121110052'], 'content' => 'one id',
        ]);
        $this->assertSame(201, $status, json_encode($sent, JSON_UNESCAPED_UNICODE));
        $sendId = $sent['data']['id'];
        $recipients = $sent['data']['recipients'];
        $this->assertSame(['989121110051', '989121110052'], array_column($recipients, 'destination'));
        $a1 = (int)$this->attemptsFor('989121110051')[0]['id'];
        $a2 = (int)$this->attemptsFor('989121110052')[0]['id'];
        foreach ($recipients as $r) {
            $this->assertMatchesRegularExpression('/^[1-3]\d{14}$/', $r['id'], 'a 15-digit message id');
        }
        // The id is the one the panel's report shows for that row, and it names exactly that row.
        $this->assertSame([\sent_report_public_ref('attempt', $a1), \sent_report_public_ref('attempt', $a2)], array_column($recipients, 'id'));
        $this->assertSame(['attempt', $a1], \sent_report_resolve_ref($recipients[0]['id']));
        $this->assertNotSame($sendId, $recipients[0]['id']);

        // The same ids in GET /messages/{id} and in the state of the whole send.
        $this->assertSame($recipients, $this->call('api_handle_messages_get', ['id' => $sendId])[1]['data']['recipients']);
        $state = $this->call('api_handle_messages_state', ['id' => $sendId])[1]['data'];
        $this->assertSame(array_column($recipients, 'id'), array_column($state['recipients'], 'id'));

        // One recipient's id answers the state route with that one recipient.
        $this->assertTrue(gateway_status_record('attempt', $a2, 'sent', 'delivered', '2026-11-10 10:00:30', 'DELIVRD'));
        [$status, $one] = $this->call('api_handle_messages_state', ['id' => $recipients[1]['id']]);
        $this->assertSame(200, $status, json_encode($one));
        $this->assertSame($recipients[1]['id'], $one['data']['id']);
        $this->assertSame('delivered', $one['data']['state']);
        $this->assertSame([['id' => $recipients[1]['id'], 'destination' => '989121110052', 'state' => 'delivered', 'delivered_at' => '2026-11-10 10:00:30', 'checked_at' => $one['data']['recipients'][0]['checked_at']]], $one['data']['recipients']);
        $this->assertSame('sent', $this->call('api_handle_messages_state', ['id' => $recipients[0]['id']])[1]['data']['state']);
        // The old reference of the same row still works and carries the same id.
        $this->assertSame($recipients[1]['id'], $this->call('api_handle_messages_state', ['id' => 'D' . $a2])[1]['data']['recipients'][0]['id']);

        // The delivery webhook names the message by the same id.
        $st = db()->prepare("SELECT payload_json FROM ellsms_webhook_events WHERE organization_id = ? AND event_type = 'message.delivered' ORDER BY id DESC LIMIT 1");
        $st->execute([$this->organizationId]);
        $payload = json_decode((string)$st->fetchColumn(), true);
        $data = $payload['data'] ?? $payload;
        $this->assertSame($recipients[1]['id'], $data['public_id'] ?? null, json_encode($payload));
        $this->assertSame($sendId, $data['api_message_id']);

        // Another organization, and ids that name no row of anyone.
        $other = $this->otherOrgPrincipal([\ApiScopes::MESSAGES_READ]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => $recipients[0]['id']], [], [], $other)[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => (string)\message_public_id('attempt', 987654321)])[0]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => (string)\message_public_id('legacy', $a1)])[0], 'a legacy row has no delivery report');

        // A bulk item: its id in the job's state, and that id alone on the state route.
        $jobId = $this->makeBulkJob('done', [['989121110053', 'sent', 'delivered', '2026-11-10 10:00:00']]);
        $job = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId])[1]['data'];
        $itemId = $job['recipients'][0]['id'];
        $this->assertSame(\message_public_id('bulk', (int)$job['recipients'][0]['item_id']), $itemId);
        $byId = $this->call('api_handle_messages_state', ['id' => $itemId])[1]['data'];
        $this->assertSame($itemId, $byId['id']);
        $this->assertSame('989121110053', $byId['recipients'][0]['destination']);
    }

    public function testABulkItemsPanelReferenceAnswersTheStateRoute(): void
    {
        $jobId = $this->makeBulkJob('done', [
            ['989121110041', 'sent', 'delivered', '2026-11-10 10:00:00'],
            ['989121110042', 'pending', null, null],
        ]);
        $ids = db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);

        [$status, $body] = $this->call('api_handle_messages_state', ['id' => 'B' . $ids[0]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('B' . $ids[0], $body['data']['id']);
        $this->assertSame('delivered', $body['data']['state']);
        $this->assertSame('989121110041', $body['data']['recipients'][0]['destination']);

        $queued = $this->call('api_handle_messages_state', ['id' => 'B' . $ids[1]])[1]['data'];
        $this->assertSame('queued', $queued['recipients'][0]['state']);
        $this->assertFalse($queued['final']);

        $other = $this->otherOrgPrincipal([\ApiScopes::MESSAGES_READ]);
        $this->assertSame(404, $this->call('api_handle_messages_state', ['id' => 'B' . $ids[0]], [], [], $other)[0]);

        // An aimed-bulk job's numbers belong to the platform's bank: masked here and in the job's own state.
        if (bulk_jobs_have_mask_column()) {
            db()->prepare('UPDATE ellsms_bulk_jobs SET recipients_masked = 1 WHERE id = ?')->execute([$jobId]);
            $this->assertSame('0912***0041', $this->call('api_handle_messages_state', ['id' => 'B' . $ids[0]])[1]['data']['recipients'][0]['destination']);
            [, $job] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], [], $this->principal);
            $this->assertSame(['0912***0041', '0912***0042'], array_column($job['data']['recipients'], 'destination'));
        }
    }

    public function testAStatePostWithBodyOptionsAnswersLikeTheGet(): void
    {
        $jobId = $this->makeBulkJob('done', [
            ['989121110021', 'sent', 'delivered', '2026-11-10 10:00:00'],
            ['989121110022', 'sent', 'failed', null],
            ['989121110023', 'sent', 'delivered', '2026-11-10 10:00:05'],
        ]);
        [, $byQuery] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], [], ['limit' => '1', 'state' => 'delivered']);
        [$status, $byBody] = $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], ['limit' => 1, 'state' => 'delivered']);
        $this->assertSame(200, $status);
        $this->assertSame($byQuery['data'], $byBody['data']);
        $this->assertCount(1, $byBody['data']['recipients']);
        $this->assertSame(422, $this->call('api_handle_bulk_jobs_state', ['id' => (string)$jobId], ['state' => 'nope'])[0]);
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
        $this->assertSame('sent', $body['data']['state']);
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
        $this->assertSame('sent', $this->call('api_handle_shared_otp_state', ['id' => (string)$second['data']['id']])[1]['data']['state']);

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
        $this->assertSame('sent', $d['state']);
        $this->assertFalse($d['final']);
        $this->assertSame(5, $d['total_rows']);
        $this->assertSame(1, $d['summary']['delivered']);
        $this->assertSame(1, $d['summary']['undelivered']);
        $this->assertSame(1, $d['summary']['sent']);
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
