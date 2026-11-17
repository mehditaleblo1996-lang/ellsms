<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Aimed bulk over the public API (app/Api/Handlers/AimedBulk.php, docs/aimed-bulk.md §API) on a
 * throwaway `php -S`: scopes, the province list, the background count polled until done, a range
 * request staged by the worker pass, confirmed, and followed on /bulk-jobs/{id}/state with the
 * numbers masked; validation and cancel. Committed fixtures, removed in tearDownAfterClass.
 */
final class AimedBulkApiTest extends TestCase
{
    private const LINE = '5000900914';
    private const POSTAL = '98764';
    private const PROVINCE = 9876;
    private const MOBILES = ['989129860001', '989129860002', '989129860003'];

    private static $appProc = null;
    private static int $port;
    private static string $tmp;
    private static int $orgId;
    private static int $ownerId;
    private static string $key;
    private static string $keyNoAimed;
    private static array $saved = [];

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        $db = db();

        foreach (['aimed_bulk_enabled' => '1', 'kyc_gate.aimed_bulk' => '0', 'aimed_bulk_surcharge_percent' => '0', 'aimed_bulk_max_recipients' => '500000'] as $k => $v) {
            $st = $db->prepare('SELECT svalue FROM ellsms_settings WHERE skey = ?');
            $st->execute([$k]);
            self::$saved[$k] = $st->fetchColumn();
            set_setting($k, $v);
        }
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['aimedapi_' . bin2hex(random_bytes(4))]);
        self::$ownerId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([self::$ownerId, self::LINE]);
        self::$orgId = (int)create_organization(self::$ownerId, 'Aimed API Org ' . bin2hex(random_bytes(3)))['organization_id'];
        wallet_credit(self::$ownerId, 1000000, 'purchase', 'test', 'aimedapi:' . self::$ownerId, 'aimedapi:' . self::$ownerId);

        $db->prepare('INSERT INTO ellsms_aimed_provinces (code, name, source_id) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE name = VALUES(name)')
           ->execute([self::PROVINCE, 'استان آزمایشی']);
        $ins = $db->prepare("INSERT INTO ellsms_aimed_mobiles (mobile, postal_code, province_code, city_code, sim_type, blocklisted) VALUES (?, ?, ?, NULL, 'prepaid', 0)");
        foreach (self::MOBILES as $m) $ins->execute([$m, self::POSTAL . '00000', self::PROVINCE]);
        $db->exec('DELETE FROM ellsms_aimed_counts');

        self::$key = api_key_create(self::$orgId, self::$ownerId, 'aimed', [\ApiScopes::AIMED_SEND, \ApiScopes::AIMED_READ, \ApiScopes::BULK_READ])['raw_key'];
        self::$keyNoAimed = api_key_create(self::$orgId, self::$ownerId, 'sms-only', [\ApiScopes::MESSAGES_SEND])['raw_key'];

        self::$tmp = sys_get_temp_dir() . '/ellsms_aimed_api_' . bin2hex(random_bytes(5));
        mkdir(self::$tmp, 0700, true);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        $env = ['APP_ENV' => 'testing', 'API_ENABLED' => '1', 'API_RATE_LIMIT_PER_MINUTE' => '1000', 'API_RATE_LIMIT_BURST' => '1000'];
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $k) $env['BACKEND_DB_' . $k] = (string)getenv('BACKEND_DB_' . $k);
        self::$appProc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . self::$tmp, '-S', '127.0.0.1:' . self::$port, '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 60; $i++) {
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
            usleep(100000);
        }
        $self->fail('API server never came up');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$appProc !== null) { proc_terminate(self::$appProc); proc_close(self::$appProc); }
        if (!isset(self::$ownerId)) return;
        $db = db();
        $jobs = $db->prepare('SELECT bulk_job_id FROM ellsms_aimed_bulk_requests WHERE user_id = ? AND bulk_job_id IS NOT NULL');
        $jobs->execute([self::$ownerId]);
        foreach ($jobs->fetchAll(\PDO::FETCH_COLUMN) as $jobId) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([(int)$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([(int)$jobId]);
            $db->prepare("DELETE FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?")->execute([(string)$jobId]);
        }
        $db->prepare('DELETE FROM ellsms_aimed_bulk_requests WHERE user_id = ?')->execute([self::$ownerId]);
        $db->prepare('DELETE FROM ellsms_aimed_mobiles WHERE mobile IN (?,?,?)')->execute(self::MOBILES);
        $db->prepare('DELETE FROM ellsms_aimed_provinces WHERE code = ?')->execute([self::PROVINCE]);
        $db->exec('DELETE FROM ellsms_aimed_counts');
        // Wallet rows first: ellsms_wallet_transactions references ellsms_wallet_accounts.
        foreach (['ellsms_wallet_transactions', 'ellsms_wallet_reservations', 'ellsms_wallet_accounts', 'ellsms_audit_log', 'ellsms_meta'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([self::$ownerId]);
        }
        foreach (['ellsms_idempotency_keys', 'ellsms_api_keys', 'ellsms_usage_reservations', 'ellsms_organization_memberships', 'ellsms_wallet_accounts'] as $t) {
            $db->prepare("DELETE FROM {$t} WHERE organization_id = ?")->execute([self::$orgId]);
        }
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([self::$orgId]);
        $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([self::$ownerId]);
        foreach (self::$saved as $k => $v) {
            if ($v === false) $db->prepare('DELETE FROM ellsms_settings WHERE skey = ?')->execute([$k]);
            else set_setting($k, (string)$v);
        }
        foreach (glob(self::$tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$tmp);
    }

    /** @return array{code:int, json:array} */
    private function call(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/api/v1' . $path);
        $headers = ['Authorization: Bearer ' . ($key ?? self::$key), 'Accept: application/json'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'json' => json_decode($raw, true) ?? ['raw' => $raw]];
    }

    private function stage(): void
    {
        for ($i = 0; $i < 10 && aimed_bulk_stage_pass()['batches'] > 0; $i++) {
        }
    }

    public function testScopesAndPlaceLists(): void
    {
        $this->assertSame(403, $this->call('GET', '/aimed/provinces', null, self::$keyNoAimed)['code'], 'a key without aimed:read');
        $this->assertSame(403, $this->call('POST', '/aimed/requests', ['postal_code' => self::POSTAL, 'originator' => self::LINE, 'text' => 'x'], self::$keyNoAimed)['code']);

        $provinces = $this->call('GET', '/aimed/provinces');
        $this->assertSame(200, $provinces['code'], json_encode($provinces['json']));
        $this->assertContains(['code' => self::PROVINCE, 'name' => 'استان آزمایشی'], $provinces['json']['data']);
        $this->assertSame(200, $this->call('GET', '/aimed/provinces/' . self::PROVINCE . '/cities')['code']);
    }

    public function testCountIsPolledUntilTheWorkerHasCountedIt(): void
    {
        $first = $this->call('POST', '/aimed/count', ['postal_code' => self::POSTAL, 'prefix' => '0912']);
        $this->assertSame(202, $first['code'], json_encode($first['json']));
        $this->assertSame('counting', $first['json']['data']['status']);

        aimed_bulk_count_pass();
        $done = $this->call('POST', '/aimed/count', ['postal_code' => self::POSTAL, 'prefix' => '0912']);
        $this->assertSame(200, $done['code']);
        $this->assertSame(['done', 3, false], [$done['json']['data']['status'], $done['json']['data']['matched'], $done['json']['data']['more_than_limit']]);

        $bad = $this->call('POST', '/aimed/count', ['sim' => 'prepaid']);
        $this->assertSame(422, $bad['code'], 'the whole bank is not a target');
    }

    public function testARangeRequestIsStagedConfirmedAndFollowedWithMaskedNumbers(): void
    {
        $created = $this->call('POST', '/aimed/requests', [
            'postal_code' => self::POSTAL, 'pick' => 'range', 'range_from' => 2, 'range_to' => 3,
            'originator' => self::LINE, 'text' => 'پیام آزمایشی API', 'title' => 'api range',
        ]);
        $this->assertSame(202, $created['code'], json_encode($created['json'], JSON_UNESCAPED_UNICODE));
        $req = $created['json']['data'];
        $this->assertSame(['staging', 'range', 2, 3, 2], [$req['status'], $req['pick'], $req['range_from'], $req['range_to'], $req['count']]);
        $this->assertNull($req['total_cost'], 'not priced yet');

        $this->stage();
        $priced = $this->call('GET', '/aimed/requests/' . $req['id'])['json']['data'];
        $this->assertSame(['priced', 2], [$priced['status'], $priced['staged']]);
        $this->assertGreaterThan(0, $priced['total_cost']);
        $this->assertNull($priced['bulk_job_id'], 'no job id before confirmation');

        $confirmed = $this->call('POST', '/aimed/requests/' . $req['id'] . '/confirm', []);
        $this->assertSame(200, $confirmed['code'], json_encode($confirmed['json'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('confirmed', $confirmed['json']['data']['status']);
        $jobId = $confirmed['json']['data']['bulk_job_id'];
        $this->assertNotNull($jobId);
        $this->assertSame(409, $this->call('POST', '/aimed/requests/' . $req['id'] . '/confirm', [])['code'], 'confirmed once');

        $state = $this->call('GET', '/bulk-jobs/' . $jobId . '/state');
        $this->assertSame(200, $state['code'], json_encode($state['json']));
        $raw = json_encode($state['json']);
        $this->assertStringContainsString('0912***0002', $raw, 'rows 2..3 of the area, masked');
        $this->assertStringContainsString('0912***0003', $raw);
        foreach (self::MOBILES as $m) $this->assertStringNotContainsString($m, $raw, 'never the number itself');
    }

    public function testValidationAndCancel(): void
    {
        $bad = $this->call('POST', '/aimed/requests', ['postal_code' => self::POSTAL, 'pick' => 'range', 'range_from' => 5, 'range_to' => 2, 'originator' => self::LINE, 'text' => 'x']);
        $this->assertSame(422, $bad['code'], 'to before from');
        $this->assertSame(422, $this->call('POST', '/aimed/requests', ['postal_code' => self::POSTAL, 'text' => 'x'])['code'], 'originator required');
        $this->assertSame(404, $this->call('GET', '/aimed/requests/999999999')['code']);

        $created = $this->call('POST', '/aimed/requests', ['postal_code' => self::POSTAL, 'pick' => 'random', 'count' => 2, 'originator' => self::LINE, 'text' => 'لغو شود']);
        $this->assertSame(202, $created['code'], json_encode($created['json'], JSON_UNESCAPED_UNICODE));
        $id = $created['json']['data']['id'];
        $cancelled = $this->call('POST', '/aimed/requests/' . $id . '/cancel', []);
        $this->assertSame([200, 'cancelled'], [$cancelled['code'], $cancelled['json']['data']['status'] ?? null]);
        $this->assertSame(409, $this->call('POST', '/aimed/requests/' . $id . '/cancel', [])['code']);
    }
}
