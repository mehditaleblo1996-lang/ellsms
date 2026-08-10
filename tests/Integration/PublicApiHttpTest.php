<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 — public API security/behavior proven against a REAL running PHP built-in server serving
 * the real public/ directory (mirrors tests/Integration/MaintenanceModeHttpTest.php's pattern
 * exactly), not mocked requests: authentication failures, tenant isolation, scope enforcement,
 * stable error format, request correlation, pagination, idempotency over real HTTP, and payload
 * limits (STEP 44/45). One server + one pair of fixture organizations shared across the whole class
 * (setUpBeforeClass/tearDownAfterClass) — rate limiting is deliberately tested separately in
 * tests/Integration/ApiRateLimitHttpTest.php, which needs its own server with a tiny configured
 * limit that would otherwise interfere with every other test here.
 */
final class PublicApiHttpTest extends TestCase
{
    private static $serverProc = null;
    private static int $port;
    private static \PDO $db;
    private static array $orgA;
    private static array $orgB;
    private static string $rawKeyFullScopes;
    private static string $rawKeyReadOnlyContacts;
    private static int $orgAContactId;

    public static function setUpBeforeClass(): void
    {
        $self = new self('setUpBeforeClass');
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($self);
        IntegrationTestCase::ensureSchemaLoaded();
        self::$db = db();

        self::$orgA = self::makeCommittedOrg('Public API Test Org A');
        self::$orgB = self::makeCommittedOrg('Public API Test Org B');

        $full = api_key_create(self::$orgA['organization_id'], self::$orgA['owner_id'], 'full', [
            \ApiScopes::MESSAGES_SEND, \ApiScopes::MESSAGES_READ, \ApiScopes::CONTACTS_READ,
            \ApiScopes::CONTACTS_WRITE, \ApiScopes::BALANCE_READ, \ApiScopes::BULK_READ, \ApiScopes::BULK_WRITE,
        ]);
        self::$rawKeyFullScopes = $full['raw_key'];

        $readOnly = api_key_create(self::$orgA['organization_id'], self::$orgA['owner_id'], 'read-only', [\ApiScopes::CONTACTS_READ]);
        self::$rawKeyReadOnlyContacts = $readOnly['raw_key'];

        self::$db->prepare('INSERT INTO ellsms_contacts (user_id, organization_id, name, mobile, group_name) VALUES (?,?,?,?,?)')
            ->execute([self::$orgA['owner_id'], self::$orgA['organization_id'], 'Org A Contact', '989120000001', '']);
        self::$orgAContactId = (int)self::$db->lastInsertId();

        self::$port = 19700 + random_int(0, 200);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1',
            'API_MAX_BODY_BYTES' => '2048',
            'API_RATE_LIMIT_PER_MINUTE' => '1000',
            'API_RATE_LIMIT_BURST' => '1000', // this class isn't testing rate limiting (see ApiRateLimitHttpTest) -- keep it out of the way
            // Nothing listens on 127.0.0.1:1 -- the backend messaging client (app/Backend/ApiClient.php)
            // fails FAST with connection-refused instead of a slow DNS/connect-timeout wait, so the
            // message-send tests below (which don't need a real gateway, only the API-layer plumbing
            // around it) stay fast and deterministic.
            'API_BASE_URL' => 'http://127.0.0.1:1',
            'WEBHOOK_MASTER_KEY' => base64_encode(random_bytes(32)),
        ];
        $publicDir = dirname(__DIR__, 2) . '/public';
        self::$serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', $publicDir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $self->assertNotFalse(self::$serverProc, 'could not start throwaway PHP dev server');
        $booted = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $self->assertTrue($booted, 'throwaway dev server never accepted connections');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProc !== null) {
            proc_terminate(self::$serverProc);
            proc_close(self::$serverProc);
        }
        foreach ([self::$orgA, self::$orgB] as $org) {
            self::$db->prepare('DELETE FROM ellsms_contacts WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_idempotency_keys WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_api_messages WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare(
                'DELETE d FROM ellsms_webhook_deliveries d JOIN ellsms_webhook_events e ON e.id = d.event_id WHERE e.organization_id = ?'
            )->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_webhook_events WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_wallet_transactions WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_wallet_reservations WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$org['organization_id']]);
            self::$db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$org['owner_id']]);
            self::$db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$org['owner_id']]);
        }
    }

    private static function makeCommittedOrg(string $name): array {
        self::dbConnectIfNeeded();
        self::$db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['pubapi_' . bin2hex(random_bytes(4))]);
        $userId = (int)self::$db->lastInsertId();
        self::$db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $org = create_organization($userId, $name);
        return ['organization_id' => (int)$org['organization_id'], 'owner_id' => $userId];
    }

    private static function dbConnectIfNeeded(): void {
        if (!isset(self::$db)) {
            self::$db = db();
        }
    }

    /** @return array{code:int, headers:array<string,string>, body:string, json:?array} */
    private function request(string $method, string $path, ?string $bearer = null, ?string $body = null, array $extraHeaders = []): array {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $headers = ['Content-Type: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            // 10s, not 5s: POST /api/v1/messages internally attempts a real curl connection to the
            // (deliberately unreachable) fake backend with its OWN 5s connect timeout
            // (app/Backend/ApiClient.php's default) before this endpoint can respond at all — 5s
            // here would race that and flake.
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $this->assertNotFalse($raw, 'curl request failed: ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($raw, 0, $headerSize);
        $respBody = substr($raw, $headerSize);
        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return ['code' => $code, 'headers' => $respHeaders, 'body' => $respBody, 'json' => json_decode($respBody, true)];
    }

    /* ---------- Authentication (STEP 44) ---------- */

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $r = $this->request('GET', '/api/v1/me');
        $this->assertSame(401, $r['code']);
        $this->assertSame('unauthenticated', $r['json']['error']['code'] ?? null);
    }

    public function testMalformedBearerTokenReturns401(): void
    {
        $r = $this->request('GET', '/api/v1/me', 'not-a-real-key');
        $this->assertSame(401, $r['code']);
    }

    public function testWrongSecretReturns401(): void
    {
        $tampered = substr(self::$rawKeyFullScopes, 0, -2) . 'zz';
        $r = $this->request('GET', '/api/v1/me', $tampered);
        $this->assertSame(401, $r['code']);
    }

    public function testRevokedKeyReturns401(): void
    {
        $revocable = api_key_create(self::$orgA['organization_id'], self::$orgA['owner_id'], 'to-revoke', [\ApiScopes::CONTACTS_READ]);
        $r1 = $this->request('GET', '/api/v1/me', $revocable['raw_key']);
        $this->assertSame(200, $r1['code']);

        api_key_revoke(self::$orgA['organization_id'], $revocable['id'], self::$orgA['owner_id']);
        $r2 = $this->request('GET', '/api/v1/me', $revocable['raw_key']);
        $this->assertSame(401, $r2['code']);
    }

    public function testExpiredKeyReturns401(): void
    {
        $expired = api_key_create(self::$orgA['organization_id'], self::$orgA['owner_id'], 'expired', [\ApiScopes::CONTACTS_READ], 'live', '2000-01-01 00:00:00');
        $r = $this->request('GET', '/api/v1/me', $expired['raw_key']);
        $this->assertSame(401, $r['code']);
    }

    public function testApiKeyIsNeverAcceptedFromQueryString(): void
    {
        // No Authorization header at all -- only a query-string parameter, which this API never
        // reads for credentials (STEP 12) -- must behave identically to no credentials whatsoever.
        $r = $this->request('GET', '/api/v1/me?access_token=' . urlencode(self::$rawKeyFullScopes));
        $this->assertSame(401, $r['code']);
    }

    /* ---------- Scope enforcement ---------- */

    public function testMissingScopeReturns403(): void
    {
        $r = $this->request('POST', '/api/v1/contacts', self::$rawKeyReadOnlyContacts, json_encode(['mobile' => '09120000000']));
        $this->assertSame(403, $r['code']);
        $this->assertSame('forbidden', $r['json']['error']['code'] ?? null);
    }

    public function testGrantedScopeIsAllowed(): void
    {
        $r = $this->request('GET', '/api/v1/contacts', self::$rawKeyReadOnlyContacts);
        $this->assertSame(200, $r['code']);
    }

    /* ---------- Tenant isolation (Invariant B) ---------- */

    public function testCrossTenantContactReadReturns404NotTheData(): void
    {
        $orgBKey = api_key_create(self::$orgB['organization_id'], self::$orgB['owner_id'], 'k', [\ApiScopes::CONTACTS_READ]);
        $r = $this->request('GET', '/api/v1/contacts/' . self::$orgAContactId, $orgBKey['raw_key']);
        $this->assertSame(404, $r['code']);
    }

    public function testCrossTenantContactDeleteHasNoEffect(): void
    {
        $orgBKey = api_key_create(self::$orgB['organization_id'], self::$orgB['owner_id'], 'k', [\ApiScopes::CONTACTS_WRITE]);
        $this->request('DELETE', '/api/v1/contacts/' . self::$orgAContactId, $orgBKey['raw_key']);

        $stillThere = self::$db->query('SELECT COUNT(*) c FROM ellsms_contacts WHERE id = ' . self::$orgAContactId)->fetch()['c'];
        $this->assertSame('1', (string)$stillThere, 'a crafted cross-tenant id must never delete another organization\'s row');
    }

    public function testOrganizationEndpointOnlyEverReturnsTheCallersOwnOrganization(): void
    {
        $orgBKey = api_key_create(self::$orgB['organization_id'], self::$orgB['owner_id'], 'k', [\ApiScopes::CONTACTS_READ]);
        $r = $this->request('GET', '/api/v1/organization', $orgBKey['raw_key']);
        $this->assertSame(200, $r['code']);
        $this->assertSame(self::$orgB['organization_id'], $r['json']['data']['id'] ?? null);
    }

    /* ---------- Stable error format / request correlation (STEP 5/26) ---------- */

    public function testUnknownRouteReturns404WithStableFormat(): void
    {
        $r = $this->request('GET', '/api/v1/does-not-exist', self::$rawKeyFullScopes);
        $this->assertSame(404, $r['code']);
        $this->assertSame('not_found', $r['json']['error']['code'] ?? null);
        $this->assertArrayHasKey('request_id', $r['json']['error']);
    }

    public function testRequestIdIsReturnedInHeaderAndBody(): void
    {
        $r = $this->request('GET', '/api/v1/does-not-exist', self::$rawKeyFullScopes);
        $this->assertArrayHasKey('x-request-id', $r['headers']);
        $this->assertSame($r['headers']['x-request-id'], $r['json']['error']['request_id']);
    }

    public function testErrorMessageNeverLeaksInternals(): void
    {
        $r = $this->request('GET', '/api/v1/does-not-exist', self::$rawKeyFullScopes);
        $this->assertStringNotContainsString('/mnt/', $r['body']);
        $this->assertStringNotContainsString('.php', $r['body']);
        $this->assertStringNotContainsString('SELECT', $r['body']);
    }

    /* ---------- Request validation / size limits (STEP 15/16) ---------- */

    public function testOversizedBodyReturns413(): void
    {
        $hugeBody = json_encode(['name' => str_repeat('a', 4000), 'mobile' => '09120000000']);
        $r = $this->request('POST', '/api/v1/contacts', self::$rawKeyFullScopes, $hugeBody);
        $this->assertSame(413, $r['code']);
        $this->assertSame('payload_too_large', $r['json']['error']['code'] ?? null);
    }

    public function testInvalidJsonReturns400(): void
    {
        $r = $this->request('POST', '/api/v1/contacts', self::$rawKeyFullScopes, '{not valid json');
        $this->assertSame(400, $r['code']);
    }

    public function testWrongContentTypeIsRejected(): void
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/api/v1/contacts');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::$rawKeyFullScopes, 'Content-Type: text/plain'],
            CURLOPT_POSTFIELDS => json_encode(['mobile' => '09120000000']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->assertSame(415, $code);
    }

    public function testInvalidMobileReturnsValidationFailed(): void
    {
        $r = $this->request('POST', '/api/v1/contacts', self::$rawKeyFullScopes, json_encode(['mobile' => 'not-a-number']));
        $this->assertSame(422, $r['code']);
        $this->assertSame('validation_failed', $r['json']['error']['code'] ?? null);
        $this->assertArrayHasKey('mobile', $r['json']['error']['fields'] ?? []);
    }

    /* ---------- Contacts CRUD + pagination (STEP 22/23) ---------- */

    public function testCreateReadUpdateDeleteContactRoundTrip(): void
    {
        $create = $this->request('POST', '/api/v1/contacts', self::$rawKeyFullScopes, json_encode(['mobile' => '09121234567', 'name' => 'Round Trip']));
        $this->assertSame(201, $create['code']);
        $id = $create['json']['data']['id'];

        $get = $this->request('GET', '/api/v1/contacts/' . $id, self::$rawKeyFullScopes);
        $this->assertSame(200, $get['code']);
        $this->assertSame('Round Trip', $get['json']['data']['name']);

        $update = $this->request('PATCH', '/api/v1/contacts/' . $id, self::$rawKeyFullScopes, json_encode(['name' => 'Renamed']));
        $this->assertSame(200, $update['code']);
        $this->assertSame('Renamed', $update['json']['data']['name']);

        $delete = $this->request('DELETE', '/api/v1/contacts/' . $id, self::$rawKeyFullScopes);
        $this->assertSame(200, $delete['code']);

        $getAfterDelete = $this->request('GET', '/api/v1/contacts/' . $id, self::$rawKeyFullScopes);
        $this->assertSame(404, $getAfterDelete['code']);
    }

    public function testContactsListRespectsLimitAndReturnsCursor(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', '/api/v1/contacts', self::$rawKeyFullScopes, json_encode(['mobile' => '0912000' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)]));
        }
        $r = $this->request('GET', '/api/v1/contacts?limit=2', self::$rawKeyFullScopes);
        $this->assertSame(200, $r['code']);
        $this->assertCount(2, $r['json']['data']);
        $this->assertNotNull($r['json']['meta']['next_cursor'] ?? null);
    }

    /* ---------- Balance (STEP 24) ---------- */

    public function testBalanceReadsFromWalletService(): void
    {
        $r = $this->request('GET', '/api/v1/balance', self::$rawKeyFullScopes);
        $this->assertSame(200, $r['code']);
        $this->assertArrayHasKey('available', $r['json']['data']);
        $this->assertSame(wallet_balance(self::$orgA['owner_id'])['available'], $r['json']['data']['available']);
    }

    /* ---------- Idempotency over real HTTP (STEP 17/18) ---------- */

    public function testMessagesSendRequiresIdempotencyKeyHeader(): void
    {
        $r = $this->request('POST', '/api/v1/messages', self::$rawKeyFullScopes, json_encode(['destinations' => ['09120000000'], 'content' => 'hi']));
        $this->assertSame(400, $r['code']);
    }

    public function testMessagesSendIsIdempotentAcrossRepeatedRealRequests(): void
    {
        $key = 'http-idem-' . bin2hex(random_bytes(6));
        $body = json_encode(['destinations' => ['09120000000'], 'content' => 'idempotent test']);

        $first = $this->request('POST', '/api/v1/messages', self::$rawKeyFullScopes, $body, ['Idempotency-Key' => $key]);
        $second = $this->request('POST', '/api/v1/messages', self::$rawKeyFullScopes, $body, ['Idempotency-Key' => $key]);

        $this->assertSame($first['code'], $second['code']);
        $this->assertSame($first['body'], $second['body'], 'a replayed idempotent request must return byte-identical output');

        $count = self::$db->query("SELECT COUNT(*) c FROM ellsms_api_messages WHERE idempotency_key = " . self::$db->quote($key))->fetch()['c'];
        $this->assertSame('1', (string)$count, 'exactly one ellsms_api_messages row for two identical idempotent HTTP calls');
    }

    public function testMessagesSendWithSameKeyDifferentBodyReturnsConflict(): void
    {
        $key = 'http-idem-conflict-' . bin2hex(random_bytes(6));
        $this->request('POST', '/api/v1/messages', self::$rawKeyFullScopes, json_encode(['destinations' => ['09120000000'], 'content' => 'first']), ['Idempotency-Key' => $key]);
        $r = $this->request('POST', '/api/v1/messages', self::$rawKeyFullScopes, json_encode(['destinations' => ['09120000000'], 'content' => 'different']), ['Idempotency-Key' => $key]);
        $this->assertSame(409, $r['code']);
        $this->assertSame('conflict', $r['json']['error']['code'] ?? null);
    }

    /* ---------- API disabled (STEP 57) ---------- */

    public function testApiDisabledReturns503(): void
    {
        // A second, throwaway server instance with API_ENABLED unset (defaults to 0) -- proves the
        // safe-off-by-default gate independent of this class's main (enabled) server.
        $port = 19750 + random_int(0, 200);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
        ];
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__, 2) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); break; }
        }
        $ch = curl_init("http://127.0.0.1:{$port}/api/v1/me");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        proc_terminate($proc);
        proc_close($proc);

        $this->assertSame(503, $code);
        $this->assertSame('service_unavailable', json_decode((string)$body, true)['error']['code'] ?? null);
    }
}
