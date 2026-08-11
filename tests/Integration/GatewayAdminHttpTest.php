<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * STEP 60/61 — the gateway admin page over REAL HTTP: who may reach it, and what a forged request
 * achieves.
 *
 * Access control is the kind of claim that must be tested through the actual web entry point. A
 * service-level test would prove that a guard function works, not that the page calls it — and "the
 * page forgot to call it" is the failure that actually happens.
 *
 * Fixtures are COMMITTED because the server is a separate process with its own connection, and are
 * removed in tearDown.
 */
final class GatewayAdminHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $customerId = 0;
    private array $createdUserIds = [];
    private array $createdGatewayIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId    = $this->makeCommittedUser(true);
        $this->customerId = $this->makeCommittedUser(false);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_gw_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19950 + random_int(0, 400);
        $env = [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'SMS_GATEWAY_MASTER_KEY' => 'http-test-master-key-0123456789abcdefgh',
        ];
        $this->serverProc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertNotFalse($this->serverProc, 'could not start throwaway PHP dev server');

        $booted = false;
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $this->assertTrue($booted, 'throwaway dev server never accepted connections');

        // Rate limiting is per-IP and every request here comes from 127.0.0.1, so a previous test's
        // bucket would otherwise leak into this one.
        db()->exec('DELETE FROM ellsms_rate_limits');
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $file) { @unlink($file); }
        @rmdir($this->sessionDir);

        $db = db();
        foreach ($this->createdGatewayIds as $gatewayId) {
            $db->prepare('DELETE FROM ellsms_sms_gateway_parameters WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_secrets WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_operators WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_send_connectors WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_status_connectors WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateway_config_audit WHERE gateway_id = ?')->execute([$gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$gatewayId]);
        }
        // Anything the page itself created during a successful admin POST.
        $db->exec("DELETE p FROM ellsms_sms_gateway_parameters p JOIN ellsms_sms_gateways g ON g.id = p.gateway_id WHERE g.code LIKE 'httpgw%'");
        $db->exec("DELETE c FROM ellsms_sms_gateway_send_connectors c JOIN ellsms_sms_gateways g ON g.id = c.gateway_id WHERE g.code LIKE 'httpgw%'");
        $db->exec("DELETE a FROM ellsms_sms_gateway_config_audit a JOIN ellsms_sms_gateways g ON g.id = a.gateway_id WHERE g.code LIKE 'httpgw%'");
        $db->exec("DELETE FROM ellsms_sms_gateways WHERE code LIKE 'httpgw%'");

        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    /* ================= tests ================= */

    public function testAPlatformAdminCanOpenTheGatewayPage(): void
    {
        $response = $this->request('GET', '/sms-gateways.php', $this->sessionFor($this->adminId));

        $this->assertSame(200, $response['code']);
        $this->assertStringContainsString('درگاه‌های پیامک', $response['body']);
    }

    public function testACustomerCannotOpenTheGatewayPage(): void
    {
        $response = $this->request('GET', '/sms-gateways.php', $this->sessionFor($this->customerId));

        // require_admin() answers a logged-in non-admin with 403 rather than a redirect — there is
        // nowhere useful to send them, and a redirect would imply the page might become reachable.
        $this->assertSame(403, $response['code']);
        $this->assertStringNotContainsString('درگاه جدید', $response['body']);
    }

    public function testAnAnonymousVisitorCannotOpenTheGatewayPage(): void
    {
        $response = $this->request('GET', '/sms-gateways.php');

        $this->assertSame(302, $response['code']);
    }

    public function testACustomerPostCannotCreateAGateway(): void
    {
        $session = $this->sessionFor($this->customerId);
        $before = $this->gatewayCount();

        $response = $this->request('POST', '/sms-gateways.php', $session, [
            '_csrf' => 'anything', 'do' => 'gateway_create', 'code' => 'httpgw_forged', 'name' => 'forged',
        ]);

        $this->assertSame(403, $response['code']);
        $this->assertSame($before, $this->gatewayCount(), 'a forged POST from a customer must write nothing');
    }

    public function testAPostWithoutAValidCsrfTokenIsRejected(): void
    {
        $session = $this->sessionFor($this->adminId);
        $before = $this->gatewayCount();

        $response = $this->request('POST', '/sms-gateways.php', $session, [
            '_csrf' => 'not-the-real-token', 'do' => 'gateway_create', 'code' => 'httpgw_nocsrf', 'name' => 'no csrf',
        ]);

        $this->assertNotSame(200, $response['code']);
        $this->assertSame($before, $this->gatewayCount(), 'CSRF enforcement must stop the write, not merely warn');
    }

    public function testAnAdminCanCreateAGatewayAndItsConfigVersionIsRecorded(): void
    {
        $session = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor($session);
        $this->assertNotSame('', $csrf, 'the page must render a CSRF token');

        $code = 'httpgw_' . bin2hex(random_bytes(3));
        $response = $this->request('POST', '/sms-gateways.php', $session, [
            '_csrf' => $csrf, 'do' => 'gateway_create', 'code' => $code, 'name' => 'HTTP test gateway', 'send_mode' => 'batch',
        ]);

        $this->assertSame(302, $response['code']);
        $row = $this->gatewayByCode($code);
        $this->assertNotNull($row);
        $this->assertSame('batch', $row['send_mode']);
        // Creation bumps the version, which is what makes a worker notice the new gateway.
        $this->assertGreaterThanOrEqual(2, (int)$row['config_version']);

        $audit = db()->prepare('SELECT change_type FROM ellsms_sms_gateway_config_audit WHERE gateway_id = ?');
        $audit->execute([(int)$row['id']]);
        $this->assertContains('gateway.create', array_column($audit->fetchAll(), 'change_type'));
    }

    public function testAStoredSecretIsNeverRenderedBackIntoThePage(): void
    {
        $session = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor($session);
        $code = 'httpgw_' . bin2hex(random_bytes(3));
        $this->request('POST', '/sms-gateways.php', $session, [
            '_csrf' => $csrf, 'do' => 'gateway_create', 'code' => $code, 'name' => 'secret page test',
        ]);
        $gatewayId = (int)$this->gatewayByCode($code)['id'];

        $this->request('POST', '/sms-gateways.php', $session, [
            '_csrf' => $this->csrfFor($session), 'do' => 'secret_save', 'gateway_id' => $gatewayId,
            'tab' => 'secrets', 'secret_key' => 'api_token', 'secret_value' => 'never-render-this-value',
        ]);

        $page = $this->request('GET', "/sms-gateways.php?gateway={$gatewayId}&tab=secrets", $session);

        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('api_token', $page['body'], 'the key NAME is what an admin needs to see');
        $this->assertStringNotContainsString('never-render-this-value', $page['body'], 'a stored credential must never be rendered back');
    }

    /* ================= helpers ================= */

    private function gatewayCount(): int
    {
        return (int)db()->query('SELECT COUNT(*) FROM ellsms_sms_gateways')->fetchColumn();
    }

    private function gatewayByCode(string $code): ?array
    {
        $st = db()->prepare('SELECT * FROM ellsms_sms_gateways WHERE code = ?');
        $st->execute([$code]);
        $row = $st->fetch();
        if ($row) {
            $this->createdGatewayIds[] = (int)$row['id'];
        }
        return $row ?: null;
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute([($isAdmin ? 'gwadmin_' : 'gwuser_') . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        $encoded = '';
        foreach (['uid' => $userId, '_created_at' => $now, '_last_activity' => $now] as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        $sid = bin2hex(random_bytes(16));
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function csrfFor(string $sessionId): string
    {
        $page = $this->request('GET', '/sms-gateways.php', $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $options[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $options);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $headerSize)];
    }
}
