<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Issue #14 — real HTTP smoke test for the Prometheus scrape endpoint (public/metrics.php), plus a
 * direct-call sanity check of PrometheusExporter::render() against real seeded data so the numbers
 * aren't just "didn't crash" but actually reflect what's in the database.
 */
final class PrometheusMetricsEndpointTest extends TestCase
{
    private $serverProc = null;
    private int $port;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        IntegrationTestCase::ensureSchemaLoaded();
        // Guarantee at least one provider_health row exists and is committed (a separate php -S
        // subprocess reads it) regardless of what other tests have or haven't run against this
        // database yet -- a pristine DB has zero rows in ellsms_provider_health_state.
        \provider_health_record_success('legacy_backend', 12.5);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    private function startServer(array $extraEnv = []): void {
        $this->port = 19900 + random_int(0, 200);
        $env = array_merge([
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
        ], $extraEnv);
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

    private function stopServer(): void {
        if ($this->serverProc !== null) {
            proc_terminate($this->serverProc);
            proc_close($this->serverProc);
            $this->serverProc = null;
        }
    }

    private function get(string $path, array $headers = []): array {
        $ch = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 5]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        return ['code' => $code, 'content_type' => $contentType, 'body' => substr((string)$raw, $headerSize)];
    }

    public function testScrapeEndpointReturnsValidPrometheusExpositionTextByDefault(): void
    {
        // PHP's built-in dev server (php -S) does not apply docker/apache-clean-urls.conf's
        // rewrite rules -- that mapping (/metrics -> metrics.php) is proven statically instead by
        // tests/Unit/CleanUrlRoutingTest.php. This hits the real file directly, same as every other
        // subprocess-server HTTP test in this suite hits e.g. /api/v1/... against public/api/index.php.
        $this->startServer();
        $resp = $this->get('/metrics.php');

        $this->assertSame(200, $resp['code']);
        $this->assertStringContainsString('text/plain', (string)$resp['content_type']);
        $this->assertStringContainsString('# HELP ellsms_db_up', $resp['body']);
        $this->assertStringContainsString('# TYPE ellsms_db_up gauge', $resp['body']);
        $this->assertStringContainsString('ellsms_db_up 1', $resp['body']);
        $this->assertStringContainsString('ellsms_queue_bulk_depth{message_class="bulk_campaign"}', $resp['body']);
        $this->assertStringContainsString('ellsms_provider_health_status{provider_key="legacy_backend"', $resp['body']);

        foreach (explode("\n", trim($resp['body'])) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[^}]*\})?\s+\S+$/', $line, "malformed exposition line: {$line}");
        }
    }

    public function testMissingOrWrongTokenIsRejectedWhenAMetricsTokenIsConfigured(): void
    {
        $this->startServer(['METRICS_TOKEN' => 'super-secret-scrape-token']);

        $noAuth = $this->get('/metrics.php');
        $this->assertSame(401, $noAuth['code']);
        $this->assertSame('', trim($noAuth['body']), 'a 401 must never leak metric data or a hint about the expected token');

        $wrongAuth = $this->get('/metrics.php', ['Authorization: Bearer nope']);
        $this->assertSame(401, $wrongAuth['code']);
    }

    public function testCorrectBearerTokenIsAccepted(): void
    {
        $this->startServer(['METRICS_TOKEN' => 'super-secret-scrape-token']);
        $resp = $this->get('/metrics.php', ['Authorization: Bearer super-secret-scrape-token']);
        $this->assertSame(200, $resp['code']);
        $this->assertStringContainsString('ellsms_db_up 1', $resp['body']);
    }

    public function testExportReflectsRealSeededBulkQueueDepthByMessageClass(): void
    {
        $db = \db();
        $userId = null;
        $organizationId = null;
        $jobId = null;

        try {
            $db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['metrics_' . bin2hex(random_bytes(4))]);
            $userId = (int)$db->lastInsertId();
            // Defensive: a prior run of this exact test that crashed between its own inserts and
            // its finally-block cleanup (below) can leave a stale ellsms_meta row sharing this same
            // auto-increment id, if the environment's AUTO_INCREMENT counter was ever reset (e.g.
            // after the table was fully emptied) -- never assume a fresh id is actually unused.
            $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
            $db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
            $org = \create_organization($userId, 'Metrics Test Org');
            $organizationId = (int)$org['organization_id'];
            $originator = sprintf('9%03d', $userId % 1000); // derived from a fresh id, never a fixed literal -- avoids colliding with a leftover row from a prior interrupted run
            $db->prepare('INSERT INTO ellsms_numbers (number, organization_id) VALUES (?, ?)')->execute([$originator, $organizationId]);
            \wallet_credit($userId, 100, 'purchase', 'metrics_test', "seed:{$userId}", "metrics:credit:{$userId}");

            $user = ['id' => $userId, 'role' => 'user', 'organization_id' => $organizationId];
            $rows = array_map(static fn(int $i) => ['mobile' => sprintf('0912%07d', $i), 'content' => 'metrics test'], range(1, 7));
            [$ok, , $jobId] = \bulk_queue_job($user, 'p2p', 'Metrics test job', $originator, null, $rows);
            $this->assertTrue($ok);
            // The depth query (matching bulk_claim_unthrottled_items_by_class()'s own eligibility
            // predicate) only counts items belonging to a 'processing' job -- a freshly queued job
            // starts 'pending' until a worker pass picks it up, exactly like a real tick would.
            $db->prepare("UPDATE ellsms_bulk_jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);

            $output = \PrometheusExporter::render($db);
            $this->assertMatchesRegularExpression('/ellsms_queue_bulk_depth\{message_class="bulk_campaign"\} 7\b/', $output);
        } finally {
            if ($jobId !== null) {
                $db->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
                $db->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
            }
            if ($userId !== null) {
                $db->prepare('DELETE FROM ellsms_wallet_reservations WHERE user_id = ?')->execute([$userId]);
                $db->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$userId]);
                $db->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$userId]);
            }
            if ($organizationId !== null) {
                $db->prepare('DELETE FROM ellsms_numbers WHERE organization_id = ?')->execute([$organizationId]);
                $db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$organizationId]);
                $db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$organizationId]);
            }
            if ($userId !== null) {
                $db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$userId]);
                $db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$userId]);
            }
        }
    }
}
