<?php
/**
 * ELLSMS — pre-deploy gate (Phase 10, STEP 35).
 *
 * Non-mutating. Meant to run right before a deploy, with the deploy's actual target environment
 * variables already in place, to answer one question: is it safe to proceed? Composes existing
 * tools rather than re-implementing their checks — see each row below for what it actually shells
 * out to. Exits non-zero on any blocker.
 *
 * Usage:
 *   php cron/predeploy-check.php
 *   php cron/predeploy-check.php --json
 */
require_once __DIR__ . '/../app/bootstrap.php';

$json = in_array('--json', $argv ?? [], true);
$root = dirname(__DIR__);
$blockers = [];
$warnings = [];

function shell_ok(string $root, string $relativePath, array $args = []): array {
    $cmd = array_merge([PHP_BINARY, $root . '/' . $relativePath], $args);
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $exitCode);
    return ['ok' => $exitCode === 0, 'output' => implode("\n", $output)];
}

// 1. config-check
$cfg = shell_ok($root, 'cron/config-check.php');
if (!$cfg['ok']) {
    $blockers[] = 'config-check reported blocking configuration issues — run `make config-check` for detail';
}

// 2. Database reachable
try {
    db()->query('SELECT 1');
} catch (\Throwable $e) {
    $blockers[] = 'database is not reachable with the configured BACKEND_DB_* credentials';
}

// 3. Migration status known (read-only)
$migrationStatus = shell_ok($root, 'cron/db-migrate.php', ['--status']);
if (!$migrationStatus['ok']) {
    $warnings[] = 'migration status could not be determined (see `make migration-status`) — the migrations table may not exist yet on a brand-new install';
} elseif (str_contains($migrationStatus['output'], 'Pending')
       && !preg_match('/Pending \(0\)/', $migrationStatus['output'])) {
    $warnings[] = 'pending migrations exist — run `make migration-preflight` then apply them as its own explicit deploy step before starting the app';
}

// 4. Backend API configuration present
if (env('API_BASE_URL', '') === '') {
    $warnings[] = 'API_BASE_URL is not set — message sending will fail closed until configured';
}

// 5. Writable directories
foreach (['storage/logs', 'storage/kyc'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path) || !is_writable($path)) {
        $blockers[] = "{$dir} does not exist or is not writable by the runtime user";
    }
}

// 6. No fake/load-test mode active
$env = app_env();
if ($env === 'production') {
    foreach (['ELLSMS_ALLOW_LOAD_TEST', 'FAKE_BACKEND_LATENCY_MS', 'FAKE_BACKEND_FAILURE_RATE'] as $unsafeVar) {
        if (env($unsafeVar, '') !== '') {
            $blockers[] = "{$unsafeVar} is set in a production environment — this must never be present outside test/benchmark runs";
        }
    }
}

// 7. Backend-boundary-check
$boundary = shell_ok($root, 'cron/backend-boundary-check.php');
if (!$boundary['ok']) {
    $blockers[] = 'backend-boundary-check found direct access to a backend-owned table outside the approved adapters';
}

// 8. RBAC/tenant integrity tools at least runnable (not blocking on findings -- those are
// operational, not deploy-blocking; a broken TOOL is a blocker, a real finding is a WARN)
foreach (['cron/rbac-integrity-check.php' => 'rbac-integrity-check', 'cron/tenant-integrity-check.php' => 'tenant-integrity-check'] as $script => $label) {
    $result = shell_ok($root, $script);
    if (!$result['ok']) {
        $warnings[] = "{$label} reported findings — review with `make {$label}` before proceeding";
    }
}

$status = $blockers ? 'FAIL' : ($warnings ? 'WARN' : 'PASS');

if ($json) {
    echo json_encode(['status' => $status, 'blockers' => $blockers, 'warnings' => $warnings], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit($blockers ? 1 : 0);
}

echo "ELLSMS pre-deploy check\n\n";
if ($blockers) {
    echo "BLOCKERS:\n";
    foreach ($blockers as $b) echo "  - {$b}\n";
}
if ($warnings) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $w) echo "  - {$w}\n";
}
echo "\n{$status}" . ($status === 'PASS' ? ' — safe to proceed with deployment.' : '') . "\n";
exit($blockers ? 1 : 0);
