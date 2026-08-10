<?php
/**
 * ELLSMS — non-mutating aggregate operational integrity check (Phase 10, STEP 43).
 *
 * Runs every existing read-only integrity/status tool this project already has and reports one
 * consolidated PASS/WARN/FAIL per tool, plus an overall verdict — never auto-fixes anything
 * (ownership/financial data corrections stay a deliberate operator decision, same policy every one
 * of these tools already has individually). This is orchestration, not new logic: each row below
 * shells out to the real tool and interprets its own real exit code, so this can never silently
 * drift from what running that tool directly would report.
 *
 * Usage:
 *   php cron/production-integrity-check.php
 *   php cron/production-integrity-check.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
$root = dirname(__DIR__);

/** @return array{status: string, exit_code: int, summary: string} */
function run_tool(string $root, string $relativePath, array $extraArgs = []): array {
    $cmd = array_merge([PHP_BINARY, $root . '/' . $relativePath], $extraArgs);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if ($proc === false) {
        return ['status' => 'FAIL', 'exit_code' => -1, 'summary' => 'could not start process'];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$out))));
    $summary = end($lines) ?: 'no output';
    $status = $exitCode === 0 ? (str_contains($out, '[WARN]') || str_contains($out, 'WARN') ? 'WARN' : 'PASS') : 'FAIL';
    return ['status' => $status, 'exit_code' => $exitCode, 'summary' => mb_strimwidth($summary, 0, 200, '…')];
}

$checks = [
    'config-check'              => fn() => run_tool($root, 'cron/config-check.php'),
    'backend-boundary-check'    => fn() => run_tool($root, 'cron/backend-boundary-check.php'),
];

// The remaining tools need a real database connection -- only run them if one is actually
// reachable, so this command stays usable (for config-check/boundary-check at least) even before
// BACKEND_DB_* is finalized, rather than fatally erroring on the first DB-dependent check.
$dbReachable = false;
try {
    db()->query('SELECT 1');
    $dbReachable = true;
} catch (\Throwable $e) {
    // fall through -- reported as its own finding below
}

if ($dbReachable) {
    $checks['db-integrity-check']   = fn() => run_tool($root, 'cron/db-integrity-check.php');
    $checks['tenant-integrity-check'] = fn() => run_tool($root, 'cron/tenant-integrity-check.php');
    $checks['rbac-integrity-check'] = fn() => run_tool($root, 'cron/rbac-integrity-check.php');
    $checks['wallet-audit']         = fn() => run_tool($root, 'cron/wallet-audit.php');
    $checks['jobs-status']          = fn() => run_tool($root, 'cron/jobs-status.php');
    $checks['performance-snapshot'] = fn() => run_tool($root, 'cron/performance-snapshot.php');
    $checks['migration-status']     = fn() => run_tool($root, 'cron/db-migrate.php', ['--status']);
}

$results = [];
foreach ($checks as $name => $runner) {
    $results[$name] = $runner();
}
if (!$dbReachable) {
    $results['database-connectivity'] = ['status' => 'FAIL', 'exit_code' => -1, 'summary' => 'could not connect to BACKEND_DB_* — DB-dependent checks skipped'];
}

$overall = 'PASS';
foreach ($results as $r) {
    if ($r['status'] === 'FAIL') { $overall = 'FAIL'; break; }
    if ($r['status'] === 'WARN' && $overall !== 'FAIL') { $overall = 'WARN'; }
}

if ($json) {
    echo json_encode(['overall' => $overall, 'checks' => $results], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($overall === 'FAIL' ? 1 : 0);
}

echo "ELLSMS production integrity check\n\n";
foreach ($results as $name => $r) {
    echo sprintf("  [%-4s] %-24s %s\n", $r['status'], $name, $r['summary']);
}
echo "\nOverall: {$overall}\n";
exit($overall === 'FAIL' ? 1 : 0);
