<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Account-type user management over REAL HTTP (docs/profile-kyc.md) — the admin create/edit/list
 * screens (public/users.php) and the self-service profile page's organization-resolution fix
 * (public/profile.php), proven against the actual rendered pages rather than the underlying
 * functions alone (those are covered in tests/Integration/AccountTypeOrganizationEnsureTest.php).
 *
 * backend_create_account() calls the shared backend platform's own HTTP API (see app/backend.php's
 * docblock on that boundary) and cannot be exercised from this test environment, so "user creation"
 * here is simulated the same way CustomerProfileHttpTest.php already does — a user row written
 * directly, mirroring exactly what a successful backend_create_account() call would have produced —
 * and then the SAME local ELLSMS-side code path public/users.php's create_account success branch
 * runs (ensure_user_has_organization() + profile_organization_save()) is exercised for real.
 */
final class AccountTypeUserManagementHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId = $this->makeCommittedUser(true);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_acct_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 20300 + random_int(0, 400);
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
        foreach ($this->createdOrganizationIds as $organizationId) {
            $db->prepare('DELETE FROM ellsms_organization_profiles WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_addresses WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_notification_preferences WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_user_profiles WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(bool $isAdmin = false, string $prefix = 'acctuser_'): int
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
    private function simulateAccountCreationLocalSide(int $userId, string $displayName, string $accountType): array
    {
        return \db_transaction(function () use ($userId, $displayName, $accountType) {
            $orgResult = \ensure_user_has_organization($userId, $displayName . "'s Workspace");
            if ($orgResult['ok'] && $orgResult['organization_id']) {
                \profile_organization_save((int)$orgResult['organization_id'], ['account_type' => $accountType], $this->adminId);
                $this->createdOrganizationIds[] = (int)$orgResult['organization_id'];
            }
            return $orgResult;
        });
    }

    /* ---------- Creating individual / legal customers (§2) ---------- */

    public function testCreatingAnIndividualCustomerPersistsOrganizationAccountType(): void
    {
        $userId = $this->makeCommittedUser(false, 'indiv_');
        $result = $this->simulateAccountCreationLocalSide($userId, 'Individual Customer', 'individual');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['created']);
        $this->assertSame('individual', \profile_organization_get((int)$result['organization_id'])['account_type']);
        $this->assertTrue(\can_access_organization($userId, (int)$result['organization_id']));
    }

    public function testCreatingALegalCustomerPersistsOrganizationAccountType(): void
    {
        $userId = $this->makeCommittedUser(false, 'legal_');
        $result = $this->simulateAccountCreationLocalSide($userId, 'Legal Customer', 'legal');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['created']);
        $this->assertSame('legal', \profile_organization_get((int)$result['organization_id'])['account_type']);
    }

    public function testNoDuplicateOrganizationOrProfileRowOnASimulatedRetry(): void
    {
        $userId = $this->makeCommittedUser(false, 'retry_');
        $first = $this->simulateAccountCreationLocalSide($userId, 'Retry Customer', 'legal');
        // A retried/duplicated submit for the SAME already-created user must not create a second org.
        $second = $this->simulateAccountCreationLocalSide($userId, 'Retry Customer', 'legal');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['organization_id'], $second['organization_id']);
        $this->assertCount(1, \user_organization_memberships($userId));

        $st = db()->prepare('SELECT COUNT(*) FROM ellsms_organization_profiles WHERE organization_id = ?');
        $st->execute([$first['organization_id']]);
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    /* ---------- Profile page visibility for a valid user (root-cause fix) ---------- */

    public function testProfilePageShowsAccountTypeSelectorForAUserWithAValidOrganization(): void
    {
        $userId = $this->makeCommittedUser(false, 'valid_');
        $result = $this->simulateAccountCreationLocalSide($userId, 'Valid User', 'individual');
        $this->assertTrue($result['created']);

        $session = $this->sessionFor($userId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('class="segmented"', $page['body']);
        $this->assertStringContainsString('حقیقی', $page['body']);
        $this->assertStringContainsString('حقوقی', $page['body']);
        $this->assertStringNotContainsString('این حساب هنوز به هیچ سازمانی متصل نیست', $page['body']);
    }

    /** The exact bug reported: before the fix, this rendered 200 with the selector silently absent
     *  and no explanation. After the fix it must explain why and offer a safe self-repair action. */
    public function testProfilePageExplainsRatherThanHidesForAUserWithNoOrganization(): void
    {
        $userId = $this->makeCommittedUser(false, 'malformed_');
        $this->assertSame([], \user_organization_memberships($userId), 'precondition: genuinely zero organizations');

        $session = $this->sessionFor($userId);
        $page = $this->request('GET', '/profile.php', $session);

        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('این حساب هنوز به هیچ سازمانی متصل نیست', $page['body']);
        $this->assertStringContainsString('ساخت سازمان برای حساب من', $page['body'], 'a safe self-repair action must be offered');
        // Must NOT silently render as if it were a normal individual account with an org.
        $this->assertStringNotContainsString('class="segmented"', $page['body']);
    }

    public function testProfilePageOffersOrganizationSwitchForAUserWithMultipleOrganizations(): void
    {
        $userId = $this->makeCommittedUser(false, 'multi_');
        $orgA = \create_organization($userId, 'Multi HTTP Org A');
        $orgB = \create_organization($userId, 'Multi HTTP Org B');
        $this->createdOrganizationIds[] = (int)$orgA['organization_id'];
        $this->createdOrganizationIds[] = (int)$orgB['organization_id'];

        $session = $this->sessionFor($userId);
        $page = $this->request('GET', '/profile.php', $session);

        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('عضو', $page['body']);
        $this->assertStringContainsString('انتخاب سازمان فعال', $page['body']);
        $this->assertStringNotContainsString('این حساب هنوز به هیچ سازمانی متصل نیست', $page['body'],
            'multiple organizations is not the same failure as zero — must not be conflated');
    }

    /* ---------- Self-repair actually resolves the account (organizations.php) ---------- */

    public function testSelfServiceOrganizationCreationUnblocksTheAccountTypeSelector(): void
    {
        $userId = $this->makeCommittedUser(false, 'selfrepair_');
        $session = $this->sessionFor($userId);
        $csrf = $this->csrfFor('/profile.php', $session);

        $create = $this->request('POST', '/organizations.php', $session, ['_csrf' => $csrf, 'do' => 'create', 'name' => 'Self Repaired Org']);
        $this->assertSame(302, $create['code']);

        $orgRow = db()->query("SELECT id FROM ellsms_organizations WHERE name = 'Self Repaired Org'")->fetch();
        $this->assertNotFalse($orgRow, 'organization must have actually been created');
        $this->createdOrganizationIds[] = (int)$orgRow['id'];

        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('class="segmented"', $page['body']);
    }

    /* ---------- Admin edit screen (§3) ---------- */

    public function testAdminEditScreenShowsRepairButtonForAUserWithNoOrganization(): void
    {
        $userId = $this->makeCommittedUser(false, 'adminview_none_');
        $adminSession = $this->sessionFor($this->adminId);

        $page = $this->request('GET', '/users.php?edit=' . $userId, $adminSession);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('این کاربر به هیچ سازمانی متصل نیست', $page['body']);
        $this->assertStringContainsString('ساخت سازمان پیش‌فرض برای این کاربر', $page['body']);
    }

    public function testAdminCanRepairAUsersMissingOrganizationAndThenSeeAccountType(): void
    {
        $userId = $this->makeCommittedUser(false, 'adminrepair_');
        $adminSession = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor('/users.php?edit=' . $userId, $adminSession);

        $repair = $this->request('POST', '/users.php', $adminSession, [
            '_csrf' => $csrf, 'do' => 'ensure_organization', 'id' => (string)$userId, 'back' => '1',
        ]);
        $this->assertSame(302, $repair['code']);

        $memberships = \user_organization_memberships($userId);
        $this->assertCount(1, $memberships, 'exactly one organization must now exist');
        $this->createdOrganizationIds[] = (int)$memberships[0]['organization_id'];

        $auditRow = db()->prepare("SELECT id FROM ellsms_audit_log WHERE action = 'user.organization_ensured' AND details LIKE ? ORDER BY id DESC LIMIT 1");
        $auditRow->execute(["%#{$userId}%"]);
        $this->assertNotFalse($auditRow->fetch(), 'the repair must be audited');

        $page = $this->request('GET', '/users.php?edit=' . $userId, $adminSession);
        $this->assertStringContainsString('class="segmented"', $page['body']);
        $this->assertStringNotContainsString('این کاربر به هیچ سازمانی متصل نیست', $page['body']);
    }

    /** Repairing an already-fixed (or ambiguous) user must be a safe no-op, not a duplicate/error. */
    public function testRepairingAUserWhoAlreadyHasAnOrganizationIsANoOp(): void
    {
        $userId = $this->makeCommittedUser(false, 'adminrepair_noop_');
        $org = \create_organization($userId, 'Already Has One');
        $this->createdOrganizationIds[] = (int)$org['organization_id'];
        $adminSession = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor('/users.php?edit=' . $userId, $adminSession);

        $this->request('POST', '/users.php', $adminSession, [
            '_csrf' => $csrf, 'do' => 'ensure_organization', 'id' => (string)$userId, 'back' => '1',
        ]);

        $this->assertCount(1, \user_organization_memberships($userId));
    }

    public function testAdminCanChangeAnExistingUsersAccountTypeWithoutLosingCompanyData(): void
    {
        $userId = $this->makeCommittedUser(false, 'adminedit_');
        $org = \create_organization($userId, 'Admin Edit Org');
        $organizationId = (int)$org['organization_id'];
        $this->createdOrganizationIds[] = $organizationId;
        \profile_organization_save($organizationId, ['account_type' => 'legal', 'legal_name' => 'شرکت مدیریتی'], $this->adminId);

        $adminSession = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor('/users.php?edit=' . $userId, $adminSession);

        $switch = $this->request('POST', '/users.php', $adminSession, [
            '_csrf' => $csrf, 'do' => 'account_type', 'id' => (string)$userId,
            'organization_id' => (string)$organizationId, 'account_type' => 'individual', 'back' => '1',
        ]);
        $this->assertSame(302, $switch['code']);

        $profile = \profile_organization_get($organizationId);
        $this->assertSame('individual', $profile['account_type']);
        $this->assertSame('شرکت مدیریتی', $profile['legal_name'], 'legal_name must survive the admin-side switch too');
    }

    /* ---------- User list column, no N+1 (§4) ---------- */

    public function testUserListShowsAccountTypeBadgesAndNoOrganizationState(): void
    {
        $legalUserId = $this->makeCommittedUser(false, 'list_legal_');
        $legalOrg = \create_organization($legalUserId, 'List Legal Org');
        $this->createdOrganizationIds[] = (int)$legalOrg['organization_id'];
        \profile_organization_save((int)$legalOrg['organization_id'], ['account_type' => 'legal'], $this->adminId);

        $individualUserId = $this->makeCommittedUser(false, 'list_indiv_');
        $individualOrg = \create_organization($individualUserId, 'List Individual Org');
        $this->createdOrganizationIds[] = (int)$individualOrg['organization_id'];
        // No explicit save — must default to 'individual', matching profile_organization_get()'s own default.

        $noOrgUserId = $this->makeCommittedUser(false, 'list_none_');

        $adminSession = $this->sessionFor($this->adminId);
        $page = $this->request('GET', '/users.php', $adminSession);

        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('نوع حساب', $page['body'], 'the list column header must be present');
        $this->assertStringContainsString('بدون سازمان', $page['body']);
    }

    /* ---------- Tenant isolation ---------- */

    public function testAUsersOwnOrganizationCannotBeReachedByAnotherOrdinaryUser(): void
    {
        $ownerUserId = $this->makeCommittedUser(false, 'iso_owner_');
        $org = \create_organization($ownerUserId, 'Isolated Org');
        $organizationId = (int)$org['organization_id'];
        $this->createdOrganizationIds[] = $organizationId;

        $strangerUserId = $this->makeCommittedUser(false, 'iso_stranger_');
        $this->assertFalse(\can_access_organization($strangerUserId, $organizationId));

        // The stranger's own profile page must never show the owner's organization/account type.
        $strangerSession = $this->sessionFor($strangerUserId);
        $page = $this->request('GET', '/profile.php', $strangerSession);
        $this->assertStringNotContainsString('Isolated Org', $page['body']);
    }

    /* ---------- Impersonation visibility / read-only (§7) ---------- */

    public function testImpersonationShowsAccountTypeCardButCannotChangeIt(): void
    {
        $targetUserId = $this->makeCommittedUser(false, 'imp_target_');
        $result = $this->simulateAccountCreationLocalSide($targetUserId, 'Impersonated User', 'individual');
        $this->assertTrue($result['created']);
        $organizationId = (int)$result['organization_id'];

        db()->exec("DELETE FROM ellsms_rate_limits WHERE bucket LIKE 'impersonate_start:%'");
        $adminSession = $this->sessionFor($this->adminId);
        $adminCsrf = $this->csrfFor('/index.php', $adminSession);
        $start = $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => $adminCsrf, 'do' => 'start', 'target_user_id' => (string)$targetUserId, 'reason' => 'account type support check',
        ]);
        $impersonated = preg_match('/Set-Cookie:\s*ELLSMS_SESSION=([^;]+)/i', $start['headers'], $m) === 1 ? $m[1] : null;
        $this->assertNotNull($impersonated, 'impersonation should have started');

        $page = $this->request('GET', '/profile.php', $impersonated);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('class="segmented"', $page['body'], 'the account-type card stays visible while impersonating');
        $this->assertStringContainsString('disabled', $page['body']);

        $csrf = $this->csrfFor('/profile.php', $impersonated);
        $this->request('POST', '/profile.php', $impersonated, [
            '_csrf' => $csrf, 'do' => 'account_type', 'account_type' => 'legal',
        ]);
        $this->assertSame('individual', \profile_organization_get($organizationId)['account_type'],
            'a support session must not be able to change the account type');
    }
}
