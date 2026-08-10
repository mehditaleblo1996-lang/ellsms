<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 14) — API rate limiting proven against a real running server with a deliberately
 * tiny configured limit, isolated from tests/Integration/PublicApiHttpTest.php (which needs a
 * generous limit so its many functional assertions don't trip this). Also proves a forged
 * X-Forwarded-For cannot be used to dodge the IP-dimension bucket, reusing the exact trusted-proxy
 * mechanism app/bootstrap.php/app/rate_limit.php already established (no TRUSTED_PROXY_IPS
 * configured here, so every X-Forwarded-For value must be ignored).
 */
final class ApiRateLimitHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private \PDO $db;
    private array $org;
    private string $rawKey;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();

        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['ratelimit_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $org = create_organization($userId, 'Rate Limit Test Org');
        $this->org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $userId];
        $key = api_key_create($this->org['organization_id'], $userId, 'k', [\ApiScopes::BALANCE_READ]);
        $this->rawKey = $key['raw_key'];

        $this->port = 19800 + random_int(0, 200);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1',
            'API_RATE_LIMIT_PER_MINUTE' => '3',
            'API_RATE_LIMIT_BURST' => '3',
            'WEBHOOK_MASTER_KEY' => base64_encode(random_bytes(32)),
        ];
        $this->serverProc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', dirname(__DIR__, 2) . '/public'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertNotFalse($this->serverProc);
        $booted = false;
        for ($i = 0; $i < 30; $i++) {
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
        }
        $orgId = $this->org['organization_id'];
        $this->db->prepare('DELETE FROM ellsms_api_keys WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->org['owner_id']]);
        $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->org['owner_id']]);
    }

    private function get(string $path, ?string $xff = null): array {
        $ch = curl_init('http://127.0.0.1:' . $this->port . $path);
        $headers = ['Authorization: Bearer ' . $this->rawKey];
        if ($xff !== null) {
            $headers[] = 'X-Forwarded-For: ' . $xff;
        }
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string)$raw, 0, $headerSize);
        $body = substr((string)$raw, $headerSize);
        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return ['code' => $code, 'headers' => $respHeaders, 'body' => $body];
    }

    public function testExceedingThePerKeyLimitReturns429WithRetryAfter(): void
    {
        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->get('/api/v1/balance')['code'];
        }
        $this->assertContains(429, $codes, 'the 4th/5th request against a limit of 3/min must be rate-limited');

        $limited = $this->get('/api/v1/balance');
        $this->assertSame(429, $limited['code']);
        $this->assertSame('rate_limited', json_decode($limited['body'], true)['error']['code'] ?? null);
        $this->assertArrayHasKey('retry-after', $limited['headers']);
    }

    public function testForgedXForwardedForDoesNotResetTheEffectiveLimit(): void
    {
        // No TRUSTED_PROXY_IPS configured for this server -- every one of these X-Forwarded-For
        // values must be ignored entirely (app/bootstrap.php's request_from_trusted_proxy()), so
        // spraying a fresh fake IP on every request must NOT look like traffic from many distinct
        // clients to the rate limiter.
        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->get('/api/v1/balance', '10.0.0.' . random_int(1, 254))['code'];
        }
        $this->assertContains(429, $codes, 'a forged X-Forwarded-For must not let a single real client dodge the per-key rate limit');
    }
}
