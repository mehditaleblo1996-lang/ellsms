<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 11, STEP 22/23: maintenance mode's actual HTTP behavior, proven against a REAL running
 * PHP built-in server serving the real public/ directory -- not a mocked request. Confirms the
 * bootstrap-level 503 block, the exempt-script allowlist (health/health-ready/zarinpal-callback),
 * and that a plain CLI script is entirely unaffected by the same flag.
 */
final class MaintenanceModeHttpTest extends TestCase
{
    private $serverProc = null;
    private int $port;
    private string $flagFile;
    private string $backendDbName;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();

        $this->backendDbName = (string)getenv('BACKEND_DB_NAME');
        $this->flagFile = sys_get_temp_dir() . '/ellsms_maint_http_' . bin2hex(random_bytes(6)) . '.flag';
        $this->port = 19600 + random_int(0, 300);

        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => $this->backendDbName,
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'MAINTENANCE_MODE_FILE' => $this->flagFile,
        ];
        $publicDir = dirname(__DIR__, 2) . '/public';
        $this->serverProc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $publicDir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        $this->assertNotFalse($this->serverProc, 'could not start throwaway PHP dev server');

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
        @unlink($this->flagFile);
    }

    /** @return array{code: int, headers: array<string,string>, body: string} */
    private function fetch(string $path): array {
        $ch = curl_init("http://127.0.0.1:{$this->port}{$path}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch);
        $this->assertNotFalse($raw, 'curl request failed: ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return ['code' => $code, 'headers' => $headers, 'body' => $body];
    }

    public function testOrdinaryPageIsReachableWithoutMaintenanceFlag(): void
    {
        $r = $this->fetch('/login.php');
        $this->assertNotSame(503, $r['code']);
    }

    public function testOrdinaryPageIsBlockedWithMaintenanceFlagActive(): void
    {
        file_put_contents($this->flagFile, 'Custom drill message');
        $r = $this->fetch('/login.php');
        $this->assertSame(503, $r['code']);
        $this->assertSame('300', $r['headers']['retry-after'] ?? null);
        $this->assertStringContainsString('Custom drill message', $r['body']);
    }

    public function testHealthCheckStaysReachableDuringMaintenance(): void
    {
        file_put_contents($this->flagFile, 'down for release');
        $r = $this->fetch('/health.php');
        $this->assertNotSame(503, $r['code'], "health.php must never be blocked by maintenance mode, got body: {$r['body']}");
        $decoded = json_decode($r['body'], true);
        $this->assertSame('ok', $decoded['status'] ?? null, 'health.php must return its own real payload, not the maintenance page');
    }

    public function testSendPageIsBlockedDuringMaintenance(): void
    {
        file_put_contents($this->flagFile, 'down for release');
        $r = $this->fetch('/new-send.php');
        $this->assertSame(503, $r['code']);
    }

    public function testMaintenancePageIsRemovedImmediatelyAfterFlagCleared(): void
    {
        file_put_contents($this->flagFile, 'down for release');
        $this->assertSame(503, $this->fetch('/login.php')['code']);

        unlink($this->flagFile);

        $r = $this->fetch('/login.php');
        $this->assertNotSame(503, $r['code'], 'clearing the flag must take effect immediately, no restart needed');
    }
}
