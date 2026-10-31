<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Shared OTP panel page (public/otp-templates.php) over real HTTP: a customer registers a template, only
 * a platform admin can approve it, and only an admin can change the service settings.
 */
final class SharedOtpHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private int $adminId;
    private int $customerId;
    private int $orgId;
    private array $savedSettings = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $this->customerId = $this->makeUser(false);
        // The page needs the messages.send permission, which an organization member holds.
        $this->orgId = (int)create_organization($this->customerId, 'OTP HTTP Org ' . bin2hex(random_bytes(3)))['organization_id'];
        foreach (['shared_otp_enabled', 'shared_otp_lines', 'shared_otp_min_interval_seconds'] as $key) {
            $this->savedSettings[$key] = setting_fresh($key);
        }

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_otp_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20700 + random_int(0, 200);
        $env = ['APP_ENV' => 'testing'];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
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
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);
        foreach ($this->savedSettings as $key => $value) {
            if ($value === null) {
                db()->prepare('DELETE FROM ellsms_settings WHERE skey = ?')->execute([$key]);
            } else {
                set_setting($key, $value);
            }
        }
        foreach (['ellsms_wallet_transactions', 'ellsms_wallet_reservations', 'ellsms_wallet_accounts'] as $t) {
            db()->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$this->customerId]);
        }
        db()->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$this->orgId]);
        db()->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$this->orgId]);
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_otp_messages WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_otp_templates WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_notifications WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['otp_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function session(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents($this->sessionDir . '/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now));
        return $sid;
    }

    private function request(string $method, string $sid, array $post = [], string $query = '', string $page = '/otp-templates.php'): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$page}" . $query);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ['code' => $code, 'body' => $body];
    }

    private function csrf(string $sid): string
    {
        $page = $this->request('GET', $sid);
        $this->assertSame(200, $page['code'], mb_substr($page['body'], 0, 500));
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        return $m[1];
    }

    private function template(): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_otp_templates WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$this->customerId]);
        return $st->fetch() ?: [];
    }

    public function testACustomerRegistersATemplateAndOnlyAnAdminApprovesIt(): void
    {
        $customer = $this->session($this->customerId);
        $csrf = $this->csrf($customer);
        $this->request('POST', $customer, ['_csrf' => $csrf, 'do' => 'create', 'title' => 'ورود', 'content' => 'فروشگاه نمونه — کد شما: {code}']);
        $template = $this->template();
        $this->assertSame('pending', $template['status']);
        $this->assertSame('["code"]', $template['variables']);

        // A customer posting an admin action changes nothing.
        $this->request('POST', $customer, ['_csrf' => $csrf, 'do' => 'approve', 'id' => $template['id']]);
        $this->assertSame('pending', $this->template()['status']);
        $this->assertStringContainsString('در انتظار بررسی', $this->request('GET', $customer)['body']);

        $admin = $this->session($this->adminId);
        $page = $this->request('GET', $admin);
        $this->assertStringContainsString('فروشگاه نمونه — کد شما: {code}', $page['body'], 'pending templates are listed for the admin');
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        $this->request('POST', $admin, ['_csrf' => $m[1], 'do' => 'approve', 'id' => $template['id']]);
        $this->assertSame('approved', $this->template()['status']);
    }

    public function testOnlyAnAdminChangesTheSettings(): void
    {
        $customer = $this->session($this->customerId);
        $this->request('POST', $customer, ['_csrf' => $this->csrf($customer), 'do' => 'settings', 'shared_otp_enabled' => '1', 'shared_otp_lines' => '5000999']);
        $this->assertNotSame('5000999', setting_fresh('shared_otp_lines'));

        $admin = $this->session($this->adminId);
        $this->request('POST', $admin, ['_csrf' => $this->csrf($admin), 'do' => 'settings', 'shared_otp_enabled' => '1',
                                         'shared_otp_lines' => "۵۰۰۰۹۹۹\n5000998", 'shared_otp_min_interval_seconds' => '90']);
        $this->assertSame('1', setting_fresh('shared_otp_enabled'));
        $this->assertSame('5000999,5000998', setting_fresh('shared_otp_lines'));
        $this->assertSame('90', setting_fresh('shared_otp_min_interval_seconds'));
        $this->assertStringContainsString('در «شماره‌ها» ثبت نشده است', $this->request('GET', $admin)['body'], 'an unknown line is flagged');
    }

    public function testTheSendPagesOfferTheServiceOnlyOnceItIsOn(): void
    {
        set_setting('shared_otp_enabled', '0');
        $customer = $this->session($this->customerId);
        foreach (['/send.php', '/new-send.php'] as $page) {
            $body = $this->request('GET', $customer, [], '', $page)['body'];
            $this->assertStringNotContainsString('id="sharedOtp"', $body, $page . ' hides the box while the service is off');
        }

        set_setting('shared_otp_enabled', '1');
        set_setting('shared_otp_lines', '5000999');
        db()->prepare("INSERT INTO ellsms_otp_templates (organization_id, user_id, title, content, variables, status, created_at, updated_at)
                       VALUES (?,?,?,?,?, 'approved', NOW(), NOW())")
            ->execute([$this->orgId, $this->customerId, 'ورود', 'فروشگاه نمونه — کد شما: {code}', '["code"]']);
        $templateId = (int)db()->lastInsertId();

        foreach (['/send.php', '/new-send.php'] as $page) {
            $res = $this->request('GET', $customer, [], '', $page);
            $this->assertSame(200, $res['code'], mb_substr($res['body'], 0, 500));
            $this->assertStringContainsString('id="sharedOtp"', $res['body'], $page);
            $this->assertStringContainsString('name="otp_param[code]"', $res['body'], $page . ' asks for each variable');
            // A customer with no line of their own gets the box opened for them.
            $this->assertMatchesRegularExpression('/id="sharedOtp"[^>]*>\s*<details open/', $res['body'], $page);
        }

        // An invalid recipient is refused before anything is recorded or charged.
        preg_match('/name="_csrf" value="([^"]+)"/', $this->request('GET', $customer, [], '', '/send.php')['body'], $m);
        $res = $this->request('POST', $customer, ['_csrf' => $m[1], 'do' => 'shared_otp_send', 'otp_template_id' => $templateId,
                                                  'otp_destination' => '12345', 'otp_param' => ['code' => '4821']], '', '/send.php');
        $this->assertSame(302, $res['code']);
        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_otp_messages WHERE user_id = ?');
        $st->execute([$this->customerId]);
        $this->assertSame(0, (int)$st->fetchColumn());
        $this->assertStringContainsString('شماره‌ی موبایل نامعتبر است', $this->request('GET', $customer, [], '', '/send.php')['body']);
    }
}
