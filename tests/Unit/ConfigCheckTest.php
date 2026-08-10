<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 10, STEP 3/4/45: cron/config-check.php is a top-level script (like every other cron/*.php
 * tool in this codebase), so it's exercised as a real subprocess with a crafted environment,
 * asserting on its actual exit code and --json output — the same pattern established by
 * tests/Integration/ApiClientFailureModelTest.php for a different top-level-script case.
 */
final class ConfigCheckTest extends TestCase
{
    private function runConfigCheck(array $env): array {
        $root = dirname(__DIR__, 2);
        $cmd = array_map(
            static fn($k, $v) => escapeshellarg("{$k}={$v}"),
            array_keys($env),
            $env
        );
        $fullCmd = 'env -i ' . implode(' ', $cmd) . ' PATH=' . escapeshellarg((string)getenv('PATH'))
            . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cron/config-check.php') . ' --json 2>&1';
        exec($fullCmd, $output, $exitCode);
        // config-check.php's own --json output can be preceded by an unrelated Logger CLI mirror
        // line (e.g. app_debug()'s own "APP_DEBUG=1 ignored in production" warning) -- the JSON
        // block itself always starts at the first line beginning with "{".
        $jsonStart = null;
        foreach ($output as $i => $line) {
            if (str_starts_with(trim($line), '{')) {
                $jsonStart = $i;
                break;
            }
        }
        $jsonText = $jsonStart !== null ? implode("\n", array_slice($output, $jsonStart)) : '';
        $decoded = json_decode($jsonText, true);
        return ['exit' => $exitCode, 'json' => $decoded, 'raw' => implode("\n", $output)];
    }

    private const PRODUCTION_BASELINE = [
        'APP_ENV' => 'production',
        'BACKEND_DB_HOST' => 'db.internal',
        'BACKEND_DB_NAME' => 'ellsms_prod',
        'BACKEND_DB_USER' => 'ellsms_app',
        'BACKEND_DB_PASS' => 'a-real-random-password',
        'API_BASE_URL' => 'https://api.example.com',
        'APP_URL' => 'https://sms.example.com',
        'TRUSTED_PROXY_IPS' => '172.20.0.5',
        // Phase 12 — a "fully configured, nothing missing" production baseline now also includes
        // the public API turned on with a real (well-formed, non-placeholder) master key, so this
        // fixture keeps meaning "everything an operator would actually set is set."
        'API_ENABLED' => '1',
        'WEBHOOK_MASTER_KEY' => 'MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE=', // base64(32 arbitrary bytes) -- test-only, not a real secret
    ];

    public function testCleanProductionConfigPasses(): void
    {
        $result = $this->runConfigCheck(self::PRODUCTION_BASELINE);
        $this->assertSame(0, $result['exit'], $result['raw']);
        $this->assertSame('PASS', $result['json']['status'] ?? null, $result['raw']);
    }

    public function testMissingDatabaseCredentialsFail(): void
    {
        $env = self::PRODUCTION_BASELINE;
        unset($env['BACKEND_DB_HOST']);
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $this->assertSame('FAIL', $result['json']['status'] ?? null);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('BACKEND_DB_HOST', $keys);
    }

    public function testPlaceholderDatabaseCredentialFailsInProduction(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['BACKEND_DB_USER'] = 'change_me';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $this->assertSame('FAIL', $result['json']['status'] ?? null);
    }

    public function testWeakHmacSecretFailsInProduction(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['BACKEND_SERVICE_ID'] = 'svc1';
        $env['BACKEND_SERVICE_SECRET'] = 'changeme';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('BACKEND_SERVICE_SECRET', $keys);
    }

    public function testLoadTestFlagFailsInProduction(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['ELLSMS_ALLOW_LOAD_TEST'] = '1';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('ELLSMS_ALLOW_LOAD_TEST', $keys);
    }

    public function testFakeBackendVariableFailsInProduction(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['FAKE_BACKEND_FAILURE_RATE'] = '1';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('FAKE_BACKEND_FAILURE_RATE', $keys);
    }

    public function testNonNumericRateLimitSettingFails(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['RATE_LIMIT_LOGIN_MAX'] = 'not-a-number';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('RATE_LIMIT_LOGIN_MAX', $keys);
    }

    public function testInvalidMetricsSampleRateFails(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['METRICS_LOG_SAMPLE_RATE'] = '2.5';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('METRICS_LOG_SAMPLE_RATE', $keys);
    }

    public function testMalformedTrustedProxyEntryFails(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['TRUSTED_PROXY_IPS'] = 'not-an-ip-or-cidr';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('TRUSTED_PROXY_IPS', $keys);
    }

    public function testApiEnabledWithoutMasterKeyFailsInProduction(): void
    {
        $env = self::PRODUCTION_BASELINE;
        unset($env['WEBHOOK_MASTER_KEY']);
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('WEBHOOK_MASTER_KEY', $keys);
    }

    public function testApiEnabledWithWrongLengthMasterKeyFails(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['WEBHOOK_MASTER_KEY'] = base64_encode('too-short');
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('WEBHOOK_MASTER_KEY', $keys);
    }

    public function testApiDisabledIsWarnNotFail(): void
    {
        $env = self::PRODUCTION_BASELINE;
        unset($env['API_ENABLED'], $env['WEBHOOK_MASTER_KEY']);
        $result = $this->runConfigCheck($env);
        $this->assertSame(0, $result['exit']);
        $this->assertSame('WARN', $result['json']['status'] ?? null);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('API_ENABLED', $keys);
    }

    public function testWebhookHttpAllowedInProductionFails(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['WEBHOOK_REQUIRE_HTTPS'] = '0';
        $result = $this->runConfigCheck($env);
        $this->assertSame(1, $result['exit']);
        $keys = array_column($result['json']['findings'], 'key');
        $this->assertContains('WEBHOOK_REQUIRE_HTTPS', $keys);
    }

    public function testDebugForcedOnInProductionIsWarnNotFail(): void
    {
        $env = self::PRODUCTION_BASELINE;
        $env['APP_DEBUG'] = '1';
        $result = $this->runConfigCheck($env);
        // Not a blocker -- app_debug() already hard-forces this off in production, this is purely
        // informational for whoever wrote a self-contradictory .env.
        $this->assertSame(0, $result['exit']);
        $this->assertSame('WARN', $result['json']['status'] ?? null);
    }
}
