<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Per-line MO / DLR forwarding end to end: the real enqueue hooks (run by hand here, as
 * run_autoreply_pass() and gateway_status_record() run them), the real worker as a subprocess
 * (`php cron/number-forward-worker.php --once`) and a real HTTP receiver
 * (tests/fixtures/fake_forward_receiver.php).
 *
 * Uses WEBHOOK_ALLOW_PRIVATE_TARGETS=1, the test-only SSRF bypass (app/Webhooks.php), because the
 * receiver is on loopback; the address check itself is covered by tests/Unit/NumberForwardingTemplateTest.php.
 * Does NOT extend IntegrationTestCase: the worker subprocess must see committed rows.
 */
final class NumberForwardingTest extends TestCase
{
    private $receiverProc = null;
    private int $receiverPort;
    private string $captureFile;
    private string $line;
    private array $attemptIds = [];

    protected function setUp(): void
    {
        IntegrationTestCase::skipUnlessTestDatabaseConfigured($this);
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is not available in this PHP build.');
        }
        IntegrationTestCase::ensureSchemaLoaded();

        $this->captureFile = sys_get_temp_dir() . '/ellsms_forward_capture_' . bin2hex(random_bytes(6)) . '.ndjson';
        $this->receiverPort = 20700 + random_int(0, 250);
        $this->receiverProc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->receiverPort}", dirname(__DIR__) . '/fixtures/fake_forward_receiver.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['FORWARD_CAPTURE_FILE' => $this->captureFile, 'PHP_CLI_SERVER_WORKERS' => '4']
        );
        $booted = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(150000);
            $conn = @fsockopen('127.0.0.1', $this->receiverPort, $errno, $errstr, 0.2);
            if ($conn) { fclose($conn); $booted = true; break; }
        }
        $this->assertTrue($booted, 'fake forward receiver never accepted connections');

        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS=1');
        $this->line = '50007' . random_int(100000, 999999);
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'number\\_forward\\_%'");
        \number_forward_cache_reset();
    }

    protected function tearDown(): void
    {
        if ($this->receiverProc !== null) {
            proc_terminate($this->receiverProc);
            proc_close($this->receiverProc);
        }
        @unlink($this->captureFile);
        putenv('WEBHOOK_ALLOW_PRIVATE_TARGETS');
        db()->prepare('DELETE FROM ellsms_number_forward_queue WHERE number = ?')->execute([$this->line]);
        db()->prepare('DELETE FROM ellsms_number_forwards WHERE number = ?')->execute([$this->line]);
        foreach ($this->attemptIds as $id) {
            db()->prepare('DELETE FROM ellsms_message_attempts WHERE id = ?')->execute([$id]);
        }
        db()->exec("DELETE FROM ellsms_settings WHERE skey LIKE 'number\\_forward\\_%'");
        \number_forward_cache_reset();
    }

    private function url(string $path): string
    {
        return "http://127.0.0.1:{$this->receiverPort}{$path}";
    }

    private function configure(array $input): void
    {
        $result = \number_forward_save($this->line, $input, 0);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
    }

    private function inbound(int $id, string $content = 'سلام & hello'): array
    {
        return ['id' => $id, 'originator' => '989121234567', 'destination' => $this->line, 'content' => $content, 'received_at' => '2026-10-14 10:00:00'];
    }

    private function runWorkerOnce(): string
    {
        $env = [
            'APP_ENV' => 'testing',
            'WEBHOOK_ALLOW_PRIVATE_TARGETS' => '1',
            'JOB_RETRY_BASE_SECONDS' => '30',
            'JOB_RETRY_MAX_SECONDS' => '1800',
        ];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/cron/number-forward-worker.php', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), "number-forward-worker.php --once failed: {$err}");
        return (string)$out . (string)$err;
    }

    private function captured(): array
    {
        if (!is_file($this->captureFile)) return [];
        return array_map(static fn(string $l): array => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($this->captureFile))));
    }

    private function queueRows(): array
    {
        $st = db()->prepare('SELECT * FROM ellsms_number_forward_queue WHERE number = ? ORDER BY id');
        $st->execute([$this->line]);
        return $st->fetchAll();
    }

    public function testAnInboundMessageIsForwardedOnceWithVesalPlaceholders(): void
    {
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/mo?from=${originator}&to=${destination}&text=${content}&id=${id}')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000777));
        \number_forward_enqueue_mo($this->inbound(1000000000000777)); // the inbound scan is at-least-once
        $this->assertCount(1, $this->queueRows(), 'the same inbound message is queued exactly once');

        $this->runWorkerOnce();

        $calls = $this->captured();
        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('/mo', $calls[0]['path']);
        parse_str($calls[0]['query'], $q);
        $this->assertSame(['from' => '989121234567', 'to' => $this->line, 'text' => 'سلام & hello', 'id' => '1000000000000777'], $q);
        $this->assertSame('mo', $calls[0]['headers']['forward-kind']);

        $row = $this->queueRows()[0];
        $this->assertSame('delivered', $row['status']);
        $this->assertSame(200, (int)$row['http_status']);
        $this->assertSame(1, (int)$row['attempt_count']);
        $this->assertSame((string)$row['id'], $calls[0]['headers']['forward-id']);
        $this->assertNotNull(\number_forward_find($this->line)['last_success_at']);
    }

    public function testTheInboundScanQueuesTheForward(): void
    {
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/mo?text=${content}')]);
        $cursor = setting_fresh('autoreply_last_inbound_id', '0');
        db()->prepare("INSERT INTO inbound_message (originator, destination, content) VALUES ('989121234567', ?, 'from the scan')")->execute([$this->line]);
        $inboundId = (int)db()->lastInsertId();
        try {
            set_setting('autoreply_last_inbound_id', (string)($inboundId - 1));
            \run_autoreply_pass();
            $rows = $this->queueRows();
            $this->assertCount(1, $rows);
            $this->assertSame('inbound:' . $inboundId, $rows[0]['source_key']);
            $this->assertSame('from the scan', json_decode($rows[0]['vars_json'], true)['content']);
        } finally {
            set_setting('autoreply_last_inbound_id', (string)max((int)$cursor, $inboundId));
            db()->prepare('DELETE FROM ellsms_inbound_webhook_claims WHERE inbound_message_id = ?')->execute([$inboundId]);
            db()->prepare('DELETE FROM inbound_message WHERE id = ?')->execute([$inboundId]);
        }
    }

    public function testAFinalDeliveryReportIsForwardedFromTheStatusWrite(): void
    {
        $this->configure(['dlr_enabled' => '1', 'dlr_url' => $this->url('/dlr?id=${id}&status=${status}&state=${state}&dl_time=${dl_time}'),
                          'http_method' => 'POST', 'post_format' => 'json']);
        db()->prepare("INSERT INTO ellsms_message_attempts (user_id, reference_type, reference_id, status, error_code, originator, destination, delivery_status)
                       VALUES (1, 'direct_send', 'fwd-test', 'accepted', '', ?, '989121234567', 'sent')")->execute([$this->line]);
        $attemptId = (int)db()->lastInsertId();
        $this->attemptIds[] = $attemptId;

        $this->assertTrue(\gateway_status_record('attempt', $attemptId, 'sent', 'delivered', '2026-10-14 10:00:05', '2'));
        $this->assertFalse(\gateway_status_record('attempt', $attemptId, 'delivered', 'delivered', '2026-10-14 10:00:05', '2'));
        $this->assertCount(1, $this->queueRows(), 'only the transition into the final state forwards');

        $this->runWorkerOnce();

        $calls = $this->captured();
        $this->assertCount(1, $calls);
        $this->assertSame('POST', $calls[0]['method']);
        parse_str($calls[0]['query'], $q);
        $this->assertSame((string)$attemptId, $q['id']);
        $this->assertSame('2', $q['status'], 'Vesal DELIVERED');
        $this->assertSame('delivered', $q['state']);
        $this->assertSame((string)(strtotime('2026-10-14 10:00:05') * 1000), $q['dl_time']);
        $body = json_decode($calls[0]['body'], true);
        $this->assertSame('attempt:' . $attemptId, $body['message_id']);
        $this->assertSame('fwd-test', $body['reference_id']);
        $this->assertSame('989121234567', $body['destination']);
        $this->assertSame('delivered', $this->queueRows()[0]['status']);
    }

    public function testABulkItemsDeliveryReportCarriesItsJob(): void
    {
        $this->configure(['dlr_enabled' => '1', 'dlr_url' => $this->url('/dlr?id=${id}&status=${status}&job=${reference_id}&to=${destination}')]);
        db()->prepare("INSERT INTO ellsms_bulk_jobs (user_id, type, title, originator, status, total_rows) VALUES (1, 'p2p', 'fwd', ?, 'processing', 1)")->execute([$this->line]);
        $jobId = (int)db()->lastInsertId();
        db()->prepare("INSERT INTO ellsms_bulk_items (job_id, mobile, content, status, delivery_status) VALUES (?, '989121234567', 'hi', 'sent', 'sent')")->execute([$jobId]);
        $itemId = (int)db()->lastInsertId();
        try {
            $this->assertTrue(\gateway_status_record('bulk', $itemId, 'sent', 'rejected', null, 'REJECTD'));
            $this->runWorkerOnce();
            parse_str($this->captured()[0]['query'], $q);
            $this->assertSame(['id' => (string)$itemId, 'status' => '8', 'job' => (string)$jobId, 'to' => '989121234567'], $q, 'Vesal REJECTED = 8');
            $this->assertSame('bulk_item:' . $itemId, $this->queueRows()[0]['source_key']);
        } finally {
            db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
            db()->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
        }
    }

    public function testRetryableAndPermanentFailures(): void
    {
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/status/503')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000801));
        $this->runWorkerOnce();
        $row = $this->queueRows()[0];
        $this->assertSame('pending', $row['status'], '503 is retried');
        $this->assertSame('http_503', $row['error_code']);
        $this->assertNotNull($row['next_attempt_at']);
        $this->assertGreaterThan(time() + 5, strtotime((string)$row['next_attempt_at']), 'with backoff');

        // The customer fixes the URL — the waiting retry uses the new one.
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/fixed?t=${content}')]);
        db()->prepare('UPDATE ellsms_number_forward_queue SET next_attempt_at = NOW() WHERE number = ?')->execute([$this->line]);
        $this->runWorkerOnce();
        $row = $this->queueRows()[0];
        $this->assertSame('delivered', $row['status']);
        $this->assertSame(2, (int)$row['attempt_count']);
        $this->assertSame('/fixed', $this->captured()[1]['path']);

        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/status/404')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000802));
        $this->runWorkerOnce();
        $failed = $this->queueRows()[1];
        $this->assertSame('failed', $failed['status'], '404 is permanent');
        $this->assertSame(1, (int)\number_forward_find($this->line)['consecutive_failures']);

        $this->assertSame(1, \number_forward_requeue((int)$failed['id'], null, ['999']) + \number_forward_requeue((int)$failed['id'], null, [$this->line]),
            'a customer can only re-queue their own line');
        $this->assertSame('pending', $this->queueRows()[1]['status']);
    }

    public function testRetriesStopAtTheMaximum(): void
    {
        set_setting('number_forward_max_attempts', '2');
        \number_forward_cache_reset();
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/status/500')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000811));
        $this->runWorkerOnce();
        db()->prepare('UPDATE ellsms_number_forward_queue SET next_attempt_at = NOW() WHERE number = ?')->execute([$this->line]);
        $this->runWorkerOnce();
        $row = $this->queueRows()[0];
        $this->assertSame('dead', $row['status']);
        $this->assertSame(2, (int)$row['attempt_count']);
    }

    public function testALinesOwnAttemptCountWins(): void
    {
        set_setting('number_forward_max_attempts', '5');
        \number_forward_cache_reset();
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/status/502'), 'max_attempts' => '1']);
        \number_forward_enqueue_mo($this->inbound(1000000000000851));
        $this->runWorkerOnce();
        $this->assertSame('dead', $this->queueRows()[0]['status'], 'one attempt for this line, not the default five');
    }

    public function testASlowUrlDoesNotHoldUpTheCallsBehindIt(): void
    {
        set_setting('number_forward_concurrency', '2');
        \number_forward_cache_reset();
        // Two slow (1.5 s) and four fast forwards through two slots. Sent in waves of two this takes
        // ~3 s (each slow one stalls its wave); with the sliding window the fast ones flow through
        // the free slot while the first slow one runs, and the whole pass takes ~1.6 s.
        // Each slow URL gets its own receiver: PHP's built-in server can accept two simultaneous
        // connections into one worker and serve them one after the other, which would measure the
        // test server instead of the worker.
        $slowServers = [];
        $slowUrls = [];
        foreach ([0, 1] as $n) {
            $port = $this->receiverPort + 300 + $n;
            $slowServers[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__) . '/fixtures/fake_forward_receiver.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $slowUrls[] = "http://127.0.0.1:{$port}/sleep/1500";
        }
        $urls = [$slowUrls[0], $this->url('/fast'), $this->url('/fast'), $this->url('/fast'), $slowUrls[1], $this->url('/fast')];
        try {
            foreach ([0, 1] as $n) {
                for ($i = 0; $i < 30; $i++) {
                    usleep(100000);
                    $conn = @fsockopen('127.0.0.1', $this->receiverPort + 300 + $n, $errno, $errstr, 0.2);
                    if ($conn) { fclose($conn); break; }
                }
            }
            foreach ($urls as $i => $url) {
                db()->prepare('INSERT INTO ellsms_number_forwards (number, mo_enabled, mo_url) VALUES (?,1,?)
                               ON DUPLICATE KEY UPDATE mo_url = VALUES(mo_url)')->execute([$this->line . $i, $url]);
                \number_forward_cache_reset();
                \number_forward_enqueue_mo(['id' => 1000000000000860 + $i, 'originator' => '98912', 'destination' => $this->line . $i, 'content' => 'x']);
            }
            $startedAt = microtime(true);
            $this->runWorkerOnce();
            $elapsed = microtime(true) - $startedAt;
            $st = db()->prepare("SELECT COUNT(*) FROM ellsms_number_forward_queue WHERE number LIKE ? AND status = 'delivered'");
            $st->execute([$this->line . '_']);
            $this->assertSame(6, (int)$st->fetchColumn());
            $this->assertLessThan(2.6, $elapsed, 'the slow calls overlapped instead of each stalling a wave');
        } finally {
            foreach ($slowServers as $proc) { proc_terminate($proc); proc_close($proc); }
            db()->prepare('DELETE FROM ellsms_number_forward_queue WHERE number LIKE ?')->execute([$this->line . '_']);
            db()->prepare('DELETE FROM ellsms_number_forwards WHERE number LIKE ?')->execute([$this->line . '_']);
        }
    }

    public function testTheSupervisorRunsTheConfiguredNumberOfWorkers(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are not available.');
        }
        set_setting('number_forward_workers', '3');
        $env = ['APP_ENV' => 'testing'];
        foreach (['BACKEND_DB_HOST', 'BACKEND_DB_PORT', 'BACKEND_DB_NAME', 'BACKEND_DB_USER', 'BACKEND_DB_PASS'] as $k) {
            $env[$k] = (string)getenv($k);
        }
        $proc = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/cron/number-forward-worker.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        $pid = proc_get_status($proc)['pid'];
        $children = static fn(): int => count(array_filter(explode("\n", trim((string)shell_exec('pgrep -P ' . (int)$pid)))));
        $waitFor = static function (callable $check): bool {
            for ($i = 0; $i < 80; $i++) { if ($check()) return true; usleep(250000); }
            return false;
        };
        try {
            $this->assertTrue($waitFor(fn() => $children() === 3), 'three workers started');
            set_setting('number_forward_workers', '1');
            $this->assertTrue($waitFor(fn() => $children() === 1), 'lowered to one without a restart');
            posix_kill($pid, SIGTERM);
            $this->assertTrue($waitFor(fn() => !proc_get_status($proc)['running']), 'stops on SIGTERM');
            $this->assertSame(0, $children());
        } finally {
            if (proc_get_status($proc)['running']) { proc_terminate($proc, 9); }
            proc_close($proc);
        }
    }

    public function testNothingIsQueuedOrSentWhenSwitchedOff(): void
    {
        $this->configure(['mo_enabled' => '0', 'mo_url' => $this->url('/mo')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000821));
        $this->assertSame([], $this->queueRows(), 'the line forwards nothing while its switch is off');

        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/mo')]);
        set_setting('number_forward_enabled', '0');
        \number_forward_cache_reset();
        \number_forward_enqueue_mo($this->inbound(1000000000000822));
        $this->assertSame([], $this->queueRows(), 'the master switch stops the enqueue');

        set_setting('number_forward_enabled', '1');
        \number_forward_cache_reset();
        \number_forward_enqueue_mo($this->inbound(1000000000000823));
        // Switched off after it was queued: the worker cancels it instead of calling.
        $this->configure(['mo_enabled' => '0', 'mo_url' => $this->url('/mo')]);
        $this->runWorkerOnce();
        $this->assertSame('cancelled', $this->queueRows()[0]['status']);
        $this->assertSame([], $this->captured());
    }

    public function testSaveRefusesUnknownPlaceholdersAndEmptyUrls(): void
    {
        $bad = \number_forward_save($this->line, ['mo_enabled' => '1', 'mo_url' => $this->url('/x?s=${status}')], 0);
        $this->assertFalse($bad['ok']);
        $empty = \number_forward_save($this->line, ['dlr_enabled' => '1', 'dlr_url' => ''], 0);
        $this->assertFalse($empty['ok']);
        $this->assertNull(\number_forward_find($this->line));
    }

    public function testTheTestButtonCallsTheSavedUrl(): void
    {
        $this->configure(['mo_enabled' => '0', 'mo_url' => $this->url('/probe?t=${content}')]);
        $result = \number_forward_send_test($this->line, 'mo');
        $this->assertTrue($result['ok']);
        $this->assertSame('/probe', $this->captured()[0]['path']);
        $this->assertSame([], $this->queueRows(), 'a test is not logged as a forwarded message');
    }

    public function testASelfSignedCertificateIsAcceptedUnlessTheLineVerifies(): void
    {
        if (!is_executable('/usr/bin/openssl') && trim((string)shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('openssl is not installed.');
        }
        $dir = sys_get_temp_dir() . '/ellsms_fwd_tls_' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=not-this-host '
            . '-keyout ' . escapeshellarg("$dir/key.pem") . ' -out ' . escapeshellarg("$dir/cert.pem") . ' 2>/dev/null', $_, $rc);
        $this->assertSame(0, $rc, 'could not create a self-signed certificate');
        $port = 21300 + random_int(0, 200);
        $server = proc_open(['openssl', 's_server', '-quiet', '-accept', (string)$port, '-cert', "$dir/cert.pem", '-key', "$dir/key.pem", '-www'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            for ($i = 0; $i < 30; $i++) {
                usleep(150000);
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($conn) { fclose($conn); break; }
            }
            $this->configure(['mo_enabled' => '1', 'mo_url' => "https://127.0.0.1:{$port}/mo?t=\${content}"]);
            \number_forward_enqueue_mo($this->inbound(1000000000000841));
            $this->runWorkerOnce();
            $this->assertSame('delivered', $this->queueRows()[0]['status'], 'self-signed, wrong-host certificate: still delivered by default');

            $this->configure(['mo_enabled' => '1', 'mo_url' => "https://127.0.0.1:{$port}/mo?t=\${content}", 'tls_verify' => '1']);
            \number_forward_enqueue_mo($this->inbound(1000000000000842));
            $this->runWorkerOnce();
            $row = $this->queueRows()[1];
            $this->assertSame('pending', $row['status']);
            $this->assertSame('tls_error', $row['error_code'], 'with verification on, the bad certificate is refused');
        } finally {
            proc_terminate($server);
            proc_close($server);
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }

    public function testPruneDeletesOnlyOldFinishedRows(): void
    {
        $this->configure(['mo_enabled' => '1', 'mo_url' => $this->url('/mo')]);
        \number_forward_enqueue_mo($this->inbound(1000000000000831));
        \number_forward_enqueue_mo($this->inbound(1000000000000832));
        $rows = $this->queueRows();
        db()->prepare("UPDATE ellsms_number_forward_queue SET status = 'delivered', completed_at = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = ?")->execute([$rows[0]['id']]);
        \number_forward_prune(30);
        $left = $this->queueRows();
        $this->assertCount(1, $left);
        $this->assertSame($rows[1]['id'], $left[0]['id']);
    }
}
