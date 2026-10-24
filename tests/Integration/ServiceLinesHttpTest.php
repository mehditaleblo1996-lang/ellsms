<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Service lines on /numbers.php over real HTTP (docs/service-lines.md): a platform admin sees the
 * flag on every line, filters by it, and switches it on and off (audited); a customer cannot.
 */
final class ServiceLinesHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private array $numberIds = [];
    private int $adminId;
    private int $customerId;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $this->customerId = $this->makeUser(false);
        foreach ([['5000900901', 1], ['5000900902', 0]] as [$number, $bypass]) {
            db()->prepare('INSERT INTO ellsms_numbers (number, bypass_blacklist) VALUES (?,?)')->execute([$number, $bypass]);
            $this->numberIds[$number] = (int)db()->lastInsertId();
        }

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_svcline_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20400 + random_int(0, 200);
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
        foreach ($this->numberIds as $id) {
            db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['svcline_' . bin2hex(random_bytes(5))]);
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

    private function request(string $method, string $sid, string $query = '', array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}/numbers.php" . $query);
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

    private function flag(string $number): int
    {
        return (int)db()->query('SELECT bypass_blacklist FROM ellsms_numbers WHERE id = ' . $this->numberIds[$number])->fetchColumn();
    }

    private function csrf(string $body): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $m);
        return $m[1] ?? '';
    }

    public function testTheAdminListShowsAndFiltersServiceLines(): void
    {
        $sid = $this->session($this->adminId);
        $all = $this->request('GET', $sid);
        $this->assertSame(200, $all['code']);
        $this->assertStringContainsString('خدماتی (عبور از لیست سیاه)', $all['body']);
        $this->assertStringContainsString('5000900901', $all['body']);
        $this->assertStringContainsString('5000900902', $all['body']);

        $only = $this->request('GET', $sid, '?bypass=1');
        $this->assertStringContainsString('5000900901', $only['body']);
        $this->assertStringNotContainsString('5000900902', $only['body']);

        $none = $this->request('GET', $sid, '?bypass=0');
        $this->assertStringNotContainsString('5000900901', $none['body']);
        $this->assertStringContainsString('5000900902', $none['body']);
    }

    public function testAnAdminSwitchesTheFlagOnAndOffAndEachChangeIsAudited(): void
    {
        $sid = $this->session($this->adminId);
        $csrf = $this->csrf($this->request('GET', $sid)['body']);
        $id = (string)$this->numberIds['5000900902'];

        $this->request('POST', $sid, '', ['_csrf' => $csrf, 'do' => 'bypass_blacklist', 'id' => $id, 'value' => '1']);
        $this->assertSame(1, $this->flag('5000900902'));
        // Set, not flipped: the same form submitted twice leaves it on.
        $this->request('POST', $sid, '', ['_csrf' => $csrf, 'do' => 'bypass_blacklist', 'id' => $id, 'value' => '1']);
        $this->assertSame(1, $this->flag('5000900902'));

        $this->request('POST', $sid, '', ['_csrf' => $csrf, 'do' => 'bypass_blacklist', 'id' => $id, 'value' => '0']);
        $this->assertSame(0, $this->flag('5000900902'));

        $st = db()->prepare("SELECT action FROM ellsms_audit_log WHERE user_id = ? AND action LIKE 'number.bypass_blacklist%' ORDER BY id");
        $st->execute([$this->adminId]);
        $this->assertSame(['number.bypass_blacklist_on', 'number.bypass_blacklist_on', 'number.bypass_blacklist_off'], $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testACustomerCannotChangeTheFlag(): void
    {
        $sid = $this->session($this->customerId);
        $page = $this->request('GET', $sid);
        $this->assertSame(403, $page['code']);
        $post = $this->request('POST', $sid, '', ['do' => 'bypass_blacklist', 'id' => (string)$this->numberIds['5000900902'], 'value' => '1']);
        $this->assertSame(403, $post['code']);
        $this->assertSame(0, $this->flag('5000900902'));
    }
}
