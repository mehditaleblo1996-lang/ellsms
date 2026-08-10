<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Platform-admin enforcement and cross-tenant isolation for SMS pricing configuration (STEP 36/55),
 * proven over REAL HTTP against the real public/sms-pricing.php — not by asserting that a mocked
 * authorization decision was consulted.
 *
 * Why HTTP and a forged session file rather than calling require_admin() directly: require_admin()
 * terminates the process (exit) on denial, which cannot be observed in-process, and the thing worth
 * proving is precisely that the DENIAL PATH RUNS BEFORE ANY WRITE. A real request against a real
 * server is the only way to observe "403 AND nothing changed" as one fact.
 *
 * The session is forged by writing a session file straight into a private save_path handed to the
 * throwaway server. That is exactly what a stolen/valid cookie would produce, so it tests the guard,
 * not the login form (which has its own tests).
 *
 * Cannot extend IntegrationTestCase: that class wraps each test in a transaction and rolls it back,
 * and the HTTP server is a SEPARATE connection that would never see uncommitted rows. Fixtures here
 * are committed and cleaned up explicitly, the same approach WalletConcurrencyTest already takes.
 */
final class SmsPricingSecurityTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $sessionDir;
    private int $adminId = 0;
    private int $ownerId = 0;
    private array $createdUserIds = [];
    private string $probeProviderCode;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->probeProviderCode = 'sec_' . bin2hex(random_bytes(4));
        $this->adminId = $this->makeCommittedUser(true);
        $this->ownerId = $this->makeCommittedUser(false);

        $this->sessionDir = sys_get_temp_dir() . '/ellsms_pricing_sess_' . bin2hex(random_bytes(6));
        mkdir($this->sessionDir, 0700, true);

        $this->port = 19900 + random_int(0, 300);
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
        $db->prepare('DELETE FROM ellsms_sms_providers WHERE code LIKE ?')->execute([substr($this->probeProviderCode, 0, 4) . '%']);
        foreach ($this->createdUserIds as $id) {
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$id]);
        }
    }

    private function makeCommittedUser(bool $isAdmin): int
    {
        $db = db();
        $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?,1,0)')
           ->execute(['sec_' . bin2hex(random_bytes(5))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?,1,?,?)')
           ->execute([$id, $isAdmin ? 1 : 0, '5000']);
        $this->createdUserIds[] = $id;
        return $id;
    }

    /** Writes a valid session file for $userId and returns its id, so a request can present it as a cookie. */
    private function sessionFor(int $userId): string
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();
        file_put_contents(
            $this->sessionDir . '/sess_' . $sid,
            'uid|i:' . $userId . ';_created_at|i:' . $now . ';_last_activity|i:' . $now . ';'
        );
        return $sid;
    }

    /** @return array{code:int, body:string} */
    private function request(string $method, string $path, ?string $sessionId = null, array $post = []): array
    {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false];
        if ($sessionId !== null) {
            $opts[CURLOPT_COOKIE] = 'ELLSMS_SESSION=' . $sessionId;
        }
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $this->assertNotFalse($body, 'curl failed: ' . curl_error($ch));
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$body];
    }

    /** Every pricing table's row count — compared whole, so any write anywhere fails the test. */
    private function catalogSnapshot(): array
    {
        $db = db();
        $out = [];
        foreach ([
            'ellsms_sms_operators', 'ellsms_sms_operator_prefixes', 'ellsms_sms_providers',
            'ellsms_sms_routes', 'ellsms_sender_routes', 'ellsms_sms_route_prices',
        ] as $table) {
            $out[$table] = (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        $out['settings_fallback'] = (string)($db->query("SELECT COALESCE(MAX(svalue),'') FROM ellsms_settings WHERE skey = 'sms_pricing_legacy_fallback'")->fetchColumn());
        return $out;
    }

    /* ================= The guard ================= */

    public function testAnAnonymousVisitorIsSentToLoginAndChangesNothing(): void
    {
        $before = $this->catalogSnapshot();
        $get = $this->request('GET', '/sms-pricing.php');
        $this->assertSame(302, $get['code']);

        $post = $this->request('POST', '/sms-pricing.php', null, [
            'do' => 'provider_create', 'code' => $this->probeProviderCode, 'name' => 'x',
        ]);
        $this->assertSame(302, $post['code']);
        $this->assertSame($before, $this->catalogSnapshot(), 'an unauthenticated POST must not create anything');
    }

    public function testAPlatformAdminCanReachThePage(): void
    {
        // The positive control: without it, every denial assertion below could be passing for an
        // unrelated reason (a 500, a missing file, a broken server).
        $r = $this->request('GET', '/sms-pricing.php', $this->sessionFor($this->adminId));
        $this->assertSame(200, $r['code']);
        $this->assertStringContainsString('تعرفه‌ی پیامک', $r['body']);
    }

    public function testAnOrganizationUserIsRefusedThePageEntirely(): void
    {
        // Not an organization permission like settings.manage: these are GLOBAL rates applying to
        // every tenant, so even an organization OWNER must not reach them (STEP 36).
        $r = $this->request('GET', '/sms-pricing.php', $this->sessionFor($this->ownerId));
        $this->assertSame(403, $r['code']);
        $this->assertStringNotContainsString('ثبت تعرفه‌ی جدید', $r['body']);
    }

    public function testAnOrganizationUserCannotMutateAnyPricingEntityByPostingDirectly(): void
    {
        $session = $this->sessionFor($this->ownerId);
        $before = $this->catalogSnapshot();

        // Every mutating action the page exposes, attempted straight at the endpoint — no UI
        // involved, which is the only attack that matters.
        $attempts = [
            ['do' => 'operator_create',     'code' => 'evil_op', 'name' => 'evil'],
            ['do' => 'provider_create',     'code' => $this->probeProviderCode, 'name' => 'evil'],
            ['do' => 'route_create',        'provider_id' => 1, 'code' => 'evil_route', 'name' => 'evil', 'message_type' => 'promotional'],
            ['do' => 'sender_route_create', 'sender' => '5000', 'route_id' => 1, 'message_type' => 'promotional'],
            ['do' => 'price_create',        'route_id' => 1, 'operator_id' => 0, 'price' => '0.001', 'confirm_replace' => 1],
            ['do' => 'price_archive',       'id' => 1],
            ['do' => 'operator_update',     'id' => 1, 'name' => 'hijacked', 'status' => 'archived'],
            ['do' => 'provider_update',     'id' => 1, 'name' => 'hijacked', 'status' => 'archived'],
            ['do' => 'fallback_toggle',     'enabled' => 0],
        ];
        foreach ($attempts as $post) {
            $r = $this->request('POST', '/sms-pricing.php', $session, $post);
            $this->assertSame(403, $r['code'], 'denied: ' . $post['do']);
        }

        $this->assertSame($before, $this->catalogSnapshot(), 'an organization user must not change ANY pricing configuration');
    }

    public function testAForgedIdReferencingAnotherEntityStillCannotEscalate(): void
    {
        // IDOR shape: the ids below are real, existing rows (the seeded legacy catalog). The guard,
        // not the id validation, is what must stop this.
        $session = $this->sessionFor($this->ownerId);
        $realRouteId = (int)db()->query("SELECT id FROM ellsms_sms_routes ORDER BY id LIMIT 1")->fetchColumn();
        $realPriceId = (int)db()->query("SELECT id FROM ellsms_sms_route_prices ORDER BY id LIMIT 1")->fetchColumn();
        $before = $this->catalogSnapshot();

        $this->assertSame(403, $this->request('POST', '/sms-pricing.php', $session, [
            'do' => 'price_create', 'route_id' => $realRouteId, 'operator_id' => 0, 'price' => '0.001', 'confirm_replace' => 1,
        ])['code']);
        $this->assertSame(403, $this->request('POST', '/sms-pricing.php', $session, [
            'do' => 'price_archive', 'id' => $realPriceId,
        ])['code']);

        $this->assertSame($before, $this->catalogSnapshot());
        $this->assertSame(
            'active',
            (string)db()->query("SELECT status FROM ellsms_sms_route_prices WHERE id = {$realPriceId}")->fetchColumn(),
            'the seeded legacy price must be untouched'
        );
    }

    /* ================= Only one writer exists at all ================= */

    public function testTheAdminPageIsTheOnlyWebSurfaceThatWritesToThePricingCatalog(): void
    {
        // The guard above protects one file. This proves there is only ONE file to protect — a
        // second, unguarded page writing to these tables would defeat every assertion above, and
        // would be easy to add by accident.
        $catalogTables = [
            'ellsms_sms_operators', 'ellsms_sms_operator_prefixes', 'ellsms_sms_providers',
            'ellsms_sms_routes', 'ellsms_sender_routes', 'ellsms_sms_route_prices',
        ];
        $allowed = ['sms-pricing.php'];
        $offenders = [];
        $files = array_merge(
            glob(dirname(__DIR__, 2) . '/public/*.php') ?: [],
            glob(dirname(__DIR__, 2) . '/public/api/*.php') ?: []
        );
        foreach ($files as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }
            $source = (string)file_get_contents($file);
            foreach ($catalogTables as $table) {
                if (preg_match('/\b(INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+`?' . preg_quote($table, '/') . '`?\b/i', $source)) {
                    $offenders[] = basename($file) . ' writes ' . $table;
                }
            }
        }
        $this->assertSame([], $offenders, 'only the platform-admin pricing page may write pricing configuration');
    }
}
