<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The numbers page over real HTTP: an admin pins a number to a gateway and back; a gateway whose sending
 * is switched off cannot be chosen; a customer cannot reach the page at all.
 */
final class NumberGatewayHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private array $userIds = [];
    private array $gatewayIds = [];
    private int $numberId;
    private int $adminId;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->adminId = $this->makeUser(true);
        $number = '50009' . random_int(10000, 99999);
        db()->prepare('INSERT INTO ellsms_numbers (number, label) VALUES (?,?)')->execute([$number, 'http']);
        $this->numberId = (int)db()->lastInsertId();
        $this->gatewayIds['on'] = $this->makeGateway(1);
        $this->gatewayIds['off'] = $this->makeGateway(0);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_numgw_sess_' . bin2hex(random_bytes(6));
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
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$this->numberId]);
        foreach ($this->gatewayIds as $id) {
            db()->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$id]);
        }
        foreach ($this->userIds as $id) {
            db()->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(bool $admin): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['numgw_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '']);
        $this->userIds[] = $id;
        return $id;
    }

    private function makeGateway(int $sendEnabled): int
    {
        $code = 'numgwh_' . bin2hex(random_bytes(3));
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled) VALUES (?,?, 'active', 'batch', ?)")
            ->execute([$code, 'GW ' . $code, $sendEnabled]);
        return (int)db()->lastInsertId();
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
        $ch = curl_init("http://127.0.0.1:{$this->port}/numbers.php");
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

    private function pinned(): ?int
    {
        $v = db()->query('SELECT gateway_id FROM ellsms_numbers WHERE id = ' . $this->numberId)->fetchColumn();
        return $v === null ? null : (int)$v;
    }

    public function testAnAdminPinsAndUnpinsAndCannotChooseAGatewayThatCannotSend(): void
    {
        $sid = $this->session($this->adminId);
        $page = $this->request('GET', $sid);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('درگاه ارسال', $page['body']);
        $this->assertStringContainsString('value="' . $this->gatewayIds['on'] . '"', $page['body']);
        $this->assertStringNotContainsString('value="' . $this->gatewayIds['off'] . '"', $page['body'], 'a gateway with sending off is not offered');
        preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);

        $this->request('POST', $sid, ['_csrf' => $m[1], 'do' => 'gateway', 'id' => (string)$this->numberId, 'gateway_id' => (string)$this->gatewayIds['on']]);
        $this->assertSame($this->gatewayIds['on'], $this->pinned());

        $this->request('POST', $sid, ['_csrf' => $m[1], 'do' => 'gateway', 'id' => (string)$this->numberId, 'gateway_id' => (string)$this->gatewayIds['off']]);
        $this->assertSame($this->gatewayIds['on'], $this->pinned(), 'a forged post for a disabled gateway changes nothing');

        $this->request('POST', $sid, ['_csrf' => $m[1], 'do' => 'gateway', 'id' => (string)$this->numberId, 'gateway_id' => '']);
        $this->assertNull($this->pinned());
    }

    public function testACustomerCannotChangeIt(): void
    {
        $sid = $this->session($this->makeUser(false));
        $post = $this->request('POST', $sid, ['do' => 'gateway', 'id' => (string)$this->numberId, 'gateway_id' => (string)$this->gatewayIds['on']]);
        $this->assertNotSame(200, $post['code']);
        $this->assertNull($this->pinned());
    }
}
