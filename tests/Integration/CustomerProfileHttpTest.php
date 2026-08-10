<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Customer/organization profile over REAL HTTP — real sessions, real multipart uploads, the real
 * public/ directory (docs/customer-profile.md).
 *
 * What can only be proven here: that a genuine browser upload passes the same validation the service
 * tests exercise through a seam, that document downloads are authorized per request, that a
 * cross-tenant document id 404s, that CSRF is enforced, and that a support impersonation can read a
 * profile but not change it.
 *
 * Sessions are forged by writing a session file into a private save_path handed to the throwaway
 * server, exactly as ImpersonationHttpTest does. Fixtures are COMMITTED, since the server is a
 * separate process with its own connection.
 */
final class CustomerProfileHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $ownerId = 0;
    private int $strangerId = 0;
    private int $organizationId = 0;
    private int $otherOrganizationId = 0;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];
    private array $storedKeys = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId    = $this->makeCommittedUser(true);
        $this->ownerId    = $this->makeCommittedUser(false);
        $this->strangerId = $this->makeCommittedUser(false);
        $this->organizationId      = $this->makeCommittedOrganization($this->ownerId);
        $this->otherOrganizationId = $this->makeCommittedOrganization($this->strangerId);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_prof_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19500 + random_int(0, 400);
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
        // Files written by this test are removed so the integrity check never sees them as orphans.
        foreach ($db->query('SELECT storage_key FROM ellsms_profile_documents')->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            if (in_array($key, $this->storedKeys, true)) {
                @unlink(profile_document_dir() . '/' . $key);
            }
        }
        foreach ($this->createdOrganizationIds as $organizationId) {
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

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute([($isAdmin ? 'profadmin_' : 'profuser_') . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function makeCommittedOrganization(int $ownerUserId): int
    {
        $db = db();
        $db->prepare('INSERT INTO ellsms_organizations (name, slug, status, created_by_user_id) VALUES (?,?,?,?)')
           ->execute(['Profile Org', 'prof-org-' . bin2hex(random_bytes(4)), 'active', $ownerUserId]);
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
    private function request(string $method, string $path, ?string $sessionId = null, array $post = [], array $files = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            // A REAL multipart body when a file is present, so PHP populates $_FILES exactly as a
            // browser would — including is_uploaded_file() being true.
            $opts[CURLOPT_POSTFIELDS] = $files === [] ? http_build_query($post) : array_merge($post, $files);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'headers' => substr($raw, 0, $headerSize)];
    }

    private function pngFile(string $filename = 'card.png'): array
    {
        $path = sys_get_temp_dir() . '/prof_up_' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return ['document' => new \CURLFile($path, 'image/png', $filename)];
    }

    /** Uploads a personal document through the real page and returns its id. */
    private function uploadPersonalDocument(string $session, string $filename = 'card.png'): int
    {
        $csrf = $this->csrfFor($session);
        $this->assertNotSame('', $csrf);
        $r = $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'document_upload', 'owner' => 'user', 'document_type' => 'national_card',
        ], $this->pngFile($filename));
        $this->assertSame(302, $r['code']);

        $row = db()->query('SELECT id, storage_key FROM ellsms_profile_documents ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertIsArray($row, 'the upload should have created a document row');
        $this->storedKeys[] = (string)$row['storage_key'];
        return (int)$row['id'];
    }

    /* ================= Real multipart upload (STEP 46) ================= */

    public function testARealBrowserUploadIsStoredAndDownloadableByItsOwner(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $documentId = $this->uploadPersonalDocument($session, 'my card.png');

        $document = db()->query("SELECT * FROM ellsms_profile_documents WHERE id = {$documentId}")->fetch();
        $this->assertSame('image/png', $document['mime_type'], 'the MIME type is detected from content, not from the request');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $document['storage_key']);
        $this->assertSame($this->ownerId, (int)$document['user_id']);
        $this->assertNull($document['organization_id'], 'exactly one owner');

        $download = $this->request('GET', '/profile-document.php?id=' . $documentId, $session);
        $this->assertSame(200, $download['code']);
        $this->assertStringContainsString('Content-Type: image/png', $download['headers']);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $download['headers']);
        $this->assertStringContainsString('no-store', $download['headers'], 'a private document must never be cacheable');
        // The response filename is rebuilt from the document TYPE — never from the uploaded name.
        $this->assertStringContainsString('filename="national_card.png"', $download['headers']);
        $this->assertStringNotContainsString('my card', $download['headers']);
    }

    public function testAPhpPayloadRenamedAsAnImageIsRejectedOverHttp(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_profile_documents')->fetchColumn();

        $path = sys_get_temp_dir() . '/evil_' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents($path, "<?php system(\$_GET['c']); ?>");
        $csrf = $this->csrfFor($session);
        $r = $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'document_upload', 'owner' => 'user', 'document_type' => 'national_card',
        ], ['document' => new \CURLFile($path, 'image/png', 'innocent.png')]);
        $this->assertSame(302, $r['code']);

        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM ellsms_profile_documents')->fetchColumn(),
            'a payload whose CONTENT is not an accepted format must not be stored, whatever it claims to be');
        $follow = $this->request('GET', '/profile.php', $session);
        $this->assertStringContainsString('فرمت فایل', $follow['body']);
        @unlink($path);
    }

    /* ================= Download authorization (STEP 19/43) ================= */

    public function testAnotherUsersPersonalDocumentIsNotReadable(): void
    {
        $ownerSession = $this->sessionFor($this->ownerId, $this->organizationId);
        $documentId = $this->uploadPersonalDocument($ownerSession);

        $strangerSession = $this->sessionFor($this->strangerId, $this->otherOrganizationId);
        $r = $this->request('GET', '/profile-document.php?id=' . $documentId, $strangerSession);
        // 404, not 403: a distinguishable "exists but forbidden" would confirm which ids are real.
        $this->assertSame(404, $r['code']);
        $this->assertStringNotContainsString('PNG', $r['body']);
    }

    public function testAnUnauthenticatedVisitorCannotReadADocument(): void
    {
        $ownerSession = $this->sessionFor($this->ownerId, $this->organizationId);
        $documentId = $this->uploadPersonalDocument($ownerSession);

        $r = $this->request('GET', '/profile-document.php?id=' . $documentId);
        $this->assertSame(302, $r['code'], 'an anonymous request is sent to login, never served the file');
    }

    public function testACrossTenantOrganizationDocumentIdIsNotReadable(): void
    {
        // The stranger's organization document, requested by our owner.
        $db = db();
        $storageKey = bin2hex(random_bytes(20)) . '.png';
        file_put_contents(profile_document_dir() . '/' . $storageKey, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->storedKeys[] = $storageKey;
        $db->prepare(
            "INSERT INTO ellsms_profile_documents (organization_id, document_type, storage_key, mime_type, status, active_slot)
             VALUES (?, 'incorporation_notice', ?, 'image/png', 'active', ?)"
        )->execute([$this->otherOrganizationId, $storageKey, 'o:' . $this->otherOrganizationId . ':incorporation_notice']);
        $documentId = (int)$db->lastInsertId();

        $ownerSession = $this->sessionFor($this->ownerId, $this->organizationId);
        $this->assertSame(404, $this->request('GET', '/profile-document.php?id=' . $documentId, $ownerSession)['code']);

        // ...while a member of the OWNING organization can read it.
        $strangerSession = $this->sessionFor($this->strangerId, $this->otherOrganizationId);
        $this->assertSame(200, $this->request('GET', '/profile-document.php?id=' . $documentId, $strangerSession)['code']);
    }

    public function testAPlatformAdminCanReadACustomerDocument(): void
    {
        $ownerSession = $this->sessionFor($this->ownerId, $this->organizationId);
        $documentId = $this->uploadPersonalDocument($ownerSession);

        $adminSession = $this->sessionFor($this->adminId);
        $this->assertSame(200, $this->request('GET', '/profile-document.php?id=' . $documentId, $adminSession)['code']);
    }

    /* ================= CSRF & permissions (STEP 49) ================= */

    public function testProfileMutationsRequireAValidCsrfToken(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $r = $this->request('POST', '/profile.php', $session, [
            '_csrf' => 'wrong', 'do' => 'profile_personal', 'father_name' => 'مهاجم',
        ]);
        $this->assertSame(400, $r['code']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_user_profiles WHERE user_id = {$this->ownerId}")->fetchColumn());
    }

    public function testAnOrdinaryMemberCannotChangeTheCompanyProfile(): void
    {
        $memberId = $this->makeCommittedUser(false);
        db()->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'member', 'active')")
            ->execute([$this->organizationId, $memberId]);
        $memberSession = $this->sessionFor($memberId, $this->organizationId);

        $csrf = $this->csrfFor($memberSession);
        $r = $this->request('POST', '/profile.php', $memberSession, [
            '_csrf' => $csrf, 'do' => 'profile_organization', 'legal_name' => 'تغییر توسط عضو عادی',
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_organization_profiles WHERE organization_id = {$this->organizationId}")->fetchColumn(),
            'an ordinary member must not be able to write the company record');

        // ...but they can READ the page.
        $this->assertSame(200, $this->request('GET', '/profile.php', $memberSession)['code']);
    }

    public function testTheOwnerCanSaveTheCompanyProfileAndAddress(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $csrf = $this->csrfFor($session);

        $this->assertSame(302, $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'profile_organization',
            'legal_name' => 'شرکت نمونه', 'company_type' => 'legal_entity', 'registration_number' => '۱۲۳۴۵',
        ])['code']);
        $this->assertSame(302, $this->request('POST', '/profile.php', $session, [
            '_csrf' => $csrf, 'do' => 'profile_address', 'city' => 'تهران', 'postal_code' => '۱۲۳۴۵۶۷۸۹۰',
        ])['code']);

        $profile = db()->query("SELECT * FROM ellsms_organization_profiles WHERE organization_id = {$this->organizationId}")->fetch();
        $this->assertSame('شرکت نمونه', $profile['legal_name']);
        $this->assertSame('12345', $profile['registration_number'], 'Persian digits are normalized on write');
        $address = db()->query("SELECT * FROM ellsms_organization_addresses WHERE organization_id = {$this->organizationId}")->fetch();
        $this->assertSame('1234567890', $address['postal_code']);
    }

    public function testAnOrganizationUserCannotReachThePlatformAdminCustomerPage(): void
    {
        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $r = $this->request('POST', '/users.php', $session, [
            '_csrf' => $this->csrfFor($session), 'do' => 'profile_organization',
            'id' => $this->ownerId, 'legal_name' => 'از راه پنل مدیریت',
        ]);
        $this->assertSame(403, $r['code']);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_organization_profiles WHERE organization_id = {$this->organizationId}")->fetchColumn());
    }

    /* ================= Rendering safety (STEP 37) ================= */

    public function testProfileValuesAreEscapedWhenRendered(): void
    {
        db()->prepare("INSERT INTO ellsms_organization_profiles (organization_id, legal_name, ceo_name) VALUES (?,?,?)")
            ->execute([$this->organizationId, 'Acme "><script>alert(1)</script>', 'مدیر <b>x</b>']);

        $session = $this->sessionFor($this->ownerId, $this->organizationId);
        $page = $this->request('GET', '/profile.php', $session);
        $this->assertSame(200, $page['code']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page['body'],
            'a value written directly to the database must still render escaped');
        $this->assertStringContainsString('&lt;script&gt;', $page['body']);
    }

    /* ================= Impersonation (STEP 26/27/45) ================= */

    public function testSupportImpersonationCanReadAProfileButNotChangeIt(): void
    {
        $ownerSession = $this->sessionFor($this->ownerId, $this->organizationId);
        $documentId = $this->uploadPersonalDocument($ownerSession);
        db()->prepare('INSERT INTO ellsms_organization_profiles (organization_id, legal_name) VALUES (?,?)')
            ->execute([$this->organizationId, 'شرکت قابل مشاهده']);

        // Start a real support session over HTTP.
        db()->exec("DELETE FROM ellsms_rate_limits WHERE bucket LIKE 'impersonate_start:%'");
        $adminSession = $this->sessionFor($this->adminId);
        $adminCsrf = preg_match('/name="_csrf" value="([^"]+)"/', $this->request('GET', '/index.php', $adminSession)['body'], $m) === 1 ? $m[1] : '';
        $start = $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => $adminCsrf, 'do' => 'start', 'target_user_id' => $this->ownerId, 'reason' => 'profile support',
        ]);
        $impersonated = preg_match('/Set-Cookie:\s*ELLSMS_SESSION=([^;]+)/i', $start['headers'], $m) === 1 ? $m[1] : null;
        $this->assertNotNull($impersonated, 'impersonation should have started');

        // Reading works — that is the point of support mode.
        $page = $this->request('GET', '/profile.php', $impersonated);
        $this->assertSame(200, $page['code']);
        $this->assertStringContainsString('شرکت قابل مشاهده', $page['body']);
        $this->assertStringContainsString('حالت پشتیبانی فعال است', $page['body'], 'the banner stays visible');
        $this->assertSame(200, $this->request('GET', '/profile-document.php?id=' . $documentId, $impersonated)['code'],
            'a document the target could normally see stays visible');

        // Writing does not.
        $csrf = $this->csrfFor($impersonated);
        $this->request('POST', '/profile.php', $impersonated, [
            '_csrf' => $csrf, 'do' => 'profile_personal', 'father_name' => 'تغییر توسط پشتیبانی',
        ]);
        $this->assertSame(0, (int)db()->query("SELECT COUNT(*) FROM ellsms_user_profiles WHERE user_id = {$this->ownerId}")->fetchColumn(),
            'a support session must not be able to change a customer identity record');
        $follow = $this->request('GET', '/profile.php', $impersonated);
        $this->assertStringContainsString('در حالت پشتیبانی غیرفعال است', $follow['body']);

        // Nor may it upload or archive a document.
        $before = (int)db()->query('SELECT COUNT(*) FROM ellsms_profile_documents')->fetchColumn();
        $this->request('POST', '/profile.php', $impersonated, [
            '_csrf' => $csrf, 'do' => 'document_upload', 'owner' => 'user', 'document_type' => 'birth_certificate',
        ], $this->pngFile());
        $this->assertSame($before, (int)db()->query('SELECT COUNT(*) FROM ellsms_profile_documents')->fetchColumn());
        $this->assertSame('active', (string)db()->query("SELECT status FROM ellsms_profile_documents WHERE id = {$documentId}")->fetchColumn());
    }

    public function testAnImpersonatedSessionSeesOnlyWhatTheTargetCouldSee(): void
    {
        // STEP 27: the real actor's platform-admin reach is deliberately NOT combined with the
        // target's view context — a support session is not an admin session wearing a costume.
        $db = db();
        $storageKey = bin2hex(random_bytes(20)) . '.png';
        file_put_contents(profile_document_dir() . '/' . $storageKey, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->storedKeys[] = $storageKey;
        $db->prepare(
            "INSERT INTO ellsms_profile_documents (organization_id, document_type, storage_key, mime_type, status, active_slot)
             VALUES (?, 'incorporation_notice', ?, 'image/png', 'active', ?)"
        )->execute([$this->otherOrganizationId, $storageKey, 'o:' . $this->otherOrganizationId . ':incorporation_notice']);
        $foreignDocumentId = (int)$db->lastInsertId();

        $adminSession = $this->sessionFor($this->adminId);
        // As a platform admin, that document IS readable.
        $this->assertSame(200, $this->request('GET', '/profile-document.php?id=' . $foreignDocumentId, $adminSession)['code']);

        $db->exec("DELETE FROM ellsms_rate_limits WHERE bucket LIKE 'impersonate_start:%'");
        $adminCsrf = preg_match('/name="_csrf" value="([^"]+)"/', $this->request('GET', '/index.php', $adminSession)['body'], $m) === 1 ? $m[1] : '';
        $start = $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => $adminCsrf, 'do' => 'start', 'target_user_id' => $this->ownerId, 'reason' => 'scope check',
        ]);
        $impersonated = preg_match('/Set-Cookie:\s*ELLSMS_SESSION=([^;]+)/i', $start['headers'], $m) === 1 ? $m[1] : null;
        $this->assertNotNull($impersonated);

        // While impersonating the same admin cannot — the target has no claim to it.
        $this->assertSame(404, $this->request('GET', '/profile-document.php?id=' . $foreignDocumentId, $impersonated)['code'],
            'platform-admin document reach must not leak into a support session');
    }
}
