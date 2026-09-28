<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * #38 — the opt-out page over real HTTP: a customer sees only the opt-outs of lines they may send
 * from and cannot change anything; a platform admin sees every line and can remove an opt-out.
 */
final class LineOptoutsHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private array $optoutIds = [];
    private int $adminId;
    private int $customerId;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true, '');
        $this->customerId = $this->makeUser(false, '5000900601');
        foreach ([['5000900601', '989121240001'], ['5000900699', '989121240002']] as [$line, $mobile]) {
            db()->prepare("INSERT INTO ellsms_line_optouts (originator, mobile, source) VALUES (?,?,'sms')")->execute([$line, $mobile]);
            $this->optoutIds[] = (int)db()->lastInsertId();
        }

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_optout_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $this->port = 20100 + random_int(0, 200);
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
        foreach ($this->optoutIds as $id) {
            db()->prepare('DELETE FROM ellsms_line_optouts WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin, string $originator): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['optout_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, $originator]);
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

    private function request(string $method, string $sid, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}/line-optouts.php");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sid];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body];
    }

    public function testACustomerSeesOnlyTheirOwnLineAndCannotChangeAnything(): void
    {
        $sid = $this->session($this->customerId);
        $page = $this->request('GET', $sid);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('989121240001', $page['body']);
        $this->assertStringNotContainsString('989121240002', $page['body'], "another line's opt-outs leak");
        $this->assertStringNotContainsString('name="do" value="delete"', $page['body']);

        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        $post = $this->request('POST', $sid, ['_csrf' => $m[1] ?? '', 'do' => 'delete', 'id' => (string)$this->optoutIds[0]]);
        $this->assertSame(403, $post['code']);
        $this->assertSame(1, (int)db()->query('SELECT COUNT(*) FROM ellsms_line_optouts WHERE id = ' . $this->optoutIds[0])->fetchColumn());
    }

    public function testAnAdminSeesEveryLineAndCanRemoveAnOptOut(): void
    {
        $sid = $this->session($this->adminId);
        $page = $this->request('GET', $sid);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('989121240001', $page['body']);
        $this->assertStringContainsString('989121240002', $page['body']);

        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
        $this->request('POST', $sid, ['_csrf' => $m[1], 'do' => 'delete', 'id' => (string)$this->optoutIds[1]]);
        $this->assertSame(0, (int)db()->query('SELECT COUNT(*) FROM ellsms_line_optouts WHERE id = ' . $this->optoutIds[1])->fetchColumn());
    }
}
