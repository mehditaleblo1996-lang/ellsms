<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Voice messages over real HTTP (docs/voice-messages.md): the admin settings page, the customer's
 * send and files pages, and the public API's /api/v1/voice/* routes with their scopes — against a
 * ZarinCall-shaped API (tests/fixtures/fake_zarincall_server.php). Committed fixtures, removed in
 * tearDownAfterClass, because the app runs in a separate PHP server process.
 */
final class VoiceHttpTest extends TestCase
{
    private const MASTER_KEY = 'voice-http-master-key-0123456789abcdef';

    private static $appProc = null;
    private static $zcProc = null;
    private static int $appPort;
    private static string $zcBase;
    private static string $tmp;
    private static array $org;
    private static int $adminId;
    private static int $fileId;
    private static string $keyVoice;
    private static string $keyNoVoice;

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        putenv('SMS_GATEWAY_MASTER_KEY=' . self::MASTER_KEY);

        self::$tmp = sys_get_temp_dir() . '/ellsms_voice_http_' . bin2hex(random_bytes(5));
        mkdir(self::$tmp . '/sess', 0700, true);
        file_put_contents(self::$tmp . '/state.json', '');
        file_put_contents(self::$tmp . '/record.jsonl', '');

        $zcPort = self::freePort();
        self::$zcBase = "http://127.0.0.1:{$zcPort}/Apiv2";
        $zcEnv = getenv();
        $zcEnv['ZARINCALL_FAKE_STATE'] = self::$tmp . '/state.json';
        $zcEnv['ZARINCALL_FAKE_RECORD'] = self::$tmp . '/record.jsonl';
        self::$zcProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$zcPort}", dirname(__DIR__) . '/fixtures/fake_zarincall_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $zcEnv);
        self::waitFor($self, $zcPort);

        foreach ([
            'voice_enabled' => '1', 'voice_base_url' => self::$zcBase, 'voice_price_per_pulse' => '1000', 'voice_otp_price' => '500',
            'voice_pulse_seconds' => '30', 'voice_batch_size' => '1000', 'voice_otp_min_interval_seconds' => '0',
        ] as $k => $v) {
            set_setting($k, $v);
        }
        zarincall_api_key_save('test-key');

        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['voiceowner_' . bin2hex(random_bytes(4))]);
        $ownerId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$ownerId, IntegrationTestCase::DEFAULT_ORIGINATOR]);
        $org = create_organization($ownerId, 'Voice HTTP Org ' . bin2hex(random_bytes(3)));
        self::$org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $ownerId];
        wallet_credit($ownerId, 50000, 'purchase', 'test', 'voicehttp:' . $ownerId, 'voicehttp:' . $ownerId);

        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['voiceadmin_' . bin2hex(random_bytes(4))]);
        self::$adminId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 1, ?)')->execute([self::$adminId, '']);

        $owner = ['id' => $ownerId, 'role' => 'user', 'organization_id' => self::$org['organization_id']];
        $rate = 8000;
        $wav = 'RIFF' . pack('V', 36 + 40 * $rate) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8) . 'data' . pack('V', 40 * $rate) . str_repeat("\x00", 40 * $rate);
        $up = voice_file_upload($owner, 'خوش‌آمد', 'hello.wav', $wav);
        $self->assertTrue($up['ok'], (string)($up['error'] ?? ''));
        self::$fileId = (int)$up['id'];

        self::$keyVoice = api_key_create(self::$org['organization_id'], $ownerId, 'voice', [\ApiScopes::VOICE_SEND, \ApiScopes::VOICE_READ])['raw_key'];
        self::$keyNoVoice = api_key_create(self::$org['organization_id'], $ownerId, 'sms-only', [\ApiScopes::MESSAGES_SEND])['raw_key'];

        self::$appPort = self::freePort();
        $env = ['APP_ENV' => 'testing', 'API_ENABLED' => '1', 'API_RATE_LIMIT_PER_MINUTE' => '1000', 'API_RATE_LIMIT_BURST' => '1000',
                'SMS_GATEWAY_MASTER_KEY' => self::MASTER_KEY];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        self::$appProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . self::$tmp . '/sess', '-S', '127.0.0.1:' . self::$appPort, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::waitFor($self, self::$appPort);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$appProc, self::$zcProc] as $proc) {
            if ($proc !== null) { proc_terminate($proc); proc_close($proc); }
        }
        if (!isset(self::$org)) return;
        $orgId = self::$org['organization_id'];
        $users = [self::$org['owner_id'], self::$adminId];
        $in = implode(',', array_map('intval', $users));
        db()->exec("DELETE b FROM ellsms_voice_message_batches b JOIN ellsms_voice_messages m ON m.id = b.message_id WHERE m.user_id IN ({$in})");
        db()->exec("DELETE FROM ellsms_voice_messages WHERE user_id IN ({$in})");
        db()->exec("DELETE FROM ellsms_voice_files WHERE user_id IN ({$in})");
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'voice\\_%'");
        foreach (['ellsms_idempotency_keys', 'ellsms_api_keys', 'ellsms_organization_memberships'] as $t) {
            db()->prepare("DELETE FROM {$t} WHERE organization_id = ?")->execute([$orgId]);
        }
        foreach (['ellsms_wallet_transactions', 'ellsms_wallet_reservations', 'ellsms_wallet_accounts', 'ellsms_audit_log', 'ellsms_meta'] as $t) {
            db()->exec("DELETE FROM {$t} WHERE user_id IN ({$in})");
        }
        db()->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        db()->exec("DELETE FROM user_ WHERE id IN ({$in})");
        putenv('SMS_GATEWAY_MASTER_KEY');
        foreach (glob(self::$tmp . '/sess/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$tmp . '/sess');
        @unlink(self::$tmp . '/state.json');
        @unlink(self::$tmp . '/record.jsonl');
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

    private function session(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents(self::$tmp . '/sess/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now)
            . 'organization_id|' . serialize(self::$org['organization_id']));
        return $sid;
    }

    /** @return array{code:int, body:string, json:?array, type:string} */
    private function http(string $method, string $path, array $opts = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$appPort . $path);
        $headers = [];
        $set = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
        if (isset($opts['sid'])) $set[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $opts['sid'];
        if (isset($opts['bearer'])) $headers[] = 'Authorization: Bearer ' . $opts['bearer'];
        if (isset($opts['json'])) {
            $headers[] = 'Content-Type: application/json';
            $set[CURLOPT_POSTFIELDS] = json_encode($opts['json'], JSON_UNESCAPED_UNICODE);
        } elseif (isset($opts['form'])) {
            $set[CURLOPT_POSTFIELDS] = http_build_query($opts['form']);
        }
        $set[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $set);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true), 'type' => $type];
    }

    private function csrf(string $body): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    private function recordedActions(): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string)file_get_contents(self::$tmp . '/record.jsonl'))) as $line) {
            $out[] = basename((string)json_decode($line, true)['path']);
        }
        return $out;
    }

    public function testAdminSettingsPageSavesAndOnlyAdminsReachIt(): void
    {
        $admin = $this->session(self::$adminId);
        $page = $this->http('GET', '/voice-settings.php', ['sid' => $admin]);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('تنظیمات زرین‌کال', $page['body']);
        $this->assertStringNotContainsString('test-key', $page['body'], 'the stored key is never rendered');

        $test = $this->http('POST', '/voice-settings.php', ['sid' => $admin, 'form' => ['_csrf' => $this->csrf($page['body']), 'do' => 'test']]);
        $this->assertStringContainsString('اتصال برقرار است', $test['body']);
        $list = $this->http('POST', '/voice-settings.php', ['sid' => $admin, 'form' => ['_csrf' => $this->csrf($page['body']), 'do' => 'list_files']]);
        $voiceId = (string)db()->query('SELECT provider_voice_id FROM ellsms_voice_files WHERE id = ' . self::$fileId)->fetchColumn();
        $this->assertStringContainsString($voiceId, $list['body'], 'the ZarinCall account\'s files are listed with their status');

        $customer = $this->http('GET', '/voice-settings.php', ['sid' => $this->session(self::$org['owner_id'])]);
        $this->assertSame(403, $customer['code']);
    }

    public function testCustomerSendsFromThePanelAndListensToAFile(): void
    {
        $sid = $this->session(self::$org['owner_id']);
        $page = $this->http('GET', '/voice.php', ['sid' => $sid]);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('خوش‌آمد', $page['body']);

        $send = $this->http('POST', '/voice.php', ['sid' => $sid, 'form' => [
            '_csrf' => $this->csrf($page['body']), 'do' => 'send', 'voice_file_id' => (string)self::$fileId,
            'kind' => 'voice', 'destinations' => "09121234567\n09127654321", 'mode' => 'now',
        ]]);
        $this->assertContains($send['code'], [302, 303]);
        $st = db()->prepare("SELECT * FROM ellsms_voice_messages WHERE user_id = ? AND source = 'panel' ORDER BY id DESC LIMIT 1");
        $st->execute([self::$org['owner_id']]);
        $m = $st->fetch();
        $this->assertSame('sent', $m['status']);
        $this->assertSame(2, (int)$m['recipients_count']);
        $this->assertSame(4000, (int)$m['reserved_cost'], '40 s = 2 pulses × 1000 × 2 recipients');

        $files = $this->http('GET', '/voice-files.php', ['sid' => $sid]);
        $this->assertSame(200, $files['code']);
        $detail = $this->http('GET', '/voice.php?id=' . (int)$m['id'], ['sid' => $sid]);
        $this->assertStringContainsString('جزئیات ارسال', $detail['body']);
        foreach ([$page, $files, $detail] as $r) {
            $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Deprecated|Fatal error):/', $r['body']);
        }
        $audio = $this->http('GET', '/voice-files.php?play=' . self::$fileId, ['sid' => $sid]);
        $this->assertSame(200, $audio['code']);
        $this->assertSame('audio/wav', $audio['type']);
        $this->assertStringStartsWith('RIFF', $audio['body']);
    }

    public function testApiSendsReadsCancelsAndRequiresTheVoiceScopes(): void
    {
        $list = $this->http('GET', '/api/v1/voice/files', ['bearer' => self::$keyVoice]);
        $this->assertSame(200, $list['code'], $list['body']);
        $this->assertSame(self::$fileId, $list['json']['data'][0]['id']);
        $this->assertSame(2, $list['json']['data'][0]['pulses']);

        $denied = $this->http('GET', '/api/v1/voice/files', ['bearer' => self::$keyNoVoice]);
        $this->assertSame(403, $denied['code']);

        $send = $this->http('POST', '/api/v1/voice/messages', ['bearer' => self::$keyVoice, 'json' => [
            'voice_file_id' => self::$fileId, 'destinations' => ['09121112233', 'bad'], 'client_message_id' => 'vm-' . bin2hex(random_bytes(4)),
        ]]);
        $this->assertSame(201, $send['code'], $send['body']);
        $this->assertSame('sent', $send['json']['data']['status']);
        $this->assertSame(1, $send['json']['data']['recipients']);
        $this->assertSame(1, $send['json']['data']['invalid']);
        $id = $send['json']['data']['id'];

        $get = $this->http('GET', '/api/v1/voice/messages/' . $id, ['bearer' => self::$keyVoice]);
        $this->assertSame(200, $get['code']);
        $this->assertSame(2000, $get['json']['data']['reserved_cost']);

        $scheduled = $this->http('POST', '/api/v1/voice/messages', ['bearer' => self::$keyVoice, 'json' => [
            'voice_file_id' => self::$fileId, 'destinations' => ['09121112244'], 'scheduled_at' => date('c', time() + 7200),
        ]]);
        $this->assertSame(201, $scheduled['code'], $scheduled['body']);
        $this->assertSame('scheduled', $scheduled['json']['data']['status']);
        $cancel = $this->http('POST', '/api/v1/voice/messages/' . $scheduled['json']['data']['id'] . '/cancel', ['bearer' => self::$keyVoice, 'json' => []]);
        $this->assertSame(200, $cancel['code'], $cancel['body']);
        $this->assertSame('cancelled', $cancel['json']['data']['status']);

        $invalid = $this->http('POST', '/api/v1/voice/messages', ['bearer' => self::$keyVoice, 'json' => ['voice_file_id' => self::$fileId, 'destinations' => [], 'type' => 'ivc']]);
        $this->assertSame(422, $invalid['code']);
        $this->assertArrayHasKey('destinations', $invalid['json']['error']['fields']);
        $this->assertArrayHasKey('ivc_digit', $invalid['json']['error']['fields']);

        $missing = $this->http('POST', '/api/v1/voice/messages', ['bearer' => self::$keyVoice, 'json' => ['voice_file_id' => 999999999, 'destinations' => ['09121112233']]]);
        $this->assertSame(404, $missing['code']);

        $otp = $this->http('POST', '/api/v1/voice/otp', ['bearer' => self::$keyVoice, 'json' => ['destination' => '09121112255', 'code' => '5521']]);
        $this->assertSame(201, $otp['code'], $otp['body']);
        $this->assertSame('otp', $otp['json']['data']['kind']);
        $this->assertContains('SendOTP', $this->recordedActions());

        $badOtp = $this->http('POST', '/api/v1/voice/otp', ['bearer' => self::$keyVoice, 'json' => ['destination' => '09121112255', 'code' => 'abc']]);
        $this->assertSame(422, $badOtp['code']);
    }
}
