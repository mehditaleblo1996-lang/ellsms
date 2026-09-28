<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * #42 — the Bale messenger channel against a Bale-shaped endpoint (recording fixture, /bale/*):
 * exact request shape, per-message billing of accepted messages only, SMS fallback for recipients
 * Bale did not take, replay safety, content policy and the per-second cap.
 */
final class BaleChannelTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900900';
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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_bale_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        putenv('BALE_API_ACCESS_KEY=test-bale-key');
        set_setting('bale_enabled', '1');
        set_setting('bale_base_url', self::$baseUrl . '/bale');
        set_setting('bale_bot_id', '1234567890');
        set_setting('bale_tps', '1000');
        set_setting('bale_price_credits', '2');
        $GLOBALS['__bale_bucket'] = null;
        sms_pricing_cache_reset();
        gateway_cache_reset();
        @file_put_contents(self::$recordFile, '');
        @file_put_contents(self::$recordFile . '.vesal', '');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        wallet_credit($this->userId, 1000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
    }

    protected function tearDown(): void
    {
        putenv('SMS_GATEWAY_TRANSPORT');
        putenv('BALE_API_ACCESS_KEY');
        sms_pricing_cache_reset();
        gateway_cache_reset();
        parent::tearDown();
    }

    private function user(): array
    {
        return backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    private function recorded(string $path): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            $r = json_decode($line, true);
            if (($r['path'] ?? '') === $path) $out[] = $r;
        }
        return $out;
    }

    private function makeSmsGateway(): void
    {
        $db = db();
        $code = 'balesms_' . bin2hex(random_bytes(3));
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

    public function testItIsConfiguredOnlyWithEverySettingAndTheKey(): void
    {
        $this->assertTrue(bale_configured());
        putenv('BALE_API_ACCESS_KEY');
        $this->assertFalse(bale_configured(), 'the key lives only in the environment');
        putenv('BALE_API_ACCESS_KEY=test-bale-key');
        set_setting('bale_enabled', '0');
        $this->assertFalse(bale_configured());
    }

    public function testTheRequestHasBalesExactShapeAndOnlyAcceptedMessagesAreCharged(): void
    {
        $before = wallet_balance($this->userId)['available'];
        $result = bale_dispatch($this->user(), ['989121270001', '989121270002', '989120001234'], 'کد ورود: ۱۲۳۴', 'bale:direct_send', 'bale-test-1');

        $this->assertSame(['989121270001', '989121270002'], $result['sent']);
        $this->assertArrayHasKey('989120001234', $result['failed'], 'no Bale account');
        $requests = $this->recorded('/bale/api/v3/send_message');
        $this->assertCount(3, $requests);
        $this->assertSame('test-bale-key', $requests[0]['headers']['Api-Access-Key']);
        $this->assertSame(['bot_id' => 1234567890, 'phone_number' => '989121270001', 'message_data' => ['message' => ['text' => 'کد ورود: ۱۲۳۴']]],
            json_decode((string)$requests[0]['body'], true));
        $this->assertMatchesRegularExpression('/"bot_id":1234567890/', (string)$requests[0]['body'], 'bot_id is a JSON number');

        $this->assertSame(4, $result['cost'], '2 accepted × 2 credits');
        $this->assertSame($before - 4, wallet_balance($this->userId)['available']);
        $rows = db()->query("SELECT status, cost_credits, provider_message_id FROM ellsms_channel_messages WHERE reference_id = 'bale-test-1' ORDER BY id")->fetchAll();
        $this->assertSame(['accepted', 'accepted', 'failed'], array_column($rows, 'status'));
        $this->assertSame([2, 2, 0], array_map('intval', array_column($rows, 'cost_credits')));
        $this->assertStringStartsWith('bale-', (string)$rows[0]['provider_message_id']);
    }

    public function testReplayingTheSameReferenceSendsNothingAgain(): void
    {
        bale_dispatch($this->user(), ['989121270003'], 'hi', 'bale:api_message', 'client-42');
        $again = bale_dispatch($this->user(), ['989121270003'], 'hi', 'bale:api_message', 'client-42');
        $this->assertTrue($again['ok']);
        $this->assertCount(1, $this->recorded('/bale/api/v3/send_message'));
    }

    public function testBaleThenSmsSendsTheRestAsSmsAndChargesEachChannelForItsOwn(): void
    {
        $this->makeSmsGateway();
        [$ok, , , $sent, $total, , $byChannel] = dispatch_with_channel($this->user(), $this->line, ['989121270004', '989120007777'], 'سلام', 'bale_sms');
        $this->assertTrue($ok);
        $this->assertSame([2, 2], [$sent, $total]);
        $this->assertSame(['bale' => 1, 'sms' => 1], $byChannel);
        $sms = $this->recorded('/vesal/ManyToMany');
        $this->assertCount(1, $sms);
        $this->assertSame(['989120007777'], json_decode((string)$sms[0]['body'], true)['destinations'], 'only the recipient Bale did not take goes as SMS');
    }

    public function testBaleOnlyDoesNotFallBack(): void
    {
        $this->makeSmsGateway();
        [, , , $sent, , , $byChannel] = dispatch_with_channel($this->user(), $this->line, ['989120007778'], 'سلام', 'bale');
        $this->assertSame(0, $sent);
        $this->assertSame(['bale' => 0, 'sms' => 0], $byChannel);
        $this->assertSame([], $this->recorded('/vesal/ManyToMany'));
    }

    public function testTheSmsChannelIsTheUnchangedPath(): void
    {
        $this->makeSmsGateway();
        [, , , , , , $byChannel] = dispatch_with_channel($this->user(), $this->line, ['989121270005'], 'سلام', 'sms');
        $this->assertSame(['bale' => 0, 'sms' => 1], $byChannel);
        $this->assertSame([], $this->recorded('/bale/api/v3/send_message'));
    }

    public function testProhibitedContentNeverReachesBale(): void
    {
        db()->prepare("INSERT INTO ellsms_prohibited_words (pattern, match_type) VALUES ('قمار', 'contains') ON DUPLICATE KEY UPDATE active = 1")->execute();
        content_policy_reset();
        $result = bale_dispatch($this->user(), ['989121270006'], 'قمار', 'bale:direct_send', 'bale-test-policy');
        content_policy_reset();
        $this->assertFalse($result['ok']);
        $this->assertSame([], $this->recorded('/bale/api/v3/send_message'));
    }

    public function testThePerSecondCapIsRespected(): void
    {
        set_setting('bale_tps', '2');
        $GLOBALS['__bale_bucket'] = null;
        $started = microtime(true);
        bale_dispatch($this->user(), ['989121270007', '989121270008', '989121270009', '989121270010'], 'x', 'bale:direct_send', 'bale-test-tps');
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started, '4 messages at 2/s need about a second after the initial burst of 2');
    }
}
