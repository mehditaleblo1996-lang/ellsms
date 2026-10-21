<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Delivery-based billing over real HTTP: the admin's ارسال بر اساس تحویل tab on /users.php saves the
 * switch and percentage (and refuses a bad percentage), and both that tab and the customer's own
 * /profile.php show the refunds the user received. A customer cannot switch it on for themselves.
 */
final class DeliveryBillingHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        \delivery_billing_available(true);

        $this->adminId = $this->makeCommittedUser(true);
        $this->sessionDir = sys_get_temp_dir() . '/ellsms_delivery_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 22800 + random_int(0, 400);
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
        foreach ($this->createdUserIds as $id) {
            foreach (['ellsms_delivery_refunds', 'ellsms_delivery_billing_periods', 'ellsms_delivery_billing_policies',
                      'ellsms_message_attempts', 'ellsms_wallet_transactions', 'ellsms_wallet_accounts'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
            }
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(bool $isAdmin = false, string $prefix = 'dbuser_'): int
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

    private function sessionFor(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        $encoded = '';
        foreach (['uid' => $userId, '_created_at' => $now, '_last_activity' => $now] as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, string $sessionId, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
                 CURLOPT_COOKIE => 'ELLSMS_SESSION=' . $sessionId];
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

    private function post(string $session, string $page, array $fields): array
    {
        $form = $this->request('GET', $page, $session);
        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $form['body'], $m) === 1 ? $m[1] : '';
        return $this->request('POST', (string)parse_url($page, PHP_URL_PATH), $session, ['_csrf' => $csrf] + $fields);
    }

    public function testAnAdminSwitchesItOnAndBothPagesShowTheRefunds(): void
    {
        $customer = $this->makeCommittedUser(false, 'dbcust_');
        $admin = $this->sessionFor($this->adminId);

        $page = $this->request('GET', '/users.php?edit=' . $customer, $admin);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('data-tab="delivery-billing"', $page['body']);
        $this->assertStringContainsString('name="delivery_billing_enabled"', $page['body']);

        $saved = $this->post($admin, '/users.php?edit=' . $customer, ['do' => 'delivery_billing_save', 'id' => $customer, 'back' => '1',
            'delivery_billing_enabled' => '1', 'refund_percent' => '۵۰']);
        $this->assertSame(302, $saved['code']);
        $policy = \delivery_billing_policy($customer);
        $this->assertTrue($policy['enabled']);
        $this->assertSame(50, $policy['refund_percent']);

        // A bad percentage changes nothing.
        $this->post($admin, '/users.php?edit=' . $customer, ['do' => 'delivery_billing_save', 'id' => $customer, 'back' => '1',
            'delivery_billing_enabled' => '1', 'refund_percent' => '150']);
        $this->assertSame(50, \delivery_billing_policy($customer)['refund_percent']);

        // One undelivered direct send, refunded at 50%.
        db()->prepare("INSERT INTO ellsms_message_attempts
                         (user_id, reference_type, reference_id, status, error_code, gateway_id, destination,
                          provider_message_id, delivery_status, attempted_at, completed_at, charged_credits)
                       VALUES (?, 'direct_send', 'http-ref', 'accepted', '', 1, '989121239999', 'pm-http', 'failed', NOW(), NOW(), 3000)")
            ->execute([$customer]);
        $this->assertSame('refunded', \delivery_refund_apply('attempt', (int)db()->lastInsertId())['reason']);

        $page = $this->request('GET', '/users.php?edit=' . $customer, $admin);
        $this->assertStringContainsString('989121239999', $page['body']);
        $this->assertStringContainsString('آخرین بازگشت‌ها', $page['body']);

        $own = $this->request('GET', '/profile.php', $this->sessionFor($customer));
        $this->assertSame(200, $own['code']);
        $this->assertStringContainsString('فعال — بازگشت ۵۰٪', $own['body']);
        $this->assertStringContainsString('بازگشت هزینه‌ی پیام‌های تحویل‌نشده', $own['body']);
        $this->assertStringContainsString('989121239999', $own['body']);
        $this->assertStringNotContainsString('name="delivery_billing_enabled"', $own['body'], 'the customer only sees the setting');
    }

    public function testACustomerCannotSwitchItOnThroughTheAdminForm(): void
    {
        $customer = $this->makeCommittedUser(false, 'dbself_');
        $session = $this->sessionFor($customer);

        $this->post($session, '/users.php', ['do' => 'delivery_billing_save', 'id' => $customer, 'back' => '1',
            'delivery_billing_enabled' => '1', 'refund_percent' => '100']);

        $this->assertFalse(\delivery_billing_policy($customer)['enabled']);
    }
}
