<?php
/**
 * ELLSMS — disaster recovery drill (Phase 11, STEP 21).
 *
 * Composes existing tools into one operator-facing, timed end-to-end drill: seed/snapshot -> real
 * backup -> simulate total loss (DROP the configured database) -> real restore -> migration status
 * + every read-only integrity tool -> start a real (throwaway) app server + run cron/smoke-test.php
 * against it -> run the worker once -> compare critical record counts pre/post -> record elapsed
 * time to dr_drill_status_file() (which cron/backup-status.php reads).
 *
 * SAFETY (Invariant C, same convention as cron/load-test.php): refuses to run unless the
 * configured database looks disposable (BACKEND_DB_NAME contains "test") or ELLSMS_ALLOW_DR_DRILL=1
 * is explicitly set. This drill DROPS the configured database as its "simulate loss" step -- it
 * must never be able to do that to a production database by accident.
 *
 * Usage:
 *   php cron/dr-drill.php
 *   php cron/dr-drill.php --json
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$dbHost = (string)env('BACKEND_DB_HOST', '');
$dbPort = (string)env('BACKEND_DB_PORT', '3306');
$dbName = (string)env('BACKEND_DB_NAME', '');
$dbUser = (string)env('BACKEND_DB_USER', '');
$dbPass = (string)env('BACKEND_DB_PASS', '');

function drill_fail(bool $json, array $steps, string $message, float $startedAt): never {
    $result = ['status' => 'FAIL', 'error' => $message, 'elapsed_seconds' => round(microtime(true) - $startedAt, 2), 'steps' => $steps];
    drill_write_status($result);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
        foreach ($steps as $s) {
            fwrite(STDERR, sprintf("  [%-4s] %s\n", $s['status'], $s['name']));
        }
    }
    exit(1);
}

function drill_write_status(array $result): void {
    $statusFile = dr_drill_status_file();
    $payload = [
        'status' => $result['status'],
        'ran_at' => gmdate('c'),
        'elapsed_seconds' => $result['elapsed_seconds'],
    ];
    @file_put_contents($statusFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($statusFile, 0640);
}

/** @return array{0: string, 1: int} */
function drill_run(string $script, array $args, array $env): array {
    $envAssign = [];
    foreach ($env as $k => $v) {
        $envAssign[] = $k . '=' . escapeshellarg((string)$v);
    }
    $cmd = implode(' ', $envAssign) . ' php ' . escapeshellarg(dirname(__DIR__) . '/cron/' . $script)
         . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $outputLines, $exitCode);
    return [implode("\n", $outputLines), $exitCode];
}

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    fwrite(STDERR, "FAIL: BACKEND_DB_HOST/NAME/USER must be set\n");
    exit(1);
}
if (!str_contains(strtolower($dbName), 'test') && env('ELLSMS_ALLOW_DR_DRILL', '0') !== '1') {
    fwrite(STDERR, "FAIL: BACKEND_DB_NAME (\"{$dbName}\") does not look like a disposable test database, and this drill DROPS it as its \"simulate loss\" step.\n");
    fwrite(STDERR, "Point this at a real disposable database, or set ELLSMS_ALLOW_DR_DRILL=1 if you are certain.\n");
    exit(1);
}

$startedAt = microtime(true);
$steps = [];
$baseEnv = ['APP_ENV' => 'testing', 'BACKEND_DB_HOST' => $dbHost, 'BACKEND_DB_PORT' => $dbPort, 'BACKEND_DB_USER' => $dbUser, 'BACKEND_DB_PASS' => $dbPass, 'BACKUP_DIR' => backup_dir()];

// ---- Step 1: pre-drill snapshot of critical record counts (before touching anything) ----
$server = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$countTables = ['ellsms_organizations', 'ellsms_organization_memberships', 'ellsms_wallet_accounts', 'ellsms_payments', 'ellsms_tickets'];
$preCounts = [];
try {
    foreach ($countTables as $t) {
        $preCounts[$t] = (int)$server->query("SELECT COUNT(*) FROM `{$dbName}`.`{$t}`")->fetchColumn();
    }
    $steps[] = ['name' => 'pre-drill snapshot', 'status' => 'PASS', 'summary' => json_encode($preCounts, JSON_UNESCAPED_SLASHES)];
} catch (Throwable $e) {
    $steps[] = ['name' => 'pre-drill snapshot', 'status' => 'FAIL', 'summary' => $e->getMessage()];
    drill_fail($json, $steps, 'could not snapshot pre-drill record counts (is the schema fully migrated?)', $startedAt);
}

// ---- Step 2: real backup ----
[$backupOut, $backupExit] = drill_run('backup.php', ['--json'], $baseEnv);
if ($backupExit !== 0) {
    $steps[] = ['name' => 'backup', 'status' => 'FAIL', 'summary' => mb_strimwidth($backupOut, 0, 300, '…')];
    drill_fail($json, $steps, 'backup step failed', $startedAt);
}
$backupId = json_decode($backupOut, true)['manifest']['backup_id'] ?? null;
$steps[] = ['name' => 'backup', 'status' => 'PASS', 'summary' => "backup_id={$backupId}"];

// ---- Step 3: simulate total loss (guard above already confirmed this is a disposable database) ----
try {
    $server->exec('DROP DATABASE `' . $dbName . '`');
    $steps[] = ['name' => 'simulate loss (DROP DATABASE)', 'status' => 'PASS', 'summary' => "dropped {$dbName}"];
} catch (Throwable $e) {
    $steps[] = ['name' => 'simulate loss (DROP DATABASE)', 'status' => 'FAIL', 'summary' => $e->getMessage()];
    drill_fail($json, $steps, 'could not drop the database to simulate loss', $startedAt);
}

// ---- Step 4: real restore, same name (now nonexistent -> the safe/default fresh-create path) ----
[$restoreOut, $restoreExit] = drill_run('restore.php', [$backupId, '--target-db=' . $dbName, '--json'], $baseEnv);
if ($restoreExit !== 0) {
    $steps[] = ['name' => 'restore', 'status' => 'FAIL', 'summary' => mb_strimwidth($restoreOut, 0, 300, '…')];
    drill_fail($json, $steps, 'restore step failed — database was NOT successfully recovered', $startedAt);
}
$steps[] = ['name' => 'restore', 'status' => 'PASS', 'summary' => "restored into {$dbName}"];

// ---- Step 5: migration status + every existing read-only integrity tool, against restored data ----
[$migOut, $migExit] = drill_run('db-migrate.php', ['--status'], $baseEnv);
$migOk = $migExit === 0 && str_contains($migOut, 'Pending (0):');
$steps[] = ['name' => 'migration-status', 'status' => $migOk ? 'PASS' : 'FAIL', 'summary' => mb_strimwidth($migOut, 0, 200, '…')];
if (!$migOk) {
    drill_fail($json, $steps, 'restored database is not fully migrated', $startedAt);
}
foreach (['db-integrity-check.php', 'tenant-integrity-check.php', 'rbac-integrity-check.php', 'wallet-audit.php'] as $tool) {
    [$out, $exit] = drill_run($tool, [], $baseEnv);
    $steps[] = ['name' => $tool, 'status' => $exit === 0 ? 'PASS' : 'FAIL', 'summary' => mb_strimwidth($out, 0, 200, '…')];
    if ($exit !== 0) {
        drill_fail($json, $steps, "{$tool} reported a violation on the restored database", $startedAt);
    }
}

// ---- Step 6: start a real (throwaway) app server against the restored database, smoke-test it ----
$port = 19000 + random_int(0, 900);
$publicDir = dirname(__DIR__) . '/public';
$serverEnv = array_merge($baseEnv, ['BACKEND_DB_NAME' => $dbName]);
$serverProc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $publicDir],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    null,
    $serverEnv
);
if ($serverProc === false) {
    $steps[] = ['name' => 'start app server', 'status' => 'FAIL', 'summary' => 'could not start PHP built-in server'];
    drill_fail($json, $steps, 'could not start throwaway app server for smoke test', $startedAt);
}
// Give the dev server a moment to bind before probing it.
$booted = false;
for ($i = 0; $i < 20; $i++) {
    usleep(150000);
    $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($conn) { fclose($conn); $booted = true; break; }
}
if (!$booted) {
    proc_terminate($serverProc);
    $steps[] = ['name' => 'start app server', 'status' => 'FAIL', 'summary' => 'server did not accept connections in time'];
    drill_fail($json, $steps, 'throwaway app server never came up', $startedAt);
}
$steps[] = ['name' => 'start app server', 'status' => 'PASS', 'summary' => "listening on 127.0.0.1:{$port}"];

[$smokeOut, $smokeExit] = drill_run('smoke-test.php', ["http://127.0.0.1:{$port}", '--json'], $baseEnv);
proc_terminate($serverProc);
proc_close($serverProc);
$steps[] = ['name' => 'smoke-test', 'status' => $smokeExit === 0 ? 'PASS' : 'FAIL', 'summary' => mb_strimwidth($smokeOut, 0, 300, '…')];
if ($smokeExit !== 0) {
    drill_fail($json, $steps, 'smoke test against the restored app failed', $startedAt);
}

// ---- Step 7: run the worker once against the restored database ----
[$workerOut, $workerExit] = drill_run('worker.php', ['--once'], $serverEnv);
$steps[] = ['name' => 'worker --once', 'status' => $workerExit === 0 ? 'PASS' : 'FAIL', 'summary' => mb_strimwidth($workerOut, 0, 200, '…')];
if ($workerExit !== 0) {
    drill_fail($json, $steps, 'worker pass against the restored database failed', $startedAt);
}

// ---- Step 8: verify critical records survived exactly ----
$postCounts = [];
$mismatches = [];
try {
    foreach ($countTables as $t) {
        $postCounts[$t] = (int)$server->query("SELECT COUNT(*) FROM `{$dbName}`.`{$t}`")->fetchColumn();
        if ($postCounts[$t] !== $preCounts[$t]) {
            $mismatches[] = "{$t}: {$preCounts[$t]} -> {$postCounts[$t]}";
        }
    }
} catch (Throwable $e) {
    drill_fail($json, $steps, "could not verify post-restore record counts: {$e->getMessage()}", $startedAt);
}
$steps[] = ['name' => 'verify critical records', 'status' => $mismatches === [] ? 'PASS' : 'FAIL', 'summary' => $mismatches === [] ? 'all record counts match pre-drill snapshot exactly' : implode('; ', $mismatches)];
if ($mismatches !== []) {
    drill_fail($json, $steps, 'critical record counts changed across the drill — restore did not faithfully reproduce the data', $startedAt);
}

$elapsedSeconds = round(microtime(true) - $startedAt, 2);
$result = ['status' => 'PASS', 'elapsed_seconds' => $elapsedSeconds, 'backup_id' => $backupId, 'steps' => $steps];
drill_write_status($result);

Logger::info('dr_drill.completed', ['status' => 'PASS', 'elapsed_seconds' => $elapsedSeconds, 'backup_id' => $backupId]);

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS disaster recovery drill\n\n";
    foreach ($steps as $s) {
        echo sprintf("  [%-4s] %s\n", $s['status'], $s['name']);
    }
    echo "\nOverall: PASS ({$elapsedSeconds}s, measured in this test environment -- not a production RTO guarantee)\n";
}
exit(0);
