<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Aimed bulk through the real pages (public/aimed-bulk.php, public/bulk-job.php) on a throwaway
 * `php -S`: a customer creates a request, the worker pass stages it, the customer confirms it, and
 * the job's recipients are shown masked to the customer but in full to a platform admin.
 *
 * The server is a separate process, so everything here is committed and removed in tearDown().
 */
final class AimedBulkHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port = 0;
    private string $sessionDir = '';
    private int $customerId = 0;
    private int $adminId = 0;
    private int $orgId = 0;
    private array $mobiles = ['989129870001', '989129870002', '989129870003'];
    private array $savedSettings = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $db = db();

        foreach (['aimed_bulk_enabled' => '1', 'kyc_gate.aimed_bulk' => '0', 'aimed_bulk_surcharge_percent' => '0'] as $key => $value) {
            $st = $db->prepare('SELECT svalue FROM ellsms_settings WHERE skey = ?');
            $st->execute([$key]);
            $this->savedSettings[$key] = $st->fetchColumn();
            set_setting($key, $value);
        }
        $this->customerId = $this->makeUser(0);
        $this->adminId = $this->makeUser(1);
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['Aimed Org', 'aimed-org-' . bin2hex(random_bytes(4)), 'active', $this->customerId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$this->orgId, $this->customerId]);
        wallet_credit($this->customerId, 1000000, 'purchase', 'test', 'seed:' . $this->customerId, 'seed:' . $this->customerId);
        $ins = $db->prepare("INSERT INTO ellsms_aimed_mobiles (mobile, postal_code, province_code, city_code, sim_type, blocklisted) VALUES (?, '9876500000', 1, 101, 'prepaid', 0)");
        foreach ($this->mobiles as $m) $ins->execute([$m]);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_aimed_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19800 + random_int(0, 90);
        $env = ['APP_ENV' => 'testing'];
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASS'] as $k) $env['BACKEND_DB_' . $k] = (string)getenv('BACKEND_DB_' . $k);
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env
        );
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $this->fail('throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->sessionDir);

        $db = db();
        $jobs = $db->prepare('SELECT bulk_job_id FROM ellsms_aimed_bulk_requests WHERE user_id = ? AND bulk_job_id IS NOT NULL');
        $jobs->execute([$this->customerId]);
        foreach ($jobs->fetchAll(\PDO::FETCH_COLUMN) as $jobId) {
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([(int)$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([(int)$jobId]);
            $db->prepare("DELETE FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?")->execute([(string)$jobId]);
        }
        $db->prepare('DELETE FROM ellsms_aimed_bulk_requests WHERE user_id = ?')->execute([$this->customerId]);
        $db->prepare('DELETE FROM ellsms_aimed_mobiles WHERE mobile IN (?,?,?)')->execute($this->mobiles);
        $db->prepare('DELETE FROM ellsms_usage_reservations WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$this->orgId]);
        $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->orgId]);
        foreach ([$this->customerId, $this->adminId] as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
        foreach ($this->savedSettings as $key => $value) {
            if ($value === false) $db->prepare('DELETE FROM ellsms_settings WHERE skey = ?')->execute([$key]);
            else set_setting($key, (string)$value);
        }
    }

    private function makeUser(int $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['aimed_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, '5000900913']);
        return $id;
    }

    private function sessionFor(int $userId, ?int $organizationId = null): string
    {
        $now = time();
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        $data = ['uid' => $userId, '_created_at' => $now, '_last_activity' => $now] + ($organizationId !== null ? ['organization_id' => $organizationId] : []);
        foreach ($data as $key => $value) $encoded .= $key . '|' . serialize($value);
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, string $sid, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid];
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }

    private function csrf(string $sid): string
    {
        $page = $this->request('GET', '/aimed-bulk.php', $sid);
        $this->assertSame(200, $page['code'], $page['body']);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    public function testACustomerCreatesAndConfirmsAndSeesOnlyMaskedNumbers(): void
    {
        $customer = $this->sessionFor($this->customerId, $this->orgId);
        $admin = $this->sessionFor($this->adminId);

        $adminPage = $this->request('GET', '/aimed-bulk.php', $admin);
        $this->assertSame(200, $adminPage['code']);
        $this->assertStringContainsString('تنظیمات ارسال با کد پستی', $adminPage['body']);

        $csrf = $this->csrf($customer);
        $this->assertStringNotContainsString('تنظیمات ارسال با کد پستی', $this->request('GET', '/aimed-bulk.php', $customer)['body'], 'settings are admin-only');
        $created = $this->request('POST', '/aimed-bulk.php', $customer, [
            '_csrf' => $csrf, 'action' => 'create', 'postal_code' => '98765', 'sim' => 'all',
            'originator' => '5000900913', 'content' => 'پیام آزمایشی', 'count' => '',
        ]);
        $this->assertSame(302, $created['code'], $created['body']);

        $st = db()->prepare('SELECT id FROM ellsms_aimed_bulk_requests WHERE user_id = ?');
        $st->execute([$this->customerId]);
        $requestId = (int)$st->fetchColumn();
        $this->assertGreaterThan(0, $requestId, 'the request was stored');
        for ($i = 0; $i < 10 && aimed_bulk_stage_pass()['batches'] > 0; $i++) {
        }
        $this->assertStringContainsString('منتظر تأیید', $this->request('GET', '/aimed-bulk.php', $customer)['body']);

        $confirmed = $this->request('POST', '/aimed-bulk.php', $customer, ['_csrf' => $csrf, 'action' => 'confirm', 'id' => (string)$requestId]);
        $this->assertSame(302, $confirmed['code']);
        $st = db()->prepare('SELECT r.status, r.bulk_job_id, j.status AS job_status FROM ellsms_aimed_bulk_requests r JOIN ellsms_bulk_jobs j ON j.id = r.bulk_job_id WHERE r.id = ?');
        $st->execute([$requestId]);
        $row = $st->fetch();
        $this->assertSame(['confirmed', 'pending'], [$row['status'], $row['job_status']]);

        $customerJob = $this->request('GET', '/bulk-job.php?id=' . (int)$row['bulk_job_id'], $customer);
        $this->assertSame(200, $customerJob['code']);
        $this->assertStringContainsString('0912***0001', $customerJob['body']);
        foreach ($this->mobiles as $m) $this->assertStringNotContainsString($m, $customerJob['body']);
        $this->assertStringNotContainsString('name="dest"', $customerJob['body'], 'no number search on a masked job');

        $search = $this->request('GET', '/bulk-job.php?id=' . (int)$row['bulk_job_id'] . '&dest=9870002', $customer);
        $this->assertStringContainsString('0912***0001', $search['body'], 'a typed number filter is ignored for a masked job');

        $adminJob = $this->request('GET', '/bulk-job.php?id=' . (int)$row['bulk_job_id'], $admin);
        $this->assertStringContainsString('989129870001', $adminJob['body']);

        // The bulk report's rows are a lazily loaded part (app/LazyParts.php).
        $reports = $this->request('GET', '/reports-bulk.php?_part=rows', $customer);
        $this->assertSame(200, $reports['code']);
        $this->assertStringContainsString('0912***0001', $reports['body']);
        foreach ($this->mobiles as $m) $this->assertStringNotContainsString($m, $reports['body']);
    }
}
