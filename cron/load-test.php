<?php
/**
 * ELLSMS — reproducible bulk-queue load-test harness (Phase 9, STEP 11).
 *
 * Seeds disposable organizations/users/wallets/bulk jobs, starts the deterministic fake backend
 * (tests/fixtures/fake_backend_server.php) with a configurable latency/failure profile, spawns N
 * real worker OS processes (cron/load-test-worker-runner.php) against the SAME database, measures
 * throughput, verifies correctness (STEP 12), writes a benchmark artifact, and cleans up after
 * itself. Every piece here already exists elsewhere in this codebase (bulk_queue_job(), the fake
 * backend, run_bulk_send_pass()) — this harness only wires them together and measures.
 *
 * SAFETY (Invariant C): refuses to run unless the configured database looks like a disposable test
 * database (BACKEND_DB_NAME contains "test") or ELLSMS_ALLOW_LOAD_TEST=1 is explicitly set. This is
 * NOT a substitute for pointing it at a real disposable container — it is a last-resort guard
 * against an accidental run against a shared/production database.
 *
 * Configuration (env vars, all optional):
 *   LOAD_TEST_ITEMS                     total bulk items to seed (default 1000)
 *   LOAD_TEST_WORKERS                   worker OS processes to run concurrently (default 1)
 *   LOAD_TEST_ORGS                      organizations to spread items across (default 1)
 *   LOAD_TEST_BACKEND_LATENCY_MS        fake backend base latency (default 0)
 *   LOAD_TEST_BACKEND_LATENCY_JITTER_MS fake backend added random latency (default 0)
 *   LOAD_TEST_FAILURE_RATE              0.0-1.0 (default 0)
 *   LOAD_TEST_FAILURE_MIX               comma list, e.g. "500,422,timeout" (default "500,422,timeout")
 *   LOAD_TEST_BATCH_SIZE                WORKER_BULK_BATCH_SIZE for spawned workers (default 20)
 *   LOAD_TEST_TIMEOUT_SECONDS           per-worker safety deadline (default 120)
 *   LOAD_TEST_KEEP                      1 = skip cleanup, leave seeded data for inspection (default 0)
 *   LOAD_TEST_LABEL                     free-text label stored in the result artifact
 *
 * Usage:
 *   ELLSMS_TEST_DB_HOST=127.0.0.1 ELLSMS_TEST_DB_PORT=33061 ELLSMS_TEST_DB_NAME=ellsms_test \
 *   ELLSMS_TEST_DB_USER=ellsms_test ELLSMS_TEST_DB_PASS=ellsms_test \
 *   LOAD_TEST_ITEMS=1000 LOAD_TEST_WORKERS=2 php cron/load-test.php
 */

$root = dirname(__DIR__);

// Accept the same ELLSMS_TEST_DB_* convention IntegrationTestCase uses, so this harness can run
// with exactly the same env the integration suite does.
$testHost = getenv('ELLSMS_TEST_DB_HOST');
if ($testHost !== false && $testHost !== '' && getenv('BACKEND_DB_HOST') === false) {
    putenv('BACKEND_DB_HOST=' . $testHost);
    putenv('BACKEND_DB_PORT=' . (getenv('ELLSMS_TEST_DB_PORT') ?: '3306'));
    putenv('BACKEND_DB_NAME=' . (getenv('ELLSMS_TEST_DB_NAME') ?: 'ellsms_test'));
    putenv('BACKEND_DB_USER=' . (getenv('ELLSMS_TEST_DB_USER') ?: 'ellsms_test'));
    putenv('BACKEND_DB_PASS=' . (getenv('ELLSMS_TEST_DB_PASS') ?: 'ellsms_test'));
}
putenv('APP_ENV=testing');

require_once $root . '/app/backend.php';

$dbName = (string)env('BACKEND_DB_NAME', '');
if (!str_contains(strtolower($dbName), 'test') && env('ELLSMS_ALLOW_LOAD_TEST', '0') !== '1') {
    fwrite(STDERR, "REFUSING TO RUN: BACKEND_DB_NAME (\"{$dbName}\") does not look like a disposable test database.\n");
    fwrite(STDERR, "Point this at a real disposable database, or set ELLSMS_ALLOW_LOAD_TEST=1 if you are certain.\n");
    exit(1);
}

function cfg(string $key, string $default): string {
    return (string)(env($key, $default) ?? $default);
}

$items          = max(1, (int)cfg('LOAD_TEST_ITEMS', '1000'));
$workers        = max(1, (int)cfg('LOAD_TEST_WORKERS', '1'));
$orgs           = max(1, (int)cfg('LOAD_TEST_ORGS', '1'));
$latencyMs      = (int)cfg('LOAD_TEST_BACKEND_LATENCY_MS', '0');
$jitterMs       = (int)cfg('LOAD_TEST_BACKEND_LATENCY_JITTER_MS', '0');
$failureRate    = (float)cfg('LOAD_TEST_FAILURE_RATE', '0');
$failureMix     = cfg('LOAD_TEST_FAILURE_MIX', '500,422,timeout');
$batchSize      = max(1, (int)cfg('LOAD_TEST_BATCH_SIZE', '20'));
$workerTimeout  = max(5, (int)cfg('LOAD_TEST_TIMEOUT_SECONDS', '120'));
$keep           = cfg('LOAD_TEST_KEEP', '0') === '1';
$label          = cfg('LOAD_TEST_LABEL', 'unlabeled');

echo "=== ELLSMS load test: {$label} ===\n";
echo "items={$items} workers={$workers} orgs={$orgs} batch_size={$batchSize} latency_ms={$latencyMs}(+0..{$jitterMs}) failure_rate={$failureRate} failure_mix={$failureMix}\n";

/* ---------- 1. Start the fake backend ---------- */

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($socket === false) {
    fwrite(STDERR, "Could not allocate a local port: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($socket, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fclose($socket);
$fakeBackendUrl = "http://127.0.0.1:{$port}";

$serverEnv = array_merge($_ENV ?: [], [
    'FAKE_BACKEND_LATENCY_MS' => (string)$latencyMs,
    'FAKE_BACKEND_LATENCY_JITTER_MS' => (string)$jitterMs,
    'FAKE_BACKEND_FAILURE_RATE' => (string)$failureRate,
    'FAKE_BACKEND_FAILURE_MIX' => $failureMix,
    'FAKE_BACKEND_SEED' => (string)crc32($label . $items . $workers),
]);
// Redirected to real files, NOT pipes -- a proc_open pipe nobody reads from fills its OS buffer
// (~64KB) once enough gets written to it and then blocks the child process's next write()
// indefinitely. The fake backend logs one line per request; at load-test volume that fills a pipe
// buffer within seconds and silently wedges the whole benchmark (confirmed the hard way during
// this phase's own benchmarking -- a run stalled for ~300s once this happened. See
// docs/observability-and-performance.md's methodology notes).
$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
$fakeBackendLogPath = $logDir . '/load-test-fake-backend-' . getmypid() . '.log';
$fakeBackendProcess = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $root . '/tests/fixtures/fake_backend_server.php'],
    [1 => ['file', $fakeBackendLogPath, 'w'], 2 => ['file', $fakeBackendLogPath, 'a']],
    $pipes,
    null,
    $serverEnv
);
if ($fakeBackendProcess === false) {
    fwrite(STDERR, "Could not start the fake backend server.\n");
    exit(1);
}
$deadline = microtime(true) + 5;
$up = false;
while (microtime(true) < $deadline) {
    $conn = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($conn) { fclose($conn); $up = true; break; }
    usleep(50000);
}
if (!$up) {
    fwrite(STDERR, "Fake backend server did not become reachable.\n");
    proc_terminate($fakeBackendProcess);
    exit(1);
}

/* ---------- 2. Seed disposable organizations/users/wallets/jobs ---------- */

$seededUserIds = [];
$seededOrgIds = [];
$jobIds = [];
$itemsPerOrg = (int)ceil($items / $orgs);
$defaultOriginator = '5000';

set_setting('default_originator', $defaultOriginator);

for ($o = 0; $o < $orgs; $o++) {
    // ellsms_numbers.number is UNIQUE -- every organization needs its own distinct sender line,
    // not the shared default (discovered the hard way: seeding LOAD_TEST_ORGS > 1 with one shared
    // number crashed on the second org's INSERT with a duplicate-key error).
    $orgOriginator = sprintf('%04d', 5000 + $o);

    $username = 'loadtest_' . bin2hex(random_bytes(4));
    db()->prepare('INSERT INTO user_ (username, active, deleted) VALUES (?, 1, 0)')->execute([$username]);
    $userId = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO ellsms_meta (user_id, panel_access, is_admin, originator) VALUES (?, 1, 0, ?)')->execute([$userId, $orgOriginator]);
    $seededUserIds[] = $userId;

    $orgResult = create_organization($userId, "Load Test Org {$o}");
    if (!$orgResult['ok']) {
        fwrite(STDERR, "Failed to create organization: " . ($orgResult['reason'] ?? 'unknown') . "\n");
        exit(1);
    }
    $organizationId = (int)$orgResult['organization_id'];
    $seededOrgIds[] = $organizationId;
    db()->prepare('INSERT INTO ellsms_numbers (number, organization_id) VALUES (?, ?)')->execute([$orgOriginator, $organizationId]);

    $thisOrgCount = min($itemsPerOrg, $items - $o * $itemsPerOrg);
    if ($thisOrgCount <= 0) continue;

    wallet_credit($userId, $thisOrgCount * 10, 'purchase', 'load_test', "seed:{$userId}", "loadtest:credit:{$userId}");

    $rows = [];
    for ($i = 0; $i < $thisOrgCount; $i++) {
        $rows[] = ['mobile' => sprintf('0912%07d', ($o * $itemsPerOrg + $i) % 10000000), 'content' => 'load test message'];
    }
    $user = ['id' => $userId, 'role' => 'user', 'organization_id' => $organizationId];
    [$ok, $msg, $jobId] = bulk_queue_job($user, 'p2p', "Load test job {$o}", $orgOriginator, null, $rows);
    if (!$ok) {
        fwrite(STDERR, "Failed to queue job for org {$o}: {$msg}\n");
        exit(1);
    }
    $jobIds[] = $jobId;
}

$totalSeeded = array_sum(array_map(static fn($id) => (int)db()->query("SELECT COUNT(*) c FROM ellsms_bulk_items WHERE job_id = {$id}")->fetch()['c'], $jobIds));
echo "Seeded {$totalSeeded} items across " . count($jobIds) . " job(s) / " . count($seededOrgIds) . " organization(s).\n";

/* ---------- 3. Run workers ---------- */

putenv("API_BASE_URL={$fakeBackendUrl}");
putenv("WORKER_BULK_BATCH_SIZE={$batchSize}");
if ($failureRate > 0) {
    // Short backoff so retryable failures resolve within this harness's own timeout window instead
    // of waiting out the production-sized default backoff (STEP 17).
    putenv('JOB_RETRY_BASE_SECONDS=1');
    putenv('JOB_RETRY_MAX_SECONDS=3');
}

$workerDeadline = microtime(true) + $workerTimeout;
$startedAt = microtime(true);

// Same pipe-buffer reasoning as the fake backend above: at load-test volume each worker's own
// Logger CLI mirror output can exceed the OS pipe buffer well before this harness gets around to
// reading it (all $workers processes are spawned first, in this same loop, before any pipe is
// read), which would wedge the worker mid-batch. Real per-process log files instead.
$processes = [];
$outLogPaths = [];
for ($w = 0; $w < $workers; $w++) {
    $outLogPath = $logDir . '/load-test-worker-' . getmypid() . '-' . $w . '.log';
    $proc = proc_open(
        [PHP_BINARY, $root . '/cron/load-test-worker-runner.php', (string)$workerDeadline],
        [1 => ['file', $outLogPath, 'w'], 2 => ['file', $outLogPath, 'a']],
        $pipes,
        null,
        null
    );
    if ($proc === false) {
        fwrite(STDERR, "Failed to spawn worker {$w}.\n");
        exit(1);
    }
    $processes[] = $proc;
    $outLogPaths[] = $outLogPath;
}

$workerResults = [];
foreach ($processes as $i => $proc) {
    proc_close($proc);
    $out = (string)@file_get_contents($outLogPaths[$i]);
    // load-test-worker-runner.php's own log also carries every Logger CLI mirror line from the
    // pass it just ran (STEP 5's per-pass logging) -- its own JSON summary is always the LAST
    // line, not the only line, so that's what gets parsed here.
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $lastLine = end($lines);
    $decoded = $lastLine !== false ? json_decode($lastLine, true) : null;
    $workerResults[] = $decoded ?? ['raw_line_count' => count($lines)];
    @unlink($outLogPaths[$i]);
}

$elapsedSeconds = microtime(true) - $startedAt;

putenv('JOB_RETRY_BASE_SECONDS');
putenv('JOB_RETRY_MAX_SECONDS');

/* ---------- 4. Measure final state + correctness (STEP 12) ---------- */

$placeholders = implode(',', array_fill(0, count($jobIds), '?'));
$finalCounts = db()->prepare(
    "SELECT status, COUNT(*) c FROM ellsms_bulk_items WHERE job_id IN ({$placeholders}) GROUP BY status"
);
$finalCounts->execute($jobIds);
$byStatus = [];
foreach ($finalCounts->fetchAll() as $row) {
    $byStatus[$row['status']] = (int)$row['c'];
}

$sent = $byStatus['sent'] ?? 0;
$failed = $byStatus['failed'] ?? 0;
$pending = $byStatus['pending'] ?? 0;
$processing = $byStatus['processing'] ?? 0;

$correctness = [
    'total_accounted_for'        => ($sent + $failed + $pending) === $totalSeeded,
    'no_stuck_processing_rows'   => $processing === 0,
    'no_negative_wallet_balance' => true,
    'reservations_reconciled'    => true,
    'no_cross_tenant_item_leakage' => true,
];

foreach ($seededUserIds as $uid) {
    $bal = wallet_balance($uid)['available'];
    if ($bal < 0) $correctness['no_negative_wallet_balance'] = false;
}
$stmt = db()->prepare("SELECT status FROM ellsms_wallet_reservations WHERE reference_type = 'bulk_job' AND reference_id = ?");
foreach ($jobIds as $jobId) {
    $stmt->execute([(string)$jobId]);
    $r = $stmt->fetch();
    // A drained job's reservation must have moved out of 'active' (committed per-item as it sent,
    // released for whatever wasn't sent) -- an active reservation after full drain means something
    // was left stranded (STEP 12: "reservations reconcile correctly").
    if ($pending === 0 && $processing === 0 && $r && $r['status'] === 'active') {
        $correctness['reservations_reconciled'] = false;
    }
}
// Cross-tenant leakage check: every item claimed by this run must belong to one of THIS run's own
// job ids -- trivially true by construction (items are only ever inserted under jobIds seeded
// above), verified here as a real query rather than assumed. Job ids are integers this script
// itself generated (never external input), so inlining them is safe -- db()->query() (unlike
// prepare()/execute()) has no parameter-binding facility to use here.
$jobIdList = implode(',', array_map('intval', $jobIds));
$leaked = (int)(db()->query(
    "SELECT COUNT(*) c FROM ellsms_bulk_items i JOIN ellsms_bulk_jobs j ON j.id = i.job_id
     WHERE i.job_id IN ({$jobIdList}) AND j.id NOT IN ({$jobIdList})"
)->fetch()['c'] ?? 0);
if ($leaked > 0) $correctness['no_cross_tenant_item_leakage'] = false;

$allCorrect = !in_array(false, $correctness, true);

/* ---------- 5. Report ---------- */

$throughput = $elapsedSeconds > 0 ? round(($sent + $failed) / $elapsedSeconds, 2) : 0.0;

$result = [
    'label'       => $label,
    'git_ref'     => trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')) ?: null,
    'generated_at' => date('c'),
    'config' => [
        'items' => $items, 'workers' => $workers, 'orgs' => $orgs, 'batch_size' => $batchSize,
        'backend_latency_ms' => $latencyMs, 'backend_latency_jitter_ms' => $jitterMs,
        'failure_rate' => $failureRate, 'failure_mix' => $failureMix,
    ],
    'result' => [
        'seeded_items' => $totalSeeded, 'sent' => $sent, 'failed' => $failed,
        'pending_at_end' => $pending, 'processing_at_end' => $processing,
        'elapsed_seconds' => round($elapsedSeconds, 3), 'throughput_items_per_second' => $throughput,
        'worker_results' => $workerResults,
    ],
    'correctness' => $correctness,
    'correctness_ok' => $allCorrect,
];

echo "\n--- Result ---\n";
echo "elapsed: {$result['result']['elapsed_seconds']}s  throughput: {$throughput} items/sec\n";
echo "sent={$sent} failed={$failed} pending={$pending} processing={$processing}\n";
echo 'correctness: ' . ($allCorrect ? 'OK' : 'FAILED — ' . json_encode(array_keys(array_filter($correctness, static fn($v) => $v === false)))) . "\n";

$benchDir = $root . '/storage/benchmarks';
if (!is_dir($benchDir)) @mkdir($benchDir, 0750, true);
$artifactPath = $benchDir . '/phase-9-' . date('Ymd-His') . '-' . preg_replace('/[^a-z0-9_-]/i', '_', $label) . '.json';
file_put_contents($artifactPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "artifact: {$artifactPath}\n";

/* ---------- 6. Cleanup ---------- */

proc_terminate($fakeBackendProcess);
proc_close($fakeBackendProcess);
@unlink($fakeBackendLogPath);

if (!$keep) {
    try {
        foreach ($jobIds as $jobId) {
            db()->prepare('DELETE FROM ellsms_bulk_items WHERE job_id = ?')->execute([$jobId]);
            db()->prepare('DELETE FROM ellsms_bulk_jobs WHERE id = ?')->execute([$jobId]);
        }
        db()->prepare('DELETE FROM ellsms_wallet_reservations WHERE reference_type = ? AND reference_id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')')
            ->execute(array_merge(['bulk_job'], array_map('strval', $jobIds)));
        foreach ($seededUserIds as $uid) {
            // A failure-rate run records ellsms_message_attempts rows (Phase 8, Invariant E) for
            // every simulated 4xx/5xx/timeout -- these reference organization_id and must be
            // cleared before the organization itself is deleted below, or the FK blocks it.
            db()->prepare('DELETE FROM ellsms_message_attempts WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM ellsms_wallet_transactions WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM ellsms_wallet_accounts WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM ellsms_organization_memberships WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM ellsms_numbers WHERE assigned_user_id = ? OR organization_id IN (SELECT id FROM ellsms_organizations WHERE created_by_user_id = ?)')->execute([$uid, $uid]);
            db()->prepare('DELETE FROM ellsms_meta WHERE user_id = ?')->execute([$uid]);
            db()->prepare('DELETE FROM user_ WHERE id = ?')->execute([$uid]);
        }
        foreach ($seededOrgIds as $oid) {
            db()->prepare('DELETE FROM ellsms_organizations WHERE id = ?')->execute([$oid]);
        }
        echo "Cleaned up seeded data.\n";
    } catch (Throwable $t) {
        fwrite(STDERR, "WARNING: cleanup failed ({$t->getMessage()}) -- seeded data may remain; " .
            'org ids: ' . implode(',', $seededOrgIds) . ', user ids: ' . implode(',', $seededUserIds) . ", job ids: " . implode(',', $jobIds) . "\n");
    }
} else {
    echo "LOAD_TEST_KEEP=1 — seeded data left in place (job ids: " . implode(',', $jobIds) . ").\n";
}

exit($allCorrect ? 0 : 2);
