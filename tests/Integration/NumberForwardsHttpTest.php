<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * /number-forwards.php over real HTTP: a customer sets the URLs of a line they own, cannot touch
 * someone else's line, and cannot set anything once the admin closes self-service; only an admin
 * changes the service settings.
 */
final class NumberForwardsHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private array $numbers = [];
    private int $adminId;
    private int $customerId;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'number\\_forward\\_%'");
        $this->adminId = $this->makeUser(true);
        $this->customerId = $this->makeUser(false);
        $this->numbers['own'] = $this->makeNumber($this->customerId);
        $this->numbers['other'] = $this->makeNumber(null);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_fwd_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20950 + random_int(0, 200);
        $env = ['APP_ENV' => 'testing', 'WEBHOOK_ALLOW_PRIVATE_TARGETS' => '1'];
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
        foreach ($this->numbers as $number) {
            db()->prepare('DELETE FROM ellsms_number_forwards WHERE number = ?')->execute([$number]);
            db()->prepare('DELETE FROM ellsms_numbers WHERE number = ?')->execute([$number]);
        }
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'number\\_forward\\_%'");
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['fwd_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function makeNumber(?int $userId): string
    {
        $number = '50008' . random_int(100000, 999999);
        db()->prepare('INSERT INTO ellsms_numbers (number, label, assigned_user_id) VALUES (?,?,?)')->execute([$number, 'fwd', $userId]);
        return $number;
    }

    private function session(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents($this->sessionDir . '/sess_' . $sid, 'uid|' . serialize($userId) . '_created_at|' . serialize($now) . '_last_activity|' . serialize($now));
        return $sid;
    }

    private function request(string $method, string $sid, array $post = [], string $query = ''): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}/number-forwards.php" . ($query !== '' ? '?' . $query : ''));
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
        $this->assertSame(200, $page['code']);
        $this->assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m), 'page has a form');
        return $m[1];
    }

    private function saved(string $number): ?array
    {
        $st = db()->prepare('SELECT * FROM ellsms_number_forwards WHERE number = ?');
        $st->execute([$number]);
        return $st->fetch() ?: null;
    }

    private function save(string $sid, string $csrf, string $number): void
    {
        $this->request('POST', $sid, [
            '_csrf' => $csrf, 'do' => 'save', 'number' => $number,
            'mo_enabled' => '1', 'mo_url' => 'http://127.0.0.1:9/mo?t=${content}', 'http_method' => 'GET',
        ]);
    }

    public function testACustomerSetsTheirOwnLineButNotAnotherOne(): void
    {
        $sid = $this->session($this->customerId);
        $page = $this->request('GET', $sid);
        $this->assertStringContainsString($this->numbers['own'], $page['body']);
        $this->assertStringNotContainsString($this->numbers['other'], $page['body'], 'only their own lines are listed');
        $this->assertStringNotContainsString('data-tab="settings"', $page['body'], 'no service settings for a customer');
        $csrf = $this->csrf($sid);

        $this->save($sid, $csrf, $this->numbers['own']);
        $own = $this->saved($this->numbers['own']);
        $this->assertNotNull($own);
        $this->assertSame(1, (int)$own['mo_enabled']);
        $this->assertSame('http://127.0.0.1:9/mo?t=${content}', $own['mo_url']);

        $this->save($sid, $csrf, $this->numbers['other']);
        $this->assertNull($this->saved($this->numbers['other']), 'a line they do not own is refused');

        $settings = $this->request('POST', $sid, ['_csrf' => $csrf, 'do' => 'settings', 'number_forward_enabled' => '0']);
        $this->assertSame(403, $settings['code']);
        $this->assertNotSame('0', setting_fresh('number_forward_enabled', '1'));
    }

    public function testTheAdminClosesSelfServiceAndSetsAnyLine(): void
    {
        $admin = $this->session($this->adminId);
        $adminCsrf = $this->csrf($admin);
        $this->request('POST', $admin, ['_csrf' => $adminCsrf, 'do' => 'settings', 'number_forward_enabled' => '1', 'number_forward_timeout_seconds' => '7']);
        $this->assertSame('0', setting_fresh('number_forward_user_edit'), 'an unticked box saves as off');
        $this->assertSame('7', setting_fresh('number_forward_timeout_seconds'));
        $this->save($admin, $adminCsrf, $this->numbers['other']);
        $this->assertNull($this->saved($this->numbers['other']), 'http:// is refused once the admin closes it');
        $this->request('POST', $admin, ['_csrf' => $adminCsrf, 'do' => 'settings', 'number_forward_enabled' => '1', 'number_forward_allow_http' => '1']);

        $customer = $this->session($this->customerId);
        $this->save($customer, $this->csrf($customer), $this->numbers['own']);
        $this->assertNull($this->saved($this->numbers['own']), 'self-service is closed');

        $this->save($admin, $adminCsrf, $this->numbers['other']);
        $this->assertNotNull($this->saved($this->numbers['other']), 'an admin sets any line');
        $edit = $this->request('GET', $admin, [], 'edit=' . $this->numbers['other']);
        $this->assertStringContainsString('http://127.0.0.1:9/mo?t=${content}', $edit['body']);
    }
}
