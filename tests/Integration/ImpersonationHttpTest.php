<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Platform-admin support impersonation over REAL HTTP, against a real PHP server serving the real
 * public/ directory (docs/admin-impersonation.md).
 *
 * The service-level half is covered by ImpersonationTest. What can ONLY be proven here is everything
 * that lives in the request/response cycle rather than in a function's return value: that a GET
 * cannot start a session, that CSRF is enforced, that the session COOKIE actually changes on entry
 * and exit (Invariant F — session fixation), that the banner really renders on ordinary pages, that
 * the platform-admin area really becomes unreachable, and that logout really ends everything.
 *
 * Sessions are forged by writing a session file into a private save_path handed to the throwaway
 * server — precisely what a valid cookie would produce, so these test the guards rather than the
 * login form (which has its own tests). Fixtures are COMMITTED, since the server is a separate
 * process with its own connection and cannot see an uncommitted transaction.
 */
final class ImpersonationHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $targetId = 0;
    private int $ownerId = 0;
    private int $organizationId = 0;
    private array $createdUserIds = [];
    private array $createdOrganizationIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->adminId  = $this->makeCommittedUser(true);
        $this->targetId = $this->makeCommittedUser(false);
        $this->ownerId  = $this->makeCommittedUser(false);
        $this->organizationId = $this->makeCommittedOrganization($this->targetId);

        // The impersonation-start rate limiter buckets per ACTOR and per IP, and every test in this
        // class shares 127.0.0.1 — so without this the IP bucket fills partway through the class and
        // later tests start failing for a reason that has nothing to do with what they assert.
        // The limiter itself is proven by testStartsAreRateLimitedPerIp below; here it is noise.
        db()->exec("DELETE FROM ellsms_rate_limits WHERE bucket LIKE 'impersonate_start:%'");

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_imp_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19300 + random_int(0, 400);
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
            $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$organizationId]);
            $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
        }
        foreach ($this->createdUserIds as $id) {
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
           ->execute([($isAdmin ? 'impadmin_' : 'imptarget_') . bin2hex(random_bytes(5))]);
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
           ->execute(['Imp Org', 'imp-org-' . bin2hex(random_bytes(4)), 'active', $ownerUserId]);
        $organizationId = (int)$db->lastInsertId();
        $this->createdOrganizationIds[] = $organizationId;
        $db->prepare("INSERT INTO ellsms_organization_memberships (organization_id, user_id, role, status) VALUES (?,?, 'owner', 'active')")
           ->execute([$organizationId, $ownerUserId]);
        return $organizationId;
    }

    /**
     * Writes a session file and returns its id, as a real cookie would present it.
     *
     * Encodes through serialize() rather than by hand: PHP's session format embeds the exact BYTE
     * LENGTH of every string, so a hand-written payload with an off-by-one key length is silently
     * DROPPED rather than rejected — which would quietly turn a "crafted impersonation" test into a
     * test of an ordinary session, passing for the wrong reason.
     */
    private function writeSession(array $data): string
    {
        $sid = bin2hex(random_bytes(16));
        $encoded = '';
        foreach ($data as $key => $value) {
            $encoded .= $key . '|' . serialize($value);
        }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
        return $sid;
    }

    private function sessionFor(int $userId): string
    {
        $now = time();
        return $this->writeSession(['uid' => $userId, '_created_at' => $now, '_last_activity' => $now]);
    }

    /** The server-side session payload, so a test can assert what the SERVER believes, not just what it rendered. */
    private function sessionPayload(string $sessionId): string
    {
        $path = $this->sessionDir . '/sess_' . $sessionId;
        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    /** The CSRF token the server would embed in a form for this session. */
    private function csrfFor(string $sessionId): string
    {
        $page = $this->request('GET', '/index.php', $sessionId);
        return preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m) === 1 ? $m[1] : '';
    }

    /** @return array{code:int, body:string, session_cookie:?string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true,
        ];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
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
        $newSession = preg_match('/Set-Cookie:\s*ELLSMS_SESSION=([^;]+)/i', $headers, $m) === 1 ? $m[1] : null;
        return ['code' => $code, 'body' => substr($raw, $headerSize), 'session_cookie' => $newSession];
    }

    /** Starts a support session over real HTTP and returns the NEW session id the server issued. */
    private function startImpersonation(string $adminSession, ?int $targetId = null): array
    {
        $csrf = $this->csrfFor($adminSession);
        $this->assertNotSame('', $csrf, 'could not read a CSRF token for the admin session');
        return $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => $csrf, 'do' => 'start',
            'target_user_id' => $targetId ?? $this->targetId,
            'reason' => 'ticket #99 — customer reports a failed send',
            'return_to' => '/users.php?edit=' . ($targetId ?? $this->targetId),
        ]);
    }

    /* ================= Authorization on the endpoint ================= */

    public function testAGetRequestCanNeverStartAnImpersonation(): void
    {
        // Only ever a confirmation page: a support session must not be reachable from a link, an
        // <img> tag, or a mistyped URL (STEP 9).
        $adminSession = $this->sessionFor($this->adminId);
        $r = $this->request('GET', '/impersonate.php?target=' . $this->targetId, $adminSession);
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('ورود به پنل مشتری', $r['body']);
        $this->assertStringNotContainsString('حالت پشتیبانی فعال است', $r['body'], 'the confirmation page must not itself be an impersonation');
        $this->assertStringContainsString('uid|i:' . $this->adminId, $this->sessionPayload($adminSession), 'the session is untouched by a GET');
    }

    public function testStartingRequiresAValidCsrfToken(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $r = $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => 'not-the-real-token', 'do' => 'start',
            'target_user_id' => $this->targetId, 'reason' => 'x',
        ]);
        $this->assertSame(400, $r['code'], 'a forged CSRF token must be rejected');
        $this->assertStringContainsString('uid|i:' . $this->adminId, $this->sessionPayload($adminSession));
        $this->assertStringNotContainsString('impersonation', $this->sessionPayload($adminSession));
    }

    public function testAnOrganizationOwnerCannotStartImpersonationEvenKnowingTheUrl(): void
    {
        // Platform-level functionality: no organization role reaches it (STEP 4).
        $ownerSession = $this->sessionFor($this->ownerId);
        $this->assertSame(403, $this->request('GET', '/impersonate.php?target=' . $this->targetId, $ownerSession)['code']);

        $csrf = $this->csrfFor($ownerSession);
        $r = $this->request('POST', '/impersonate.php', $ownerSession, [
            '_csrf' => $csrf, 'do' => 'start', 'target_user_id' => $this->targetId, 'reason' => 'let me in',
        ]);
        $this->assertSame(403, $r['code']);
        $this->assertStringNotContainsString('impersonation', $this->sessionPayload($ownerSession), 'zero mutation of the session');
    }

    public function testStartingWithoutAReasonIsRefused(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $csrf = $this->csrfFor($adminSession);
        $r = $this->request('POST', '/impersonate.php', $adminSession, [
            '_csrf' => $csrf, 'do' => 'start', 'target_user_id' => $this->targetId, 'reason' => '   ',
        ]);
        $this->assertSame(302, $r['code']);
        $this->assertStringNotContainsString('impersonation', $this->sessionPayload($adminSession));
    }

    public function testRepeatedStartsAreRateLimitedSoTargetIdsCannotBeEnumerated(): void
    {
        // STEP 35. The limit is deliberately low: an operator opens a handful of support sessions,
        // a script walking the user-id space opens hundreds.
        $adminSession = $this->sessionFor($this->adminId);
        $refusedAtLeastOnce = false;

        for ($attempt = 0; $attempt < 14; $attempt++) {
            $probeTarget = $this->makeCommittedUser(false);
            $csrf = $this->csrfFor($adminSession);
            if ($csrf === '') {
                break; // an earlier attempt already switched this session; nothing more to probe
            }
            $r = $this->request('POST', '/impersonate.php', $adminSession, [
                '_csrf' => $csrf, 'do' => 'start', 'target_user_id' => $probeTarget, 'reason' => 'probe',
            ]);
            if ($r['session_cookie'] !== null) {
                // A start succeeded — reset to a fresh admin session and keep probing the limiter.
                $adminSession = $this->sessionFor($this->adminId);
                continue;
            }
            $limited = (int)db()->query(
                "SELECT COUNT(*) FROM ellsms_audit_log WHERE action = 'impersonation.start_refused' AND details LIKE '%rate_limited%'"
            )->fetchColumn();
            if ($limited > 0) {
                $refusedAtLeastOnce = true;
                break;
            }
        }

        $this->assertTrue($refusedAtLeastOnce, 'repeated impersonation starts must eventually be rate-limited');
    }

    /* ================= Session fixation (Invariant F) ================= */

    public function testTheSessionIdChangesOnStartAndAgainOnExit(): void
    {
        $adminSession = $this->sessionFor($this->adminId);

        $start = $this->startImpersonation($adminSession);
        $this->assertSame(302, $start['code']);
        $impersonatedSession = $start['session_cookie'];
        $this->assertNotNull($impersonatedSession, 'starting must issue a NEW session cookie');
        $this->assertNotSame($adminSession, $impersonatedSession);
        $this->assertSame('', $this->sessionPayload($adminSession), 'the pre-impersonation session id must no longer exist');

        $payload = $this->sessionPayload($impersonatedSession);
        $this->assertStringContainsString('uid|i:' . $this->targetId, $payload);
        $this->assertStringContainsString('impersonation', $payload);

        $csrf = $this->csrfFor($impersonatedSession);
        $exit = $this->request('POST', '/impersonate.php', $impersonatedSession, ['_csrf' => $csrf, 'do' => 'exit']);
        $this->assertSame(302, $exit['code']);
        $adminAgain = $exit['session_cookie'];
        $this->assertNotNull($adminAgain, 'exiting must issue a NEW session cookie too');
        $this->assertNotSame($impersonatedSession, $adminAgain);
        $this->assertSame('', $this->sessionPayload($impersonatedSession), 'the impersonated session id must no longer exist');

        $restored = $this->sessionPayload($adminAgain);
        $this->assertStringContainsString('uid|i:' . $this->adminId, $restored);
        $this->assertStringNotContainsString('impersonation', $restored, 'no impersonation residue in the restored admin session');
    }

    /* ================= The impersonated experience ================= */

    public function testTheBannerAppearsOnOrdinaryPagesAndNamesTheAccount(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];

        $targetUsername = (string)db()->query("SELECT username FROM user_ WHERE id = {$this->targetId}")->fetchColumn();
        foreach (['/index.php', '/reports.php', '/contacts.php'] as $path) {
            $page = $this->request('GET', $path, $impersonated);
            $this->assertSame(200, $page['code'], $path);
            $this->assertStringContainsString('حالت پشتیبانی فعال است', $page['body'], "banner missing on {$path}");
            $this->assertStringContainsString($targetUsername, $page['body'], "banner does not name the account on {$path}");
            $this->assertStringContainsString('بازگشت به پنل مدیریت', $page['body'], "no exit control on {$path}");
        }
    }

    public function testThePlatformAdminAreaIsUnreachableWhileImpersonating(): void
    {
        // STEP 30 — and the reason it holds is structural: the effective user is an ordinary
        // customer, so require_admin() denies exactly as it would for any customer.
        $adminSession = $this->sessionFor($this->adminId);
        // sms-pricing.php rather than users.php as the positive control: users.php calls the backend
        // identity API for its domain list, which this test environment has no gateway for.
        $this->assertSame(200, $this->request('GET', '/sms-pricing.php', $adminSession)['code'], 'the admin can reach the admin area before starting');

        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];
        foreach (['/sms-pricing.php', '/settings.php', '/numbers.php', '/number-categories.php'] as $path) {
            $r = $this->request('GET', $path, $impersonated);
            $this->assertSame(403, $r['code'], "{$path} must be denied during impersonation");
            $this->assertStringContainsString('حالت پشتیبانی', $r['body'], 'and must say how to proceed');
        }
        // The admin sidebar must not be rendered either.
        $dashboard = $this->request('GET', '/index.php', $impersonated);
        $this->assertStringNotContainsString('تعرفه‌ی پیامک', $dashboard['body'], 'platform-admin navigation must not appear');
    }

    public function testTheImpersonatedSessionCannotStartAnotherImpersonation(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];
        $victimId = $this->makeCommittedUser(false);

        $csrf = $this->csrfFor($impersonated);
        $r = $this->request('POST', '/impersonate.php', $impersonated, [
            '_csrf' => $csrf, 'do' => 'start', 'target_user_id' => $victimId, 'reason' => 'nested',
        ]);
        $this->assertSame(403, $r['code'], 'nesting is refused at the endpoint guard');
        $this->assertStringContainsString('uid|i:' . $this->targetId, $this->sessionPayload($impersonated), 'still the original target');
    }

    /* ================= Blocked actions over HTTP ================= */

    public function testAPasswordChangeIsRefusedOverHttpDuringImpersonation(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];

        $csrf = $this->csrfFor($impersonated);
        $r = $this->request('POST', '/profile.php', $impersonated, [
            '_csrf' => $csrf, 'do' => 'password', 'current_password' => 'x', 'password' => 'newpassword1', 'password2' => 'newpassword1',
        ]);
        $this->assertSame(302, $r['code']);

        $follow = $this->request('GET', '/profile.php', $impersonated);
        $this->assertStringContainsString('تغییر رمز عبور در حالت پشتیبانی غیرفعال است', $follow['body']);

        $blocked = (int)db()->query(
            "SELECT COUNT(*) FROM ellsms_audit_log WHERE action = 'impersonation.blocked_sensitive_action' AND details = 'account.password' AND user_id = {$this->targetId}"
        )->fetchColumn();
        $this->assertSame(1, $blocked, 'the refused attempt is audited');
    }

    public function testTheSendPageShowsTheBlockedNoticeDuringImpersonation(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];

        $page = $this->request('GET', '/send.php', $impersonated);
        $this->assertSame(200, $page['code'], 'the page itself stays viewable — that is the point of support mode');
        $this->assertStringContainsString('ارسال واقعی در حالت پشتیبانی غیرفعال است', $page['body']);
    }

    /* ================= Logout semantics (STEP 11) ================= */

    public function testLogoutDuringImpersonationEndsTheWholeSessionRatherThanReturningToTheAdmin(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];

        $csrf = $this->csrfFor($impersonated);
        $r = $this->request('POST', '/logout.php', $impersonated, ['_csrf' => $csrf]);
        $this->assertSame(302, $r['code']);
        $this->assertSame('', $this->sessionPayload($impersonated), 'the session must be gone entirely');

        // And it is gone for the ADMIN too — logging out does not quietly hand the panel back.
        // Probed with an authenticated-only page: /index.php deliberately renders the public landing
        // page for a guest rather than redirecting, so it cannot distinguish the two states.
        $after = $this->request('GET', '/reports.php', $impersonated);
        $this->assertSame(302, $after['code'], 'a logged-out session is redirected to login');
    }

    /* ================= Fail-closed enforcement ================= */

    public function testAnActorWhoLosesPlatformAdminHasTheirSessionTerminatedOnTheNextRequest(): void
    {
        // STEP 33 — proven end to end here because the enforcement path terminates the request,
        // which cannot be observed in-process.
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];
        $this->assertSame(200, $this->request('GET', '/reports.php', $impersonated)['code']);

        db()->prepare('UPDATE ellsms_meta SET is_admin = 0 WHERE user_id = ?')->execute([$this->adminId]);

        $r = $this->request('GET', '/reports.php', $impersonated);
        $this->assertSame(302, $r['code'], 'the session must end, not continue as the customer');
        $this->assertSame('', $this->sessionPayload($impersonated), 'and be destroyed server-side');
    }

    public function testACraftedSessionClaimingImpersonationIsTerminated(): void
    {
        // A hand-written session file asserting impersonation with no valid actor: fail closed
        // (STEP 31). Nothing about it may be honoured — not the flag, and not the session.
        $now = time();
        $sid = $this->writeSession([
            'uid' => $this->targetId,
            '_created_at' => $now,
            '_last_activity' => $now,
            // actor == target: structurally shaped like impersonation, but not a valid one.
            'impersonation' => [
                'actor_user_id'  => $this->targetId,
                'target_user_id' => $this->targetId,
                'started_at'     => $now,
                'mode'           => 'support',
            ],
        ]);

        $r = $this->request('GET', '/reports.php', $sid);
        $this->assertSame(302, $r['code'], 'a crafted impersonation must not produce a usable session');
        $this->assertSame('', $this->sessionPayload($sid));
    }

    public function testAnExpiredSupportWindowReturnsTheOperatorToTheAdminPanel(): void
    {
        $adminSession = $this->sessionFor($this->adminId);
        $impersonated = $this->startImpersonation($adminSession)['session_cookie'];

        // Age the session past the bound, in the server's own session store.
        $payload = $this->sessionPayload($impersonated);
        $aged = preg_replace('/s:10:"started_at";i:\d+;/', 's:10:"started_at";i:' . (time() - 7200) . ';', $payload);
        file_put_contents($this->sessionDir . '/sess_' . $impersonated, $aged);

        $r = $this->request('GET', '/reports.php', $impersonated);
        $this->assertSame(302, $r['code']);
        $restored = $r['session_cookie'] ?? $impersonated;
        $payloadAfter = $this->sessionPayload($restored);
        $this->assertStringContainsString('uid|i:' . $this->adminId, $payloadAfter, 'the admin is restored, not stranded');
        $this->assertStringNotContainsString('impersonation', $payloadAfter);
    }
}
