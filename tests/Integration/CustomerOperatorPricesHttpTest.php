<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The "تعرفه‌ی اختصاصی" tab of /users.php over real HTTP: an admin saves per-operator prices, an
 * empty field removes one, a bad value saves nothing, and an account under a reseller gets no form.
 */
final class CustomerOperatorPricesHttpTest extends TestCase
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

        $this->adminId = $this->makeCommittedUser(true);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_opprice_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 21300 + random_int(0, 400);
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
            $db->prepare('DELETE FROM ellsms_customer_operator_prices WHERE user_id = ?')->execute([$id]);
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
    private function operatorId(string $code): int
    {
        $st = db()->prepare('SELECT id FROM ellsms_sms_operators WHERE code = ?');
        $st->execute([$code]);
        return (int)$st->fetchColumn();
    }

    private function save(string $session, int $userId, array $prices): array
    {
        $csrf = $this->csrfFor('/users.php?edit=' . $userId, $session);
        return $this->request('POST', '/users.php', $session, [
            '_csrf' => $csrf, 'do' => 'operator_prices_save', 'id' => $userId, 'back' => '1', 'price' => $prices,
        ]);
    }

    public function testAnAdminSetsAndRemovesPricesFromTheUserPage(): void
    {
        $customer = $this->makeCommittedUser(false, 'oppcust_');
        $session = $this->sessionFor($this->adminId);
        $mci = $this->operatorId('mci');
        $mtn = $this->operatorId('mtn');

        $page = $this->request('GET', '/users.php?edit=' . $customer, $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('تعرفه‌ی اختصاصی هر اپراتور', $page['body']);
        $this->assertStringContainsString('name="price[other]"', $page['body']);

        $saved = $this->save($session, $customer, [(string)$mci => '۸۵۰', (string)$mtn => '1,100', 'other' => '']);
        $this->assertSame(302, $saved['code']);
        $current = [];
        foreach (\customer_operator_prices_current($customer) as $row) {
            $current[$row['operator_id'] ?? 'other'] = $row['millicredits'];
        }
        $this->assertSame([$mci => 850000, $mtn => 1100000], $current);

        // The saved values come back pre-filled.
        $page = $this->request('GET', '/users.php?edit=' . $customer, $session);
        $this->assertMatchesRegularExpression('/name="price\[' . $mci . '\]"[^>]*value="850"/', $page['body']);

        // An empty field removes that operator's price; the other one stays.
        $this->save($session, $customer, [(string)$mci => '', (string)$mtn => '1100']);
        $rows = \customer_operator_prices_current($customer);
        $this->assertCount(1, $rows);
        $this->assertSame($mtn, $rows[0]['operator_id']);
    }

    public function testABadValueSavesNothing(): void
    {
        $customer = $this->makeCommittedUser(false, 'oppbad_');
        $session = $this->sessionFor($this->adminId);
        $this->save($session, $customer, [(string)$this->operatorId('mci') => '900', (string)$this->operatorId('mtn') => 'abc']);
        $this->assertSame([], \customer_operator_prices_current($customer));
    }

    public function testAnAccountUnderAResellerGetsNoForm(): void
    {
        $reseller = $this->makeCommittedUser(false, 'oppres_');
        $child = $this->makeCommittedUser(false, 'oppchild_');
        db()->prepare("INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?, 'reseller', NULL, 0, 5, 10000), (?, 'normal', ?, 1, 0, 10000)")
            ->execute([$reseller, $child, $reseller]);
        $session = $this->sessionFor($this->adminId);

        $page = $this->request('GET', '/users.php?edit=' . $child, $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('زیرمجموعه‌ی یک نماینده است', $page['body']);
        $this->assertStringNotContainsString('name="price[other]"', $page['body']);

        $this->save($session, $child, [(string)$this->operatorId('mci') => '500']);
        $this->assertSame([], \customer_operator_prices_current($child));
    }
}
