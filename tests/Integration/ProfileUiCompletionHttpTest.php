<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Profile + KYC UI completion (docs/profile-kyc.md) over REAL HTTP — proves the rendered page, not
 * just the underlying save/get functions already covered by KycWorkflowIntegrationTest.php. This is
 * the harness that can actually catch "the section never renders" or "the wrong doc types show up
 * for this account type", which a pure function-level test cannot.
 *
 * Same throwaway-dev-server pattern as CustomerProfileHttpTest.php — a separate process with its own
 * DB connection, so fixtures are committed rather than rolled back.
 */
final class ProfileUiCompletionHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $ownerId = 0;
    private int $organizationId = 0;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];
    private array $storedKeys = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->ownerId = $this->makeCommittedUser();
        $this->organizationId = $this->makeCommittedOrganization($this->ownerId);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_ui_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19900 + random_int(0, 400);
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
        foreach ($db->query('SELECT storage_key FROM ellsms_profile_documents')->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            if (in_array($key, $this->storedKeys, true)) {
                @unlink(profile_document_dir() . '/' . $key);
            }
        }
        foreach ($this->createdOrganizationIds as $organizationId) {
            $db->prepare('DELETE FROM ellsms_kyc_requests WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_allowed_ips WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_profile_documents WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_profiles WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_addresses WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_notification_preferences WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
        }
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_profile_documents WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_user_profiles WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_audit_log WHERE user_id = ? OR impersonator_user_id = ?')->execute([$id, $id]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['uicompl_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,0,?)')
           ->execute([$id, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function makeCommittedOrganization(int $ownerUserId): int
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['UI Completion Org', 'ui-compl-org-' . bin2hex(random_bytes(4)), 'active', $ownerUserId]);
        $organizationId = (int)$db->lastInsertId();
        $this->createdOrganizationIds[] = $organizationId;
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$organizationId, $ownerUserId]);
        return $organizationId;
    }

    private function writeSession(array $data): string
    {
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        foreach ($data as $key => $value) { $encoded .= $key . '|' . serialize($value); }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function sessionFor(int $userId, ?int $organizationId = null): string
    {
        $now = time();
        $data = ['uid' => $userId, '_created_at' => $now, '_last_activity' => $now];
        if ($organizationId !== null) {
            $data['organization_id'] = $organizationId;
        }
        return $this->writeSession($data);
    }

    private function csrfFor(string $sessionId): string
    {
        $page = $this->request('GET', '/profile.php', $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
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

    /* ---------- Account type switcher ---------- */

    public function testAccountTypeSwitcherRendersBothOptions(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('class="segmented"', $page['body']);
        $this->assertStringContainsString('حقیقی', $page['body']);
        $this->assertStringContainsString('حقوقی', $page['body']);
    }

    /* ---------- Individual (حقیقی) rendering ---------- */

    public function testIndividualAccountRendersPersonalSectionNotCompanySection(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('اطلاعات فردی', $page['body']);
        $this->assertStringContainsString('نام پدر', $page['body']);
        $this->assertStringContainsString('تاریخ انقضای کارت ملی', $page['body']);
        $this->assertStringNotContainsString('اطلاعات شرکت و نماینده', $page['body']);
    }

    public function testIndividualAccountDocumentTilesShowAllFourCatalogTypesEvenWhenEmpty(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('class="doc-grid"', $page['body']);
        foreach (['کارت ملی', 'شناسنامه', 'سلفی با کارت ملی', 'مدرک آدرس محل سکونت'] as $label) {
            $this->assertStringContainsString($label, $page['body']);
        }
        // Nothing uploaded yet — every tile must say so rather than silently omitting the row.
        $this->assertStringContainsString('بارگذاری نشده', $page['body']);
    }

    /* ---------- Legal (حقوقی) rendering ---------- */

    public function testLegalAccountRendersCompanyAndRepresentativeFieldsNotPersonalSection(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $csrf = $this->csrfFor($session);
        $this->request('POST', '/profile.php', $session, ['_csrf' => $csrf, 'do' => 'account_type', 'account_type' => 'legal']);

        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('اطلاعات شرکت و نماینده', $page['body']);
        $this->assertStringContainsString('نام شرکت', $page['body']);
        $this->assertStringContainsString('شناسه ملی شرکت', $page['body']);
        $this->assertStringContainsString('نام مدیرعامل شرکت', $page['body']);
        $this->assertStringContainsString('نام خانوادگی مدیرعامل شرکت', $page['body']);
        $this->assertStringContainsString('شماره شناسنامه مدیرعامل شرکت', $page['body']);
        $this->assertStringContainsString('شماره ثابت', $page['body']);
        $this->assertStringContainsString('شماره فکس', $page['body']);
        $this->assertStringContainsString('کد مشتری', $page['body']);
        // The individual-only "اطلاعات فردی" card must not render for a legal account with no legal
        // data yet (the dormant-data fallback only kicks in once something is actually on file).
        $this->assertStringNotContainsString('<h2>اطلاعات فردی</h2>', $page['body']);
    }

    public function testLegalAccountDocumentTilesShowBothRepresentativeAndCompanyCards(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $csrf = $this->csrfFor($session);
        $this->request('POST', '/profile.php', $session, ['_csrf' => $csrf, 'do' => 'account_type', 'account_type' => 'legal']);

        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('مدارک هویتی نماینده', $page['body']);
        $this->assertStringContainsString('مدارک شرکت', $page['body']);
        $this->assertStringContainsString('آگهی تأسیس', $page['body']);
        $this->assertStringContainsString('کارت ملی نماینده قانونی', $page['body']);
    }

    /* ---------- Persistence of new fields, visible through the rendered page ---------- */

    public function testNewIndividualAndCompanyFieldsPersistAndRenderAfterReload(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $csrf = $this->csrfFor($session);
        $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'profile_personal',
            'father_name' => 'رضا', 'national_code' => '1234567890',
            'national_id_expiry_at_y' => '1410', 'national_id_expiry_at_m' => '1', 'national_id_expiry_at_d' => '1',
            'gender' => 'male',
        ]);
        $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'account_type', 'account_type' => 'legal',
        ]);
        $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'profile_organization', 'account_type' => 'legal',
            'legal_name' => 'شرکت تست', 'ceo_name' => 'علی', 'ceo_last_name' => 'رضایی‌فرد',
            'ceo_birth_certificate_no' => '778899', 'landline_phone' => '02177889900',
            'fax_number' => '02177889911', 'customer_code' => 'CUST-777',
        ]);

        $page = $this->request('GET', '/profile.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('رضایی‌فرد', $page['body'], 'ceo_last_name must round-trip and render');
        $this->assertStringContainsString('778899', $page['body']);
        $this->assertStringContainsString('02177889900', $page['body']);
        $this->assertStringContainsString('02177889911', $page['body']);
        $this->assertStringContainsString('CUST-777', $page['body']);

        // And the underlying row agrees — this is the same data the admin review screen reads.
        $orgProfile = profile_organization_get($this->organizationId);
        $this->assertSame('رضایی‌فرد', $orgProfile['ceo_last_name']);
        $this->assertSame('02177889900', $orgProfile['landline_phone']);
        $userProfile = profile_user_get($this->ownerId);
        $this->assertNotNull($userProfile['national_id_expiry_at']);
    }

    /* ---------- KYC status visibility ---------- */

    public function testKycStatusCardShowsStatusAndTimestampRows(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('وضعیت احراز هویت', $page['body']);
        $this->assertStringContainsString('احراز نشده', $page['body']); // draft's Persian label
        $this->assertStringContainsString('تاریخ ارسال', $page['body']);
        $this->assertStringContainsString('تاریخ آخرین بررسی', $page['body']);
    }

    /* ---------- Allowed IPs still surfaced ---------- */

    public function testAllowedIpSectionIsPresentAndLabelledAsManagementOnly(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('آدرس‌های IP مجاز', $page['body']);
        $this->assertStringContainsString('فقط برای ثبت و مدیریت است', $page['body']);
    }
}
