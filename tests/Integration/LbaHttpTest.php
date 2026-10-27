<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * LBA over real HTTP (docs/lba.md): the admin LBA gateways page, the numbers page column, and the
 * customer's LBA page — creating an audience, seeing it built, and sending — against a PishgamRayan
 * smart-SMS-shaped server (tests/fixtures/fake_pishgam_smartsms_server.php). Committed fixtures,
 * removed in tearDownAfterClass, because the app runs in a separate PHP server process.
 */
final class LbaHttpTest extends TestCase
{
    private const MASTER_KEY = 'lba-http-master-key-0123456789abcdef!!';
    private const LINE = '5000800811';

    private static $appProc = null;
    private static $fakeProc = null;
    private static int $appPort;
    private static string $tmp;
    private static array $org;
    private static int $adminId;
    private static int $plainId;
    private static int $gatewayId;
    private static int $numberId;

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        putenv('SMS_GATEWAY_MASTER_KEY=' . self::MASTER_KEY);
        unset($GLOBALS['__lba_tables_ready']);

        self::$tmp = sys_get_temp_dir() . '/ellsms_lba_http_' . bin2hex(random_bytes(5));
        mkdir(self::$tmp . '/sess', 0700, true);
        file_put_contents(self::$tmp . '/record.jsonl', '');

        $fakePort = self::freePort();
        $fakeEnv = getenv();
        $fakeEnv['ELLSMS_RECORDER_FILE'] = self::$tmp . '/record.jsonl';
        self::$fakeProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$fakePort}", dirname(__DIR__) . '/fixtures/fake_pishgam_smartsms_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $fakeEnv);
        self::waitFor($self, $fakePort);

        set_setting('kyc_gate.lba', '0');
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['lbaowner_' . bin2hex(random_bytes(4))]);
        $ownerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$ownerId, self::LINE]);
        $org = create_organization($ownerId, 'LBA HTTP Org ' . bin2hex(random_bytes(3)));
        self::$org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $ownerId];
        wallet_credit($ownerId, 1000000, 'purchase', 'test', 'lbahttp:' . $ownerId, 'lbahttp:' . $ownerId);

        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['lbaadmin_' . bin2hex(random_bytes(4))]);
        self::$adminId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 1, ?)')->execute([self::$adminId, '']);
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['lbaplain_' . bin2hex(random_bytes(4))]);
        self::$plainId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([self::$plainId, IntegrationTestCase::DEFAULT_ORIGINATOR]);

        $saved = lba_gateway_save(['name' => 'پیشگام HTTP', 'code' => 'pghttp_' . bin2hex(random_bytes(3)), 'base_url' => "http://127.0.0.1:{$fakePort}",
            'enabled' => '1', 'token' => 'test-lba-token', 'price_fa_part' => '300', 'price_en_part' => '500'], self::$adminId);
        $self->assertTrue($saved['ok'], (string)($saved['error'] ?? ''));
        self::$gatewayId = (int)$saved['id'];
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([self::LINE, 'LBA HTTP', $ownerId]);
        self::$numberId = (int)db()->lastInsertId();

        self::$appPort = self::freePort();
        $env = ['APP_ENV' => 'testing', 'SMS_GATEWAY_MASTER_KEY' => self::MASTER_KEY];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            if (getenv($k) !== false) $env[$k] = (string)getenv($k);
        }
        self::$appProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . self::$tmp . '/sess', '-S', '127.0.0.1:' . self::$appPort, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::waitFor($self, self::$appPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$appProc, self::$fakeProc] as $proc) {
            if ($proc !== null) { proc_terminate($proc); proc_close($proc); }
        }
        if (!isset(self::$org)) return;
        $users = implode(',', array_map('intval', [self::$org['owner_id'], self::$adminId, self::$plainId]));
        db()->exec("DELETE FROM ellsms_lba_sends WHERE user_id IN ({$users})");
        db()->exec("DELETE FROM ellsms_lba_requests WHERE user_id IN ({$users})");
        db()->exec("DELETE FROM ellsms_lba_campaigns WHERE user_id IN ({$users})");
        db()->exec("DELETE FROM ellsms_lba_clubs WHERE user_id IN ({$users})");
        db()->prepare('DELETE FROM ellsms_lba_gateway_secrets WHERE gateway_id = ?')->execute([self::$gatewayId]);
        db()->prepare('DELETE FROM ellsms_lba_gateways WHERE id = ?')->execute([self::$gatewayId]);
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([self::$numberId]);
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'lba\\_cache.%' OR skey = 'kyc_gate.lba'");
        db()->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([self::$org['organization_id']]);
        foreach (['ellsms_wallet_transactions', 'ellsms_wallet_reservations', 'ellsms_wallet_accounts', 'ellsms_audit_log', 'ellsms_meta'] as $t) {
            db()->exec("DELETE FROM {$t} WHERE user_id IN ({$users})");
        }
        db()->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([self::$org['organization_id']]);
        db()->exec("DELETE FROM user_ WHERE id IN ({$users})");
        putenv('SMS_GATEWAY_MASTER_KEY');
        foreach (glob(self::$tmp . '/sess/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$tmp . '/sess');
        @unlink(self::$tmp . '/record.jsonl');
        @unlink(self::$tmp . '/record.jsonl.state');
        @rmdir(self::$tmp);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    private static function waitFor(TestCase $t, int $port): void
    {
        for ($i = 0; $i < 60; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(100000);
        }
        $t->fail("server on port {$port} never came up");
    }

    private function session(int $userId, ?int $orgId = null): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents(self::$tmp . '/sess/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now)
            . ($orgId !== null ? 'organization_id|' . serialize($orgId) : ''));
        return $sid;
    }

    /** @return array{code:int, body:string, headers:string} */
    private function http(string $method, string $path, string $sid, ?array $form = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$appPort . $path);
        $set = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
            CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid];
        if ($form !== null) $set[CURLOPT_POSTFIELDS] = http_build_query($form);
        curl_setopt_array($ch, $set);
        $raw = (string)curl_exec($ch);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
    }

    private function csrf(string $body): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    public function testAdminPinsTheGatewayToANumberAndOnlyAdminsReachThePage(): void
    {
        $admin = $this->session(self::$adminId);
        $page = $this->http('GET', '/lba-gateways.php', $admin);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('درگاه‌های LBA (ارسال پیام منطقه‌ای)', $page['body']);
        $this->assertStringContainsString(self::LINE, $page['body']);
        $this->assertStringNotContainsString('test-lba-token', $page['body'], 'the token is never rendered');

        $pin = $this->http('POST', '/lba-gateways.php', $admin, ['_csrf' => $this->csrf($page['body']), 'do' => 'number', 'number_id' => (string)self::$numberId, 'lba_gateway_id' => (string)self::$gatewayId]);
        $this->assertSame(302, $pin['code']);
        $this->assertSame(self::$gatewayId, (int)db()->query('SELECT lba_gateway_id FROM ellsms_numbers WHERE id = ' . self::$numberId)->fetchColumn());

        $test = $this->http('POST', '/lba-gateways.php', $admin, ['_csrf' => $this->csrf($this->http('GET', '/lba-gateways.php', $admin)['body']), 'do' => 'test', 'gateway_id' => (string)self::$gatewayId]);
        $this->assertSame(302, $test['code']);
        $this->assertStringContainsString('اتصال برقرار است', $this->http('GET', '/lba-gateways.php?edit=' . self::$gatewayId, $admin)['body']);

        $numbers = $this->http('GET', '/numbers.php', $admin);
        $this->assertStringContainsString('درگاه LBA', $numbers['body']);
        $this->assertSame(403, $this->http('GET', '/lba-gateways.php', $this->session(self::$org['owner_id'], self::$org['organization_id']))['code']);
    }

    #[Depends('testAdminPinsTheGatewayToANumberAndOnlyAdminsReachThePage')]
    public function testCustomerCreatesAnAudienceAndSendsFromThePage(): void
    {
        $sid = $this->session(self::$org['owner_id'], self::$org['organization_id']);
        $new = $this->http('GET', '/lba.php?tab=new', $sid);
        $this->assertSame(200, $new['code']);
        $this->assertStringContainsString('ارسال پیام منطقه‌ای (LBA)', $new['body'], 'menu entry');
        $this->assertStringContainsString('data-lba-map', $new['body']);
        $this->assertStringContainsString('گیلان', $new['body'], 'provinces from the gateway');
        $this->assertStringContainsString('Samsung', $new['body'], 'device brands from the gateway');
        $this->assertMatchesRegularExpression("#Content-Security-Policy:[^\n]*img-src 'self' data: https://tile\\.openstreetmap\\.org;#i", $new['headers']);

        $create = $this->http('POST', '/lba.php', $sid, [
            '_csrf' => $this->csrf($new['body']), 'do' => 'request_create', 'line' => self::LINE, 'kind' => 'simple', 'title' => 'آزمایش HTTP',
            'operators' => ['1'], 'start' => lba_jalali(time() + 60), 'end' => lba_jalali(time() + 7200),
            'polygons' => json_encode([[[51.3753, 35.7448], [51.3760, 35.7440], [51.3745, 35.7432]]]), 'gender' => ['1'],
        ]);
        $this->assertSame(302, $create['code']);
        $st = db()->prepare('SELECT * FROM ellsms_lba_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([self::$org['owner_id']]);
        $req = $st->fetch();
        $this->assertNotFalse($req, 'request created');
        $this->assertSame(self::$org['organization_id'], (int)$req['organization_id']);

        $refresh = $this->http('POST', '/lba.php', $sid, ['_csrf' => $this->csrf($this->http('GET', '/lba.php', $sid)['body']), 'do' => 'request_refresh', 'line' => self::LINE, 'id' => (string)$req['id']]);
        $this->assertSame(302, $refresh['code']);
        $detail = $this->http('GET', '/lba.php?request=' . (int)$req['id'], $sid);
        $this->assertStringContainsString('آماده‌ی ارسال', $detail['body']);
        $this->assertStringContainsString('ارسال یکباره', $detail['body']);

        $segment = (int)$req['provider_request_id'] * 10 + 1;
        $before = (int)wallet_balance(self::$org['owner_id'])['available'];
        $send = $this->http('POST', '/lba.php', $sid, ['_csrf' => $this->csrf($detail['body']), 'do' => 'send_simple', 'line' => self::LINE, 'id' => (string)$req['id'],
            'operator' => '1', 'from_' . $segment => '1', 'count_' . $segment => '10', 'body' => 'سلام']);
        $this->assertSame(302, $send['code']);
        $this->assertSame($before - 3000, (int)wallet_balance(self::$org['owner_id'])['available'], '10 × 1 part × 300');
        $after = $this->http('GET', '/lba.php?request=' . (int)$req['id'], $sid);
        $this->assertStringContainsString('ثبت شد', $after['body']);
    }

    public function testACustomerWithoutAnLbaLineIsToldSo(): void
    {
        $sid = $this->session(self::$plainId);
        $page = $this->http('GET', '/lba.php', $sid);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('هیچ‌کدام از خط‌های شما درگاه LBA ندارد', $page['body']);
        $dash = $this->http('GET', '/index.php', $sid);
        $this->assertStringNotContainsString('href="/lba.php"', $dash['body'], 'no menu entry');
    }
}
