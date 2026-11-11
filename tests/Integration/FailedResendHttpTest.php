<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/FailedResend.php';

/**
 * «ارسال مجدد ناموفق‌ها» through the real job page (public/bulk-job.php) on a throwaway `php -S`:
 * the customer sees the button with the right count, clicking it lands on the new p2p job (which
 * links back), the source page lists the resend, and an admin's resend is queued on the customer.
 *
 * The server is a separate process, so everything here is committed and removed in tearDown().
 */
final class FailedResendHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port = 0;
    private string $sessionDir = '';
    private int $customerId = 0;
    private int $adminId = 0;
    private int $orgId = 0;
    private string $sender = '';
    private array $jobIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $db = db();

        $this->sender = sprintf('3000%04d', random_int(0, 9999));
        $this->customerId = $this->makeUser(0, $this->sender);
        $this->adminId = $this->makeUser(1, '');
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['Resend Org', 'resend-org-' . bin2hex(random_bytes(4)), 'active', $this->customerId]);
        $this->orgId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$this->orgId, $this->customerId]);
        wallet_credit($this->customerId, 1000000, 'purchase', 'test', 'seed:' . $this->customerId, 'seed:' . $this->customerId);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_resend_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 19900 + random_int(0, 90);
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
        $jobs = $db->prepare('SELECT id FROM ellsms_bulk_jobs WHERE user_id = ?');
        $jobs->execute([$this->customerId]);
        foreach ($jobs->fetchAll(\PDO::FETCH_COLUMN) as $jobId) {
            $db->prepare('DELETE FROM ellsms_failed_resends WHERE source_job_id = ? OR new_job_id = ?')->execute([(int)$jobId, (int)$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([(int)$jobId]);
            $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([(int)$jobId]);
            $db->prepare("DELETE FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?")->execute([(string)$jobId]);
            $db->prepare("DELETE FROM ellsms_usage_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?")->execute([(string)$jobId]);
        }
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
    }

    private function makeUser(int $isAdmin, string $originator): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['resend_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $isAdmin, $originator]);
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

    /** @return array{code:int, body:string, location:string} */
    private function request(string $method, string $path, string $sid, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $location = '';
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $h) use (&$location): int {
                if (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
                return strlen($h);
            },
        ];
        if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $body, 'location' => $location];
    }

    /** A finished job of three rows: one failed, one cancelled, one sent. */
    private function seedJob(): int
    {
        $user = ['id' => $this->customerId, 'role' => 'user', 'organization_id' => $this->orgId];
        $rows = [];
        for ($i = 0; $i < 3; $i++) $rows[] = ['mobile' => sprintf('0912%07d', 2000 + $i), 'content' => "text {$i}"];
        [$ok, $msg, $jobId] = bulk_queue_job($user, 'p2p', 'HTTP resend source', $this->sender, null, $rows);
        $this->assertTrue($ok, (string)$msg);
        $ids = array_map('intval', db()->query("SELECT id FROM ellsms_bulk_items WHERE job_id = {$jobId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        db()->prepare("UPDATE ellsms_bulk_items SET status = 'failed' WHERE id = ?")->execute([$ids[0]]);
        db()->prepare("UPDATE ellsms_bulk_items SET status = 'cancelled' WHERE id = ?")->execute([$ids[1]]);
        db()->prepare("UPDATE ellsms_bulk_items SET status = 'sent' WHERE id = ?")->execute([$ids[2]]);
        db()->prepare("UPDATE ellsms_bulk_jobs SET status = 'done' WHERE id = ?")->execute([$jobId]);
        return (int)$jobId;
    }

    public function testCustomerResendsFromTheJobPageAndAdminResendsOnTheCustomersAccount(): void
    {
        $jobId = $this->seedJob();
        $customer = $this->sessionFor($this->customerId, $this->orgId);

        $page = $this->request('GET', '/bulk-job.php?id=' . $jobId, $customer);
        $this->assertSame(200, $page['code'], $page['body']);
        $this->assertStringContainsString('ارسال مجدد ناموفق‌ها (۱ پیامک)', $page['body'], 'one failed row; the cancelled row is not counted');
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';

        $post = $this->request('POST', '/bulk-job.php?id=' . $jobId, $customer, ['_csrf' => $csrf, 'do' => 'resend_failed']);
        $this->assertSame(302, $post['code'], $post['body']);
        $newId = (int)db()->query("SELECT new_job_id FROM ellsms_failed_resends WHERE source_job_id = {$jobId}")->fetchColumn();
        $this->assertGreaterThan($jobId, $newId);
        $this->assertStringContainsString('id=' . $newId, $post['location']);

        $newPage = $this->request('GET', '/bulk-job.php?id=' . $newId, $customer);
        $this->assertSame(200, $newPage['code']);
        $this->assertStringContainsString('ارسال مجدد ناموفق‌های', $newPage['body']);
        $this->assertStringContainsString('id=' . $jobId . '"', $newPage['body'], 'the new job links back to its source');
        $this->assertStringNotContainsString('name="do" value="resend_failed"', $newPage['body'], 'a pending job offers no resend');

        $source = $this->request('GET', '/bulk-job.php?id=' . $jobId, $customer);
        $this->assertStringContainsString('ارسال‌های مجدد ناموفق‌ها', $source['body']);
        $this->assertStringContainsString('قبلاً هم دوباره ارسال شده‌اند', $source['body']);

        // An admin's resend (after the 60-second guard) goes on the customer's account.
        db()->prepare('UPDATE ellsms_failed_resends SET created_at = created_at - INTERVAL 5 MINUTE WHERE source_job_id = ?')->execute([$jobId]);
        $admin = $this->sessionFor($this->adminId);
        $adminPage = $this->request('GET', '/bulk-job.php?id=' . $jobId, $admin);
        $this->assertSame(200, $adminPage['code']);
        $adminCsrf = preg_match('/name="_csrf" value="([^"]+)"/', $adminPage['body'], $m) === 1 ? $m[1] : '';
        $this->assertSame(302, $this->request('POST', '/bulk-job.php?id=' . $jobId, $admin, ['_csrf' => $adminCsrf, 'do' => 'resend_failed'])['code']);
        $log = db()->query("SELECT r.actor_user_id, j.user_id FROM ellsms_failed_resends r JOIN ellsms_bulk_jobs j ON j.id = r.new_job_id WHERE r.source_job_id = {$jobId} ORDER BY r.id DESC LIMIT 1")->fetch();
        $this->assertSame($this->adminId, (int)$log['actor_user_id']);
        $this->assertSame($this->customerId, (int)$log['user_id']);
    }
    public function testTheProviderCodeIsShownToTheAdminOnly(): void
    {
        $jobId = $this->seedJob();
        $pishgam = (int)db()->query("SELECT id FROM ellsms_sms_gateways WHERE code = 'pishgamrayan'")->fetchColumn();
        $this->assertGreaterThan(0, $pishgam);
        db()->prepare("UPDATE ellsms_bulk_items SET gateway_id = ?, provider_status = '4', delivery_status = 'sent' WHERE job_id = ? AND status = 'sent'")
            ->execute([$pishgam, $jobId]);

        $admin = $this->request('GET', '/bulk-job.php?id=' . $jobId, $this->sessionFor($this->adminId));
        $this->assertSame(200, $admin['code'], $admin['body']);
        $this->assertStringContainsString('4 — نرسیده به گوشی', $admin['body']);
        $filtered = $this->request('GET', '/bulk-job.php?id=' . $jobId . '&pcode=4', $this->sessionFor($this->adminId));
        $this->assertSame(200, $filtered['code']);
        $this->assertStringContainsString('text 2', $filtered['body']);
        $this->assertStringNotContainsString('text 0', $filtered['body'], 'the code filter keeps only code-4 rows');

        $customer = $this->request('GET', '/bulk-job.php?id=' . $jobId . '&pcode=4', $this->sessionFor($this->customerId, $this->orgId));
        $this->assertSame(200, $customer['code']);
        $this->assertStringNotContainsString('نرسیده به گوشی', $customer['body']);
        $this->assertStringNotContainsString('کد سرویس‌دهنده', $customer['body']);
        $this->assertStringContainsString('text 0', $customer['body'], 'a customer cannot filter by code');
    }
}
