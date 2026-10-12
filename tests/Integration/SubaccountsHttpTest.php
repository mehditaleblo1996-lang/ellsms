<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Resellers / organizations and menu access over real HTTP (php -S): the parent's sub-account page,
 * creating a sub-account through the backend account endpoint (a fake POST /api/users/), a closed
 * menu answering 403 and leaving the sidebar, a suspended sub-account being logged out, a parent
 * entering its child's panel, and a parent being unable to touch someone else's child.
 *
 * Data here is committed (the dev server is another process), so everything is cleaned up by id.
 */
final class SubaccountsHttpTest extends TestCase
{
    /** @var array<int,resource|null> */
    private array $procs = [];
    private int $port;
    private int $apiPort;
    private string $sessionDir;
    private array $createdUserIds = [];
    private array $createdRoleIds = [];
    private ?int $domainId = null;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        db()->exec("DELETE FROM ellsms_rate_limits WHERE bucket LIKE 'impersonate_start:%'");
        // A stored api_base_url would win over this test's API_BASE_URL (see IntegrationTestCase).
        db()->exec("DELETE FROM ellsms_settings WHERE skey = 'api_base_url'");
        menu_access_reset_cache();

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_sub_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);
        $env = [
            'APP_ENV'         => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
        ];
        $this->apiPort = 19800 + random_int(0, 150);
        $this->port = 19960 + random_int(0, 150);
        $this->startServer([PHP_BINARY, '-S', "127.0.0.1:{$this->apiPort}", dirname(__DIR__) . '/fixtures/fake_users_api_server.php'], $env, $this->apiPort);
        $this->startServer(
            [PHP_BINARY, '-d', 'session.save_path=' . $this->sessionDir, '-S', "127.0.0.1:{$this->port}", '-t', dirname(__DIR__, 2) . '/public'],
            $env + ['API_BASE_URL' => "http://127.0.0.1:{$this->apiPort}"],
            $this->port
        );
    }

    private function startServer(array $cmd, array $env, int $port): void
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertNotFalse($proc, 'could not start a throwaway PHP dev server');
        $this->procs[] = $proc;
        for ($i = 0; $i < 40; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); return; }
        }
        $this->fail('throwaway dev server never accepted connections');
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
            proc_close($proc);
        }
        foreach (glob($this->sessionDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->sessionDir);

        $db = db();
        foreach ($this->createdRoleIds as $id) {
            $db->prepare('DELETE FROM ellsms_menu_roles WHERE id = ?')->execute([$id]);
        }
        $created = $db->prepare('SELECT user_id FROM ellsms_customer_accounts WHERE parent_user_id IN (' . implode(',', array_map('intval', $this->createdUserIds ?: [0])) . ')');
        $created->execute();
        $ids = array_unique(array_merge($this->createdUserIds, array_map('intval', $created->fetchAll(\PDO::FETCH_COLUMN))));
        foreach ($ids as $id) {
            foreach (['ellsms_customer_accounts', 'ellsms_customer_tariff_history', 'ellsms_user_menu_access', 'ellsms_user_menu_overrides', 'ellsms_number_shares'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$id]);
            }
            $db->prepare('DELETE FROM ellsms_menu_roles WHERE owner_user_id = ?')->execute([$id]);
            $orgs = $db->prepare('SELECT organization_id FROM ellsms_organization_memberships WHERE user_id = ?');
            $orgs->execute([$id]);
            foreach ($orgs->fetchAll(\PDO::FETCH_COLUMN) as $orgId) {
                $db->prepare('DELETE FROM ellsms_organization_profiles WHERE organization_id = ?')->execute([$orgId]);
                $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
                $db->prepare('UPDATE ellsms_wallet_accounts SET organization_id = NULL WHERE organization_id = ?')->execute([$orgId]);
                $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
            }
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
        if ($this->domainId !== null) {
            $db->prepare('DELETE FROM domain WHERE id = ?')->execute([$this->domainId]);
        }
        menu_access_reset_cache();
    }

    private function makeUser(bool $admin = false): int
    {
        db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')->execute(['sub_' . bin2hex(random_bytes(5))]);
        $id = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')->execute([$id, $admin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function account(int $userId, string $type, ?int $parentId = null, int $max = 0, int $bp = 10000): void
    {
        db()->prepare('INSERT INTO ellsms_customer_accounts (user_id, customer_type, parent_user_id, level, max_subset_limit, tariff_bp) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $type, $parentId, $parentId === null ? 0 : 1, $max, $bp]);
    }

    private function sessionFor(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        $data = ['uid' => $userId, '_created_at' => $now, '_last_activity' => $now];
        $encoded = '';
        foreach ($data as $key => $value) $encoded .= $key . '|' . serialize($value);
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function csrfFor(string $sessionId, string $path = '/index.php'): string
    {
        $page = $this->request('GET', $path, $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    /** @return array{code:int, body:string, location:?string, session_cookie:?string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $this->assertNotSame('', $raw, 'curl failed: ' . curl_error($ch));
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        return [
            'code' => $code,
            'body' => substr($raw, $headerSize),
            'location' => preg_match('/^Location:\s*(\S+)/mi', $headers, $m) === 1 ? $m[1] : null,
            'session_cookie' => preg_match('/Set-Cookie:\s*ELLSMS_SESSION=([^;]+)/i', $headers, $m2) === 1 ? $m2[1] : null,
        ];
    }

    public function testAResellerSeesItsPageAndANormalCustomerDoesNot(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 12000);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller);

        $page = $this->request('GET', '/subaccounts.php', $this->sessionFor($reseller));
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('ساخت زیرمجموعه‌ی جدید', $page['body']);
        $this->assertStringContainsString('href="/subaccounts.php"', $page['body'], 'the sidebar links the page');

        $this->assertSame(403, $this->request('GET', '/subaccounts.php', $this->sessionFor($child))['code']);
        $this->assertStringNotContainsString('href="/subaccounts.php"', $this->request('GET', '/index.php', $this->sessionFor($child))['body']);
    }

    public function testAResellerCreatesASubAccountThroughTheBackendEndpoint(): void
    {
        db()->prepare('INSERT INTO domain (name) VALUES (?)')->execute(['sub-test-domain']);
        $this->domainId = (int)db()->lastInsertId();
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5, 12000);
        $session = $this->sessionFor($reseller);
        $username = 'child_' . bin2hex(random_bytes(4));

        $r = $this->request('POST', '/subaccounts.php', $session, [
            '_csrf' => $this->csrfFor($session, '/subaccounts.php'), 'do' => 'create',
            'customer_type' => 'normal', 'account_type' => 'individual', 'tariff_percent' => '130', 'max_subset_limit' => '0',
            'username' => $username, 'password' => 'secret123', 'first_name' => 'علی', 'last_name' => 'رضایی',
            'email' => $username . '@example.com', 'mobile' => '09121234567', 'national_id' => '0012345678', 'gender' => 'MALE',
        ]);
        $this->assertSame(302, $r['code']);
        $childId = backend_find_user_id_by_username($username);
        $this->assertNotNull($childId, 'the backend account was created');
        $this->createdUserIds[] = $childId;
        $this->assertStringContainsString('/subaccounts.php?view=' . $childId, (string)$r['location']);

        $acc = customer_account($childId);
        $this->assertSame($reseller, $acc['parent_user_id']);
        $this->assertSame(13000, $acc['tariff_bp']);
        $meta = db()->prepare('SELECT panel_access FROM ellsms_meta WHERE user_id = ?');
        $meta->execute([$childId]);
        $this->assertSame(1, (int)$meta->fetchColumn());
        $this->assertNotNull(user_default_organization_id($childId), 'the sub-account gets its own organization');

        // Below the reseller's own tariff is refused before anything is created on the backend.
        $refusedName = 'child_' . bin2hex(random_bytes(4));
        $this->request('POST', '/subaccounts.php', $session, [
            '_csrf' => $this->csrfFor($session, '/subaccounts.php'), 'do' => 'create', 'customer_type' => 'normal', 'tariff_percent' => '110',
            'username' => $refusedName, 'password' => 'secret123', 'first_name' => 'a', 'last_name' => 'b',
            'email' => $refusedName . '@example.com', 'mobile' => '09121234567', 'national_id' => '0012345678',
        ]);
        $this->assertNull(backend_find_user_id_by_username($refusedName));
    }

    private const MENU_DENIED = 'دسترسی به این بخش برای حساب شما فعال نیست';

    public function testAClosedMenuAnswers403AndLeavesTheSidebar(): void
    {
        $admin = $this->makeUser(true);
        $user = $this->makeUser();
        menu_user_access_save($user, null, ['reports' => 'deny'], null, $admin);
        $session = $this->sessionFor($user);

        $closed = $this->request('GET', '/reports.php', $session);
        $this->assertSame(403, $closed['code']);
        $this->assertStringContainsString(self::MENU_DENIED, $closed['body']);
        $this->assertSame(403, $this->request('GET', '/message-detail.php', $session)['code'], 'pages under a closed menu are closed too');

        $inbox = $this->request('GET', '/inbox.php', $session);
        $this->assertSame(200, $inbox['code']);
        $this->assertStringNotContainsString('href="/reports.php"', $inbox['body']);
        $this->assertStringContainsString('href="/inbox.php"', $inbox['body']);
    }

    public function testAChildIsNarrowedToItsParentsMenus(): void
    {
        $admin = $this->makeUser(true);
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller);
        $role = menu_role_save(null, 'فقط صندوق دریافت', ['inbox', 'subaccounts'], null, $admin);
        $this->createdRoleIds[] = $role['id'];
        menu_user_access_save($reseller, $role['id'], [], null, $admin);

        $closed = $this->request('GET', '/reports.php', $this->sessionFor($child));
        $this->assertSame(403, $closed['code']);
        $this->assertStringContainsString(self::MENU_DENIED, $closed['body']);
        $this->assertSame(200, $this->request('GET', '/inbox.php', $this->sessionFor($child))['code']);
    }

    public function testASuspendedSubAccountIsLoggedOut(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller);
        $session = $this->sessionFor($child);
        $this->assertSame(200, $this->request('GET', '/inbox.php', $session)['code']);

        $parentSession = $this->sessionFor($reseller);
        $this->request('POST', '/subaccounts.php', $parentSession, ['_csrf' => $this->csrfFor($parentSession, '/subaccounts.php'), 'do' => 'suspend', 'child_id' => $child]);
        $this->assertSame(1, customer_account($child)['suspended']);

        $r = $this->request('GET', '/inbox.php', $session);
        $this->assertSame(302, $r['code']);
        $this->assertStringContainsString('login', (string)$r['location']);
    }

    public function testAParentEntersItsChildsPanelButNotAStrangers(): void
    {
        $reseller = $this->makeUser();
        $this->account($reseller, 'reseller', null, 5);
        $child = $this->makeUser();
        $this->account($child, 'normal', $reseller);
        $otherReseller = $this->makeUser();
        $this->account($otherReseller, 'reseller', null, 5);
        $stranger = $this->makeUser();
        $this->account($stranger, 'normal', $otherReseller);

        $session = $this->sessionFor($reseller);
        $r = $this->request('POST', '/subaccounts.php', $session, ['_csrf' => $this->csrfFor($session, '/subaccounts.php'), 'do' => 'impersonate', 'child_id' => $child]);
        $this->assertSame(302, $r['code']);
        $newSession = (string)$r['session_cookie'];
        $this->assertNotSame('', $newSession, 'entering the panel issues a new session id');
        $inside = $this->request('GET', '/index.php', $newSession);
        $this->assertStringContainsString('حالت پشتیبانی فعال است', $inside['body']);
        $this->assertStringContainsString('بازگشت به پنل خودم', $inside['body']);

        // A stranger's child: the id is not one of mine, so nothing happens.
        $session2 = $this->sessionFor($reseller);
        $r = $this->request('POST', '/subaccounts.php', $session2, ['_csrf' => $this->csrfFor($session2, '/subaccounts.php'), 'do' => 'impersonate', 'child_id' => $stranger]);
        $this->assertNull($r['session_cookie']);
        $this->assertStringContainsString('/subaccounts.php', (string)$r['location']);
        $this->request('POST', '/subaccounts.php', $session2, ['_csrf' => $this->csrfFor($session2, '/subaccounts.php'), 'do' => 'suspend', 'child_id' => $stranger]);
        $this->assertSame(0, customer_account($stranger)['suspended']);
    }

    public function testTheAdminSetsTheHierarchyFromTheUsersPage(): void
    {
        $admin = $this->makeUser(true);
        $reseller = $this->makeUser();
        $session = $this->sessionFor($admin);
        $page = $this->request('GET', '/users.php?edit=' . $reseller, $session);
        $this->assertStringContainsString('نمایندگی و دسترسی منو', $page['body']);

        $csrf = preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
        $this->request('POST', '/users.php', $session, [
            '_csrf' => $csrf, 'do' => 'customer_save', 'id' => $reseller, 'back' => '1',
            'customer_type' => 'reseller', 'parent_username' => '', 'tariff_percent' => '85', 'max_subset_limit' => '20',
        ]);
        $acc = customer_account($reseller);
        $this->assertSame('reseller', $acc['customer_type']);
        $this->assertSame(8500, $acc['tariff_bp']);
        $this->assertSame(20, $acc['max_subset_limit']);
    }
}
