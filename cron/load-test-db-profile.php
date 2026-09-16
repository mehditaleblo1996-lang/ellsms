<?php
/**
 * ELLSMS — DB-pressure profiler around the existing load-test harness (issue #30).
 *
 * Runs cron/load-test.php unchanged while sampling shared-MySQL global counters from a second
 * process. The result is a companion JSON artifact with connection pressure, lock waits, slow
 * queries, I/O-ish counters, temp-table pressure and buffer-pool read ratios.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// Match IntegrationTestCase/load-test.php's test-DB aliases.
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
require_once $root . '/app/Observability/DatabasePerformance.php';

$dbName = (string)env('BACKEND_DB_NAME', '');
if (!str_contains(strtolower($dbName), 'test') && env('ELLSMS_ALLOW_LOAD_TEST', '0') !== '1') {
    fwrite(STDERR, "REFUSING TO RUN: BACKEND_DB_NAME (\"{$dbName}\") does not look disposable.\n");
    fwrite(STDERR, "Use a real disposable database, or set ELLSMS_ALLOW_LOAD_TEST=1 if intentional.\n");
    exit(1);
}

$interval = max(0.25, (float)(env('DB_PROFILE_SAMPLE_INTERVAL_SECONDS', '1') ?? '1'));
$label = (string)(env('LOAD_TEST_LABEL', 'unlabeled') ?? 'unlabeled');
$benchDir = $root . '/storage/benchmarks';
$logDir = $root . '/storage/logs';
if (!is_dir($benchDir)) @mkdir($benchDir, 0750, true);
if (!is_dir($logDir)) @mkdir($logDir, 0750, true);

$db = db();
$variablesResult = db_performance_variables($db);
$samples = [db_performance_status_snapshot($db)];

$childLog = $logDir . '/issue-30-load-profile-' . getmypid() . '.log';
$proc = proc_open(
    [PHP_BINARY, $root . '/cron/load-test.php'],
    [1 => ['file', $childLog, 'w'], 2 => ['file', $childLog, 'a']],
    $pipes,
    null,
    null
);
if ($proc === false) {
    fwrite(STDERR, "Could not start cron/load-test.php.\n");
    exit(1);
}

$observedExitCode = -1;
while (true) {
    $state = proc_get_status($proc);
    if (!$state['running']) {
        $observedExitCode = (int)$state['exitcode'];
        break;
    }
    usleep((int)round($interval * 1_000_000));
    $samples[] = db_performance_status_snapshot($db);
}
$samples[] = db_performance_status_snapshot($db);

$closedExitCode = proc_close($proc);
$exitCode = $observedExitCode >= 0 ? $observedExitCode : $closedExitCode;
$childOutput = (string)@file_get_contents($childLog);
@unlink($childLog);
if ($childOutput !== '') {
    echo $childOutput;
    if (!str_ends_with($childOutput, "\n")) echo "\n";
}

$variables = $variablesResult['values'] ?? [];
$summary = db_performance_summarize_samples($samples, $variables);
$tableStats = [];
$indexInventory = [];
$indexChecks = [];
$inspectionErrors = [];
try {
    $tableStats = db_performance_table_stats($db);
} catch (Throwable $e) {
    $inspectionErrors['table_stats'] = $e->getMessage();
}
try {
    $indexInventory = db_performance_index_inventory($db);
    $indexChecks = db_performance_verify_expected_indexes($indexInventory);
} catch (Throwable $e) {
    $inspectionErrors['indexes'] = $e->getMessage();
}

$artifact = [
    'generated_at' => date('c'),
    'label' => $label,
    'git_ref' => trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')) ?: null,
    'database' => $dbName,
    'load_test_exit_code' => $exitCode,
    'sample_interval_seconds' => $interval,
    'variables' => $variablesResult,
    'db_profile' => $summary,
    'samples' => $samples,
    'hot_table_stats' => $tableStats,
    'index_checks' => $indexChecks,
    'inspection_errors' => $inspectionErrors,
];

$safeLabel = preg_replace('/[^a-z0-9_-]/i', '_', $label) ?: 'unlabeled';
$artifactPath = $benchDir . '/issue-30-db-' . date('Ymd-His') . '-' . $safeLabel . '.json';
file_put_contents($artifactPath, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n--- DB profile ---\n";
echo 'samples=' . ($summary['sample_count'] ?? 0) . ' elapsed=' . round((float)($summary['elapsed_seconds'] ?? 0), 3) . "s\n";
if (!empty($summary['available'])) {
    echo 'peak_connections=' . ($summary['peak']['Threads_connected'] ?? 0)
       . ' peak_threads_running=' . ($summary['peak']['Threads_running'] ?? 0)
       . ' questions_per_second=' . round((float)($summary['counter_per_second']['Questions'] ?? 0), 2)
       . ' row_lock_waits=' . ($summary['counter_delta']['Innodb_row_lock_waits'] ?? 0)
       . "\n";
}
echo "db_artifact: {$artifactPath}\n";

exit($exitCode);
