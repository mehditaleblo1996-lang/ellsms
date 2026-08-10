<?php
/**
 * ELLSMS — release orchestration (Phase 11, STEP 26/27).
 *
 * This project has no CI/CD pipeline and no orchestrator integration (Docker images are built and
 * deployed by an operator running `docker compose build`/`up` by hand) — so this command does NOT
 * "deploy" in the sense of pulling new code or restarting containers; that step happens around
 * this command, not inside it (see docs/production-runbook.md's 15-step sequence). What this DOES
 * orchestrate is the part that's actually automatable and safety-critical: composing the existing
 * validation tools, taking a real pre-release backup, and recording what happened -- never a new
 * release-management platform, just gluing together tools that already exist.
 *
 * Three modes, deliberately asymmetric in required friction:
 *   --check   read-only. config-check + predeploy-check + backend-boundary-check + sms-pricing-integrity-check + backup-status.
 *   --plan    read-only. Prints the full 15-step release sequence annotated with current state
 *             (git commit, migration head, latest backup age) -- nothing here executes anything.
 *   --apply   MUTATES: takes a real backup, verifies it, reports migration status (does NOT apply
 *             migrations itself -- STEP 26 forbids auto-applying; that stays its own explicit
 *             `make db-migrations-apply` step per this project's standing convention), runs
 *             production-integrity-check, and records release metadata. Requires --confirm=RELEASE
 *             and --operator=<id> -- an unconfirmed or operator-less --apply refuses to run, so a
 *             release is never recorded/performed silently or anonymously.
 *
 * Usage:
 *   php cron/release.php --check
 *   php cron/release.php --plan
 *   php cron/release.php --apply --confirm=RELEASE --operator=alice
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$args = $argv ?? [];
$json = in_array('--json', $args, true);
if ($json) {
    Logger::setCliMirror(false);
}
$mode = null;
foreach (['--check', '--plan', '--apply'] as $m) {
    if (in_array($m, $args, true)) { $mode = ltrim($m, '-'); break; }
}
$confirm = null;
$operator = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--confirm=')) $confirm = substr($a, 10);
    if (str_starts_with($a, '--operator=')) $operator = substr($a, 11);
}

function release_fail(bool $json, string $message): never {
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

if ($mode === null) {
    release_fail($json, 'usage: php cron/release.php --check|--plan|--apply [--confirm=RELEASE --operator=<id>] [--json]');
}

$root = dirname(__DIR__);

/** @return array{status: string, exit_code: int, summary: string} */
function release_run_tool(string $root, string $relativePath, array $extraArgs = []): array {
    $cmd = array_merge([PHP_BINARY, $root . '/' . $relativePath], $extraArgs);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($proc === false) {
        return ['status' => 'FAIL', 'exit_code' => -1, 'summary' => 'could not start process'];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out . $err))));
    $summary = end($lines) ?: 'no output';
    return ['status' => $exitCode === 0 ? 'PASS' : 'FAIL', 'exit_code' => $exitCode, 'summary' => mb_strimwidth($summary, 0, 200, '…')];
}

function release_git_commit(string $root): ?string {
    $out = [];
    exec('cd ' . escapeshellarg($root) . ' && git rev-parse HEAD 2>/dev/null', $out, $exit);
    return $exit === 0 ? trim($out[0] ?? '') : null;
}

function release_migration_head(): ?string {
    try {
        $row = db()->query('SELECT version FROM ellsms_schema_migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

if ($mode === 'check') {
    $checks = [
        'config-check' => release_run_tool($root, 'cron/config-check.php'),
        'predeploy-check' => release_run_tool($root, 'cron/predeploy-check.php'),
        'backend-boundary-check' => release_run_tool($root, 'cron/backend-boundary-check.php'),
        // Pricing misconfiguration is a release-blocking class of fault, not a monitoring one: an
        // ambiguous prefix or a route with no usable tariff makes sends unpriceable (or ambiguous)
        // the moment the release goes live, and it fails closed by design.
        'sms-pricing-integrity-check' => release_run_tool($root, 'cron/sms-pricing-integrity-check.php'),
        'backup-status' => release_run_tool($root, 'cron/backup-status.php'),
    ];
    $overall = 'PASS';
    foreach ($checks as $c) { if ($c['status'] === 'FAIL') { $overall = 'FAIL'; break; } }
    Logger::info('release.check.completed', ['overall' => $overall]);
    if ($json) {
        echo json_encode(['status' => $overall, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "ELLSMS release check\n\n";
        foreach ($checks as $name => $c) {
            echo sprintf("  [%-4s] %-24s %s\n", $c['status'], $name, $c['summary']);
        }
        echo "\nOverall: {$overall}\n";
    }
    exit($overall === 'FAIL' ? 1 : 0);
}

if ($mode === 'plan') {
    $commit = release_git_commit($root);
    $migrationHead = release_migration_head();
    $steps = [
        '1. verify config (make config-check)',
        '2. predeploy-check (make predeploy-check)',
        '3. backup (make backup)',
        '4. verify backup (make backup-verify FILE=<id>)',
        '5. enable maintenance mode (make maintenance-on)',
        '6. drain/stop workers (docker compose stop worker -- graceful, see docs/production-runbook.md)',
        '7. deploy new code (docker compose build && docker compose up -d app -- OUTSIDE this tool)',
        '8. migration preflight (make db-integrity-check)',
        '9. apply migrations (make db-migrations-apply)',
        '10. production-integrity-check (make production-integrity-check)',
        '11. start/restart workers (docker compose up -d worker)',
        '12. smoke test (make smoke-test URL=...)',
        '13. disable maintenance mode (make maintenance-off)',
        '14. monitor (watch logs/metrics for an appropriate window)',
        '15. record release metadata (this command\'s own --apply mode does step 3/4/9-status/10 and writes the metadata file)',
    ];
    Logger::info('release.plan.viewed', ['commit' => $commit, 'migration_head' => $migrationHead]);
    if ($json) {
        echo json_encode(['status' => 'PLAN', 'git_commit' => $commit, 'app_version' => app_version(), 'migration_head' => $migrationHead, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "ELLSMS release plan (nothing executed)\n\n";
        echo "  git commit:      " . ($commit ?? 'unknown') . "\n";
        echo "  app version:     " . app_version() . "\n";
        echo "  migration head:  " . ($migrationHead ?? 'unknown') . "\n\n";
        foreach ($steps as $s) {
            echo "  {$s}\n";
        }
        echo "\nRun with --apply --confirm=RELEASE --operator=<id> to execute the automatable portion (steps 3/4/9-status/10 + metadata).\n";
    }
    exit(0);
}

// ---- --apply ----
if ($confirm !== 'RELEASE') {
    release_fail($json, 'destructive: --apply requires --confirm=RELEASE (must match exactly) — refusing without it');
}
if ($operator === null || trim($operator) === '') {
    release_fail($json, '--apply requires --operator=<id> — a release must never be recorded anonymously');
}

$startedAt = microtime(true);
$steps = [];

$predeploy = release_run_tool($root, 'cron/predeploy-check.php');
$steps['predeploy-check'] = $predeploy;
if ($predeploy['status'] !== 'PASS') {
    release_fail($json, 'predeploy-check failed — refusing to proceed with release');
}

// A real backup is expensive (mysqldump against the whole database) -- run it exactly once and
// capture its full JSON output directly, rather than calling release_run_tool() (which only
// retains the last output line for its summary) and then re-running the same command again to get
// the backup_id out of the full body.
$backupJsonOut = [];
exec(PHP_BINARY . ' ' . escapeshellarg($root . '/cron/backup.php') . ' --json 2>&1', $backupJsonOut, $backupExit);
$backupOutText = implode("\n", $backupJsonOut);
$backupDecoded = json_decode($backupOutText, true);
$backupId = $backupDecoded['manifest']['backup_id'] ?? null;
$steps['backup'] = ['status' => $backupExit === 0 ? 'PASS' : 'FAIL', 'summary' => $backupId !== null ? "backup_id={$backupId}" : mb_strimwidth($backupOutText, 0, 200, '…')];
if ($backupExit !== 0) {
    release_fail($json, 'backup step failed — release aborted before touching anything else');
}

if ($backupId !== null) {
    $verify = release_run_tool($root, 'cron/backup-verify.php', [$backupId]);
    $steps['backup-verify'] = $verify;
    if ($verify['status'] !== 'PASS') {
        release_fail($json, 'post-backup verification failed — release aborted');
    }
}

$migStatus = release_run_tool($root, 'cron/db-migrate.php', ['--status']);
$steps['migration-status'] = $migStatus;
// Deliberately NOT applying migrations here -- STEP 26 explicitly forbids a release tool that
// auto-applies migrations; `make db-migrations-apply` remains its own explicit, reviewed step.

$integrity = release_run_tool($root, 'cron/production-integrity-check.php');
$steps['production-integrity-check'] = $integrity;
if ($integrity['status'] === 'FAIL') {
    release_fail($json, 'production-integrity-check failed post-backup — investigate before continuing the release');
}

$elapsedSeconds = round(microtime(true) - $startedAt, 2);
$metadata = [
    'git_commit' => release_git_commit($root),
    'app_version' => app_version(),
    'released_at' => gmdate('c'),
    'operator' => $operator,
    'migration_head' => release_migration_head(),
    'backup_id' => $backupId,
    'steps' => array_map(static fn($s) => ['status' => $s['status'], 'summary' => $s['summary']], $steps),
    'elapsed_seconds' => $elapsedSeconds,
];

$releasesDir = (defined('APP_ROOT') ? APP_ROOT : $root) . '/storage/releases';
if (!is_dir($releasesDir)) {
    @mkdir($releasesDir, 0750, true);
}
$metadataFile = $releasesDir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($metadataFile, 0640);

Logger::info('release.recorded', ['operator' => $operator, 'backup_id' => $backupId, 'git_commit' => $metadata['git_commit']]);

if ($json) {
    echo json_encode(['status' => 'OK', 'metadata' => $metadata], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "OK — release recorded (operator={$operator}, backup_id={$backupId}, {$elapsedSeconds}s)\n";
    echo "Remaining manual steps (see docs/production-runbook.md): deploy new code, apply migrations, restart workers, smoke test, disable maintenance mode.\n";
}
exit(0);
