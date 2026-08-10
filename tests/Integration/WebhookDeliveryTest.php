<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Phase 12 (STEP 33/34/35) — cron/webhook-worker.php's real delivery loop against a REAL running
 * receiver (tests/fixtures/fake_webhook_receiver.php), each pass run as a genuine subprocess (`php
 * cron/webhook-worker.php --once`), never mocked. Uses WEBHOOK_ALLOW_PRIVATE_TARGETS=1 (test-only
 * SSRF bypass, see app/Webhooks.php's webhook_local_targets_allowed() docblock) since every locally
 * reachable receiver is, by definition, inside the range real webhook URLs must never be allowed to
 * target — the SSRF block itself is proven separately in tests/Unit/WebhookSsrfValidationTest.php.
 *
 * Deliberately does NOT extend IntegrationTestCase — the worker subprocess needs to see committed
 * rows over its own fresh connection, the same reasoning as WalletConcurrencyTest/
 * IdempotencyConcurrencyTest.
 */
final class WebhookDeliveryTest extends TestCase
{
    private $receiverProc = null;
    private int $receiverPort;
    private string $captureFile;
    private \PDO $db;
    private array $org;
    private int $endpointId;
    private string $endpointSecret;

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();
        $this->db = db();

        $this->captureFile = sys_get_temp_dir() . '/ellsms_webhook_capture_' . bin2hex(random_bytes(6)) . '.ndjson';
        $this->receiverPort = 19900 + random_int(0, 300);
        $this->receiverProc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->receiverPort}", dirname(__DIR__) . '/fixtures/fake_webhook_receiver.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['WEBHOOK_CAPTURE_FILE' => $this->captureFile]
        );
        $this->assertNotFalse($this->receiverProc, 'could not start fake webhook receiver');
        $booted = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->receiverPort, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $this->assertTrue($booted, 'fake webhook receiver never accepted connections');

        putenv('WEBHOOK_MASTER_KEY=' . base64_encode(random_bytes(32)));
        // webhook_endpoint_create() below runs in THIS process (not the worker subprocess), so it
        // needs the same test-only SSRF bypass too -- see app/Webhooks.php's
        // webhook_local_targets_allowed() docblock for why this is required at all here.
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        putenv('WEBHOOK_REQUIRE_HTTPS=0');

        $this->db->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute(['webhook_delivery_' . bin2hex(random_bytes(4))]);
        $userId = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, '']);
        $org = create_organization($userId, 'Webhook Delivery Test Org');
        $this->org = ['organization_id' => (int)$org['organization_id'], 'owner_id' => $userId];
    }

    protected function tearDown(): void
    {
        if ($this->receiverProc !== null) {
            proc_terminate($this->receiverProc);
            proc_close($this->receiverProc);
        }
        @unlink($this->captureFile);
        putenv('WEBHOOK_MASTER_KEY');
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        putenv('WEBHOOK_REQUIRE_HTTPS');

        $orgId = $this->org['organization_id'];
        $this->db->prepare('DELETE d FROM ellsms_webhook_deliveries d JOIN ellsms_webhook_events e ON e.id = d.event_id WHERE e.organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_webhook_events WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_webhook_endpoints WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_wallet_accounts WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_organization_memberships WHERE organization_id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$orgId]);
        $this->db->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$this->org['owner_id']]);
        $this->db->prepare('DELETE FROM user_ WHERE id = ?')->execute([$this->org['owner_id']]);
    }

    private function makeEndpoint(string $path): void {
        $result = webhook_endpoint_create($this->org['organization_id'], $this->org['owner_id'], "http://127.0.0.1:{$this->receiverPort}{$path}", 'test', [\WebhookEvents::MESSAGE_SENT]);
        $this->assertTrue($result['ok'], 'webhook_endpoint_create failed: ' . ($result['reason'] ?? ''));
        $this->endpointId = $result['id'];
        $this->endpointSecret = $result['secret'];
    }

    private function emitEvent(): string {
        $eventUuid = webhook_event_emit($this->org['organization_id'], \WebhookEvents::MESSAGE_SENT, 'test_resource', '1', ['hello' => 'world']);
        $this->assertNotNull($eventUuid);
        return $eventUuid;
    }

    /** Runs `php cron/webhook-worker.php --once` as a real subprocess with the given extra env overrides layered on top of the current DB connection info. */
    private function runWorkerOnce(array $envOverrides = []): void {
        $env = array_merge([
            'APP_ENV' => 'testing',
            'BACKEND_DB_HOST' => (string)getenv('BACKEND_DB_HOST'),
            'BACKEND_DB_PORT' => (string)getenv('BACKEND_DB_PORT'),
            'BACKEND_DB_NAME' => (string)getenv('BACKEND_DB_NAME'),
            'BACKEND_DB_USER' => (string)getenv('BACKEND_DB_USER'),
            'BACKEND_DB_PASS' => (string)getenv('BACKEND_DB_PASS'),
            'WEBHOOK_MASTER_KEY' => (string)getenv('WEBHOOK_MASTER_KEY'),
            'WEBHOOK_ALLOW_PRIVATE_TARGETS' => '1',
            'WEBHOOK_REQUIRE_HTTPS' => '0',
            'WEBHOOK_TIMEOUT_SECONDS' => '3',
            'WEBHOOK_MAX_ATTEMPTS' => '8',
            'WEBHOOK_MAX_RESPONSE_BYTES' => '4096',
            'JOB_RETRY_BASE_SECONDS' => '30',
            'JOB_RETRY_MAX_SECONDS' => '1800',
        ], $envOverrides);
        $script = dirname(__DIR__, 2) . '/cron/webhook-worker.php';
        $proc = proc_open([PHP_BINARY, $script, '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to spawn webhook-worker subprocess');
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, "webhook-worker.php --once exited non-zero. stderr: {$stderr}");
    }

    private function fetchDelivery(): array {
        $row = $this->db->query(
            "SELECT d.* FROM ellsms_webhook_deliveries d JOIN ellsms_webhook_endpoints ep ON ep.id = d.endpoint_id
             WHERE ep.organization_id = " . (int)$this->org['organization_id'] . " ORDER BY d.id DESC LIMIT 1"
        )->fetch();
        $this->assertNotFalse($row, 'expected a delivery row to exist');
        return $row;
    }

    private function readCaptured(): array {
        if (!is_file($this->captureFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", trim((string)file_get_contents($this->captureFile))));
        return array_map(static fn($l) => json_decode($l, true), $lines);
    }

    public function testSuccessfulDeliveryIsRecordedAndCorrectlySigned(): void
    {
        $this->makeEndpoint('/capture');
        $this->emitEvent();
        $this->runWorkerOnce();

        $delivery = $this->fetchDelivery();
        $this->assertSame('delivered', $delivery['status']);
        $this->assertSame(200, (int)$delivery['http_status']);
        $this->assertSame(1, (int)$delivery['attempt_count']);

        $captured = $this->readCaptured();
        $this->assertCount(1, $captured, 'the receiver must have been hit exactly once');
        $entry = $captured[0];
        $this->assertArrayHasKey('event-id', $entry['headers']);
        $this->assertArrayHasKey('timestamp', $entry['headers']);
        $this->assertArrayHasKey('signature', $entry['headers']);

        $verified = webhook_signature_verify($this->endpointSecret, $entry['headers']['timestamp'], $entry['body'], $entry['headers']['signature']);
        $this->assertTrue($verified, 'the delivered signature must verify against the secret returned at endpoint creation time');

        $payload = json_decode($entry['body'], true);
        $this->assertSame('message.sent', $payload['event_type']);
        $this->assertSame('world', $payload['data']['hello']);
    }

    public function testRetryableFailureIsRescheduledNotDeadLetteredOnFirstAttempt(): void
    {
        $this->makeEndpoint('/status/500');
        $this->emitEvent();
        $this->runWorkerOnce();

        $delivery = $this->fetchDelivery();
        $this->assertSame('pending', $delivery['status'], 'a 500 with attempts remaining must be rescheduled, not dead-lettered');
        $this->assertSame('http_500', $delivery['error_code']);
        $this->assertNotNull($delivery['next_attempt_at']);

        $endpoint = $this->db->query('SELECT consecutive_failures FROM ellsms_webhook_endpoints WHERE id = ' . $this->endpointId)->fetch();
        $this->assertSame(0, (int)$endpoint['consecutive_failures'], 'a non-terminal retry must not count toward auto-disable');
    }

    public function testPermanentFailureIsMarkedFailedOnFirstAttempt(): void
    {
        $this->makeEndpoint('/status/404');
        $this->emitEvent();
        $this->runWorkerOnce();

        $delivery = $this->fetchDelivery();
        $this->assertSame('failed', $delivery['status'], 'a 404 is permanent -- no retry should be scheduled');
        $this->assertSame('http_404', $delivery['error_code']);
        $this->assertNull($delivery['next_attempt_at']);

        $endpoint = $this->db->query('SELECT consecutive_failures FROM ellsms_webhook_endpoints WHERE id = ' . $this->endpointId)->fetch();
        $this->assertSame(1, (int)$endpoint['consecutive_failures'], 'a permanent failure IS terminal and must count toward auto-disable');
    }

    public function testRetriesExhaustedResultInDeadLetter(): void
    {
        $this->makeEndpoint('/status/503');
        $this->emitEvent();
        // WEBHOOK_MAX_ATTEMPTS=1 -- the one and only attempt this pass makes is already "exhausted."
        $this->runWorkerOnce(['WEBHOOK_MAX_ATTEMPTS' => '1']);

        $delivery = $this->fetchDelivery();
        $this->assertSame('dead_letter', $delivery['status']);
        $this->assertSame(1, (int)$delivery['attempt_count']);
    }

    public function testResponseExcerptIsTruncated(): void
    {
        $this->makeEndpoint('/large-response');
        $this->emitEvent();
        // webhook_config_max_response_bytes() floors at 256 regardless of what's configured lower.
        $this->runWorkerOnce(['WEBHOOK_MAX_RESPONSE_BYTES' => '300']);

        $delivery = $this->fetchDelivery();
        $this->assertSame('delivered', $delivery['status']);
        $this->assertLessThanOrEqual(310, strlen((string)$delivery['response_excerpt']), 'the stored response excerpt must be bounded, never the full 50000-byte body');
    }

    public function testEventIdentityStaysStableAcrossARetry(): void
    {
        $this->makeEndpoint('/status/500');
        $eventUuid = $this->emitEvent();
        $this->runWorkerOnce();
        $firstDelivery = $this->fetchDelivery();

        // Force the retry to be immediately claimable instead of waiting out the real backoff.
        $this->db->exec("UPDATE ellsms_webhook_deliveries SET next_attempt_at = NOW() WHERE id = {$firstDelivery['id']}");
        $this->runWorkerOnce();
        $secondDelivery = $this->fetchDelivery();

        $this->assertSame($firstDelivery['id'], $secondDelivery['id'], 'a retry reuses the SAME delivery row, never a new one');
        $this->assertSame(2, (int)$secondDelivery['attempt_count']);

        $eventCount = $this->db->query("SELECT COUNT(*) c FROM ellsms_webhook_events WHERE event_uuid = " . $this->db->quote($eventUuid))->fetch()['c'];
        $this->assertSame('1', (string)$eventCount, 'retrying a delivery must never mint a second logical event');
    }

    public function testAbandonedLeaseIsReclaimedAfterExpiry(): void
    {
        $this->makeEndpoint('/capture');
        $this->emitEvent();
        $delivery = $this->fetchDelivery();

        // Simulate a worker that claimed this row and then crashed mid-delivery -- lease already expired.
        $this->db->exec(
            "UPDATE ellsms_webhook_deliveries SET status='processing', claimed_by='dead-worker:1', claimed_at=DATE_SUB(NOW(), INTERVAL 10 MINUTE), lease_expires_at=DATE_SUB(NOW(), INTERVAL 5 MINUTE), attempt_count=1 WHERE id={$delivery['id']}"
        );

        $this->runWorkerOnce();

        $reclaimed = $this->fetchDelivery();
        $this->assertSame('delivered', $reclaimed['status'], 'an abandoned lease must be reclaimed and completed by the next pass');
        $this->assertSame(2, (int)$reclaimed['attempt_count'], 'reclaiming counts as a new attempt');
    }
}
