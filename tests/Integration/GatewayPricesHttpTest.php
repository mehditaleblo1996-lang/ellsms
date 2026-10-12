<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The price matrix forms over real HTTP: the global table on /sms-pricing.php?tab=matrix and an
 * account's own table on /users.php. Saving, clearing, refusing a bad value, and no form for an
 * account under a reseller.
 */
final class GatewayPricesHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $gatewayId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId = $this->makeCommittedUser(true);
        $code = 'httpgw_' . bin2hex(random_bytes(3));
        db()->prepare("INSERT INTO ellsms_sms_gateways (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version)
                       VALUES (?, 'مگفا تست', 'active', 'batch', 1, 0, 0, 1)")->execute([$code]);
        $this->gatewayId = (int)db()->lastInsertId();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_gwprice_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 22300 + random_int(0, 400);
        $env = [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
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
    }

    protected function tearDown(): void
    {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);

        $db = db();
        if ($this->gatewayId > 0) {
            $db->prepare('DELETE FROM ellsms_gateway_prices WHERE gateway_id = ?')->execute([$this->gatewayId]);
            $db->prepare('DELETE FROM ellsms_sms_gateways WHERE id = ?')->execute([$this->gatewayId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_gateway_prices WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_customer_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(bool $isAdmin = false, string $prefix = 'oppuser_'): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute([$prefix . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function writeSession(array $data): string
    {
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        foreach ($data as $key => $value) { $encoded .= $key . '|' . serialize($value); }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        return $this->writeSession(['uid' => $userId, '_created_at' => $now, '_last_activity' => $now]);
    }

    /** @return array{code:int, body:string, headers:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }

    private function csrfFor(string $path, string $sessionId): string
    {
        $page = $this->request('GET', $path, $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    /** Same local-side sequence public/users.php's create_account success branch runs, for a user
     *  already inserted directly (standing in for a completed backend_create_account() call). */
    private function mci(): string
    {
        return (string)(int)db()->query("SELECT id FROM ellsms_sms_operators WHERE code = 'mci'")->fetchColumn();
    }

    private function post(string $session, string $page, array $fields): array
    {
        $csrf = $this->csrfFor($page, $session);
        return $this->request('POST', parse_url($page, PHP_URL_PATH), $session, ['_csrf' => $csrf] + $fields);
    }

    public function testTheGlobalTableSavesAndClearsCells(): void
    {
        $session = $this->sessionFor($this->adminId);
        $page = $this->request('GET', '/sms-pricing.php?tab=matrix', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('عامل ارسال پیامک', $page['body']);
        $this->assertStringContainsString('مگفا تست', $page['body']);
        $this->assertStringContainsString('/assets/img/operators/mci-icon.png', $page['body']);
        $this->assertStringContainsString('data-fill-down="intl-en"', $page['body']);

        $mci = $this->mci();
        $saved = $this->post($session, '/sms-pricing.php?tab=matrix', ['do' => 'matrix_save', 'tab' => 'matrix',
            'price' => [$this->gatewayId => [$mci => ['fa' => '۱٬۱۲۴', 'en' => '2248'], 'intl' => ['fa' => '18000', 'en' => '']]]]);
        $this->assertSame(302, $saved['code']);
        $current = \gateway_prices_current(null);
        $this->assertSame(1124000, $current[\gateway_price_key($this->gatewayId, $mci, 'fa')] ?? null);
        $this->assertSame(2248000, $current[\gateway_price_key($this->gatewayId, $mci, 'en')] ?? null);
        $this->assertSame(18000000, $current[\gateway_price_key($this->gatewayId, 'intl', 'fa')] ?? null);
        $this->assertArrayNotHasKey(\gateway_price_key($this->gatewayId, 'intl', 'en'), $current);

        $page = $this->request('GET', '/sms-pricing.php?tab=matrix', $session);
        $this->assertMatchesRegularExpression('/name="price\[' . $this->gatewayId . '\]\[' . $mci . '\]\[fa\]"[^>]*value="1124"/', $page['body']);
    }

    public function testAnAccountTableShowsTheGlobalValueAndSavesItsOwn(): void
    {
        $customer = $this->makeCommittedUser(false, 'gwcust_');
        $session = $this->sessionFor($this->adminId);
        $mci = $this->mci();
        \gateway_prices_save(null, [\gateway_price_key($this->gatewayId, $mci, 'fa') => 1124000], $this->adminId);

        $page = $this->request('GET', '/users.php?edit=' . $customer, $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('تعرفه‌ی اختصاصی (عامل ارسال × اپراتور)', $page['body']);
        $this->assertMatchesRegularExpression('/name="price\[' . $this->gatewayId . '\]\[' . $mci . '\]\[fa\]"[^>]*placeholder="1124"/s', $page['body']);
        $this->assertMatchesRegularExpression('/class="ltr pm-cell is-default"\s+name="price\[' . $this->gatewayId . '\]\[' . $mci . '\]\[fa\]"[^>]*value="1124"/s', $page['body'], 'the default is shown, greyed, in the cell');

        // Posting the shown default unchanged saves nothing.
        $this->post($session, '/users.php?edit=' . $customer, ['do' => 'operator_prices_save', 'id' => $customer, 'back' => '1',
            'price' => [$this->gatewayId => [$mci => ['fa' => '1124', 'en' => '']]]]);
        $this->assertSame([], \gateway_prices_current($customer));

        $this->post($session, '/users.php?edit=' . $customer, ['do' => 'operator_prices_save', 'id' => $customer, 'back' => '1',
            'price' => [$this->gatewayId => [$mci => ['fa' => '900', 'en' => '']]]]);
        $this->assertSame([\gateway_price_key($this->gatewayId, $mci, 'fa') => 900000], \gateway_prices_current($customer));

        // A bad value anywhere saves nothing.
        $this->post($session, '/users.php?edit=' . $customer, ['do' => 'operator_prices_save', 'id' => $customer, 'back' => '1',
            'price' => [$this->gatewayId => [$mci => ['fa' => '', 'en' => 'abc']]]]);
        $this->assertSame([\gateway_price_key($this->gatewayId, $mci, 'fa') => 900000], \gateway_prices_current($customer));
    }

    public function testAnAccountUnderAResellerGetsNoForm(): void
    {
        $reseller = $this->makeCommittedUser(false, 'gwres_');
        $child = $this->makeCommittedUser(false, 'gwchild_');
        db()->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?, 'reseller', NULL, 0, 5, 10000), (?, 'normal', ?, 1, 0, 10000)")
            ->execute([$reseller, $child, $reseller]);
        $session = $this->sessionFor($this->adminId);

        $page = $this->request('GET', '/users.php?edit=' . $child, $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('زیرمجموعه‌ی یک نماینده است', $page['body']);
        $this->assertStringNotContainsString('name="price[', $page['body']);

        $this->post($session, '/users.php?edit=' . $child, ['do' => 'operator_prices_save', 'id' => $child, 'back' => '1',
            'price' => [$this->gatewayId => [$this->mci() => ['fa' => '500']]]]);
        $this->assertSame([], \gateway_prices_current($child));
    }
}
