<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * A sending gateway pinned to a number (numbers page, ellsms_numbers.gateway_id): the pinned gateway
 * carries everything the number sends, the price still comes from the route, and a pinned gateway that
 * cannot send holds the message (retryable) instead of sending it any other way.
 */
final class NumberGatewayTest extends IntegrationTestCase
{
    private static $serverProcess = null;
    private static string $baseUrl = '';
    private static string $recordFile = '';

    private string $line = '5000900922';
    private int $userId;
    private int $routeGateway;
    private int $otherGateway;

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
        self::$recordFile = sys_get_temp_dir() . '/ellsms_numgw_' . bin2hex(random_bytes(6)) . '.jsonl';
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
        @file_put_contents(self::$recordFile . '.vesal', '');
        $this->userId = $this->makeUser(['originator' => $this->line]);
        wallet_credit($this->userId, 1000, 'purchase', 'test', 'seed:' . $this->userId, 'seed:' . $this->userId);
        $this->routeGateway = $this->makeGateway('/vesal/route-gw');
        $this->otherGateway = $this->makeGateway('/vesal/pinned-gw');
        $suffix = bin2hex(random_bytes(3));
        $db = db();
        $db->prepare('INSERT INTO ellsms_sms_providers (code, name, status) VALUES (?,?,?)')->execute(['p_' . $suffix, 'p', 'active']);
        $providerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_sms_routes (provider_id, code, name, message_type, status, is_default, default_slot, gateway_id) VALUES (?,?,?,?,?,0,NULL,?)')
           ->execute([$providerId, 'r_' . $suffix, 'r', 'default', 'active', $this->routeGateway]);
        $db->prepare('INSERT INTO ellsms_sender_routes (sender, message_type, route_id, status, active_slot) VALUES (?,?,?,?,?)')
           ->execute([$this->line, 'default', (int)$db->lastInsertId(), 'active', $this->line . ':default']);
        $db->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$this->line, 'test', $this->userId]);
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

    private function makeGateway(string $path): int
    {
        $db = db();
        $code = 'numgw_' . bin2hex(random_bytes(3));
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

    private function pin(?int $gatewayId): void
    {
        db()->prepare('UPDATE ellsms_numbers SET gateway_id = ? WHERE number = ?')->execute([$gatewayId, $this->line]);
        $this->resetCaches();
    }

    private function hits(string $path): int
    {
        $n = 0;
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$recordFile))) as $line) {
            if ((json_decode($line, true)['path'] ?? '') === $path) $n++;
        }
        return $n;
    }

    private function user(): array
    {
        return backend_find_user_by_id($this->userId) + ['role' => 'user', 'organization_id' => null];
    }

    private function send(string $to): array
    {
        $before = wallet_balance($this->userId)['available'];
        $r = dispatch_message($this->user(), $this->line, [$to], 'سلام', null, 'direct_send', 'numgw-' . bin2hex(random_bytes(6)));
        return [$r, $before - wallet_balance($this->userId)['available']];
    }

    public function testWithoutAPinTheRoutesGatewaySends(): void
    {
        [$r] = $this->send('989121280001');
        $this->assertTrue($r[0], (string)$r[1]);
        $this->assertSame(1, $this->hits('/vesal/route-gw'));
        $this->assertSame(0, $this->hits('/vesal/pinned-gw'));
    }

    public function testThePinnedGatewaySendsAndThePriceIsUnchanged(): void
    {
        [, $unpinnedCost] = $this->send('989121280002');
        $this->pin($this->otherGateway);
        [$r, $pinnedCost] = $this->send('989121280003');
        $this->assertTrue($r[0], (string)$r[1]);
        $this->assertSame(1, $this->hits('/vesal/pinned-gw'));
        $this->assertSame(1, $this->hits('/vesal/route-gw'), 'only the unpinned send used the route gateway');
        $this->assertGreaterThan(0, $unpinnedCost);
        $this->assertSame($unpinnedCost, $pinnedCost, 'the price still comes from the route');
        $this->assertSame($this->otherGateway, (int)gateway_for_sender($this->line, sms_pricing_route_for_sender($this->line, 'default'))['connector']['gateway_id']);
    }

    public function testAPinnedGatewayThatCannotSendHoldsTheMessageInsteadOfUsingAnotherGateway(): void
    {
        $this->pin($this->otherGateway);
        db()->prepare('UPDATE ellsms_sms_gateways SET send_enabled = 0, config_version = config_version + 1 WHERE id = ?')->execute([$this->otherGateway]);
        $this->resetCaches();

        [$r, $cost] = $this->send('989121280004');
        $this->assertFalse($r[0]);
        $this->assertTrue($r[2], 'retryable, so a bulk job tries again later');
        $this->assertSame(0, $this->hits('/vesal/route-gw'), 'never silently sent through the route gateway');
        $this->assertSame(0, $this->hits('/vesal/pinned-gw'));
        $this->assertSame(0, $cost, 'nothing charged');
    }

    public function testAnArchivedPinnedGatewayAlsoHolds(): void
    {
        $this->pin($this->otherGateway);
        db()->prepare("UPDATE ellsms_sms_gateways SET status = 'archived', config_version = config_version + 1 WHERE id = ?")->execute([$this->otherGateway]);
        $this->resetCaches();
        $resolved = gateway_for_sender($this->line, null);
        $this->assertFalse($resolved['ok']);
        $this->assertTrue($resolved['pinned']);
        $this->assertSame('pinned_gateway_unavailable', $resolved['reason']);
    }

    public function testRemovingThePinRestoresTheRoute(): void
    {
        $this->pin($this->otherGateway);
        $this->pin(null);
        $this->send('989121280005');
        $this->assertSame(1, $this->hits('/vesal/route-gw'));
        $this->assertSame(0, $this->hits('/vesal/pinned-gw'));
    }

    public function testAPinOnOneNumberDoesNotAffectAnother(): void
    {
        $this->pin($this->otherGateway);
        $this->assertSame(0, gateway_number_pinned_id('5000900923'));
        $this->assertSame($this->otherGateway, gateway_number_pinned_id($this->line));
    }
}
