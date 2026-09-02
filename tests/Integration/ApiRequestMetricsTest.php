<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #14 final audit — public API request metrics (app/Observability/ApiRequestMetrics.php,
 * public/api/index.php's register_shutdown_function()). Proves requests are counted exactly once
 * regardless of which exit path fires (route matched, 404 unmatched, or an early auth/gate
 * rejection), that bounded labels are used, and that the Prometheus exporter reads the same table.
 */
final class ApiRequestMetricsTest extends TestCase
{
    private $serverProc = null;
    private int $port;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        \db()->exec('DELETE FROM ellsms_api_request_metrics');

        $this->port = 19700 + random_int(0, 200);
        $env = [
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'API_ENABLED' => '1',
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
        \db()->exec('DELETE FROM ellsms_api_request_metrics');
    }

    private function get(string $path): int {
        $ch = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (int)$code;
    }

    public function testUnmatchedRouteIsCountedWithABoundedRouteLabel(): void
    {
        $this->assertSame(404, $this->get('/api/v1/this-route-does-not-exist'));
        $row = \db()->query("SELECT * FROM ellsms_api_request_metrics WHERE route = 'unmatched'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('4xx', $row['status_bucket']);
        $this->assertSame(1, (int)$row['request_count']);
    }

    public function testARequestRejectedByAnEarlyGateIsStillCountedExactlyOnce(): void
    {
        // No Authorization header -> ApiAuth::authenticate() returns null -> 401, an "early exit"
        // path distinct from the successful-dispatch path -- proving the shutdown-function approach
        // catches this branch too, not just the happy path.
        $this->assertSame(401, $this->get('/api/v1/me'));
        $row = \db()->query("SELECT * FROM ellsms_api_request_metrics WHERE route = 'api_handle_me'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('GET', $row['method']);
        $this->assertSame('4xx', $row['status_bucket']);
        $this->assertSame(1, (int)$row['request_count']);
    }

    public function testRepeatedRequestsToTheSameRouteIncrementTheSameRowRatherThanCreatingNewOnes(): void
    {
        $this->get('/api/v1/me');
        $this->get('/api/v1/me');
        $this->get('/api/v1/me');
        $rows = \db()->query("SELECT * FROM ellsms_api_request_metrics WHERE route = 'api_handle_me'")->fetchAll();
        $this->assertCount(1, $rows, 'one bounded row per (route, method, status), never one row per request');
        $this->assertSame(3, (int)$rows[0]['request_count']);
    }

    public function testPrometheusExporterReflectsTheSameTable(): void
    {
        $this->get('/api/v1/me');
        $output = \PrometheusExporter::render(\db());
        $this->assertStringContainsString('ellsms_api_requests_total{route="api_handle_me",method="GET",status="4xx"} 1', $output);
        $this->assertStringContainsString('ellsms_api_request_duration_ms_sum_total', $output);
    }
}
