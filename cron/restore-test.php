<?php
/**
 * ELLSMS — real disposable-MySQL restore test (Phase 11, STEP 42's operational counterpart).
 *
 * "Mock-only restore tests are insufficient" -- this command performs an actual restore into a
 * throwaway database (cron/restore.php's default safe path, never the source/production database)
 * and then proves the result is usable: migration ledger is fully applied, every existing
 * read-only integrity tool (db/tenant/rbac integrity, wallet-audit) passes against the RESTORED
 * data, and a representative cross-table query executes successfully. Never validates against the
 * source database -- a healthy source proves nothing about whether the backup ARTIFACT itself is
 * restorable. The disposable database is dropped afterward unless --keep is passed.
 *
 * Usage:
 *   php cron/restore-test.php <backup_id> [--keep] [--json]
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$args = $argv ?? [];
$json = in_array('--json', $args, true);
$keep = in_array('--keep', $args, true);
if ($json) {
    Logger::setCliMirror(false);
}

$positional = null;
foreach (array_slice($args, 1) as $a) {
    if ($a === '--json' || $a === '--keep') continue;
    if ($positional === null) { $positional = $a; }
}
$backupId = $positional ?? '';

function restore_test_fail(bool $json, string $message, array $checks = []): never {
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
        foreach ($checks as $name => $r) {
            fwrite(STDERR, sprintf("  [%-4s] %-24s %s\n", $r['status'], $name, $r['summary']));
        }
    }
    exit(1);
}

/** @return array{status: string, exit_code: int, summary: string, output: string} */
function restore_test_run_tool(string $root, string $relativePath, array $extraArgs = []): array {
    $cmd = array_merge([PHP_BINARY, $root . '/' . $relativePath], $extraArgs);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    // No explicit envs array -- proc_open() then inherits this PHP process's actual environment,
    // which includes the putenv('BACKEND_DB_NAME=...') override below pointing every one of these
    // subprocess tools at the RESTORED disposable database, not the original.
    $proc = proc_open($cmd, $descriptors, $pipes);
    if ($proc === false) {
        return ['status' => 'FAIL', 'exit_code' => -1, 'summary' => 'could not start process', 'output' => ''];
    }
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $out . $err))));
    $summary = end($lines) ?: 'no output';
    return ['status' => $exitCode === 0 ? 'PASS' : 'FAIL', 'exit_code' => $exitCode, 'summary' => mb_strimwidth($summary, 0, 200, '…'), 'output' => $out . $err];
}

if ($backupId === '') {
    restore_test_fail($json, 'usage: php cron/restore-test.php <backup_id> [--keep] [--json]');
}

$root = dirname(__DIR__);
$startedAt = microtime(true);
$checks = [];

// ---- Step 1: real restore into a fresh disposable database (cron/restore.php's safe default) ----
$restoreResult = restore_test_run_tool($root, 'cron/restore.php', [$backupId, '--json']);
if ($restoreResult['exit_code'] !== 0) {
    restore_test_fail($json, "restore failed: {$restoreResult['summary']}");
}
$restoreDecoded = json_decode($restoreResult['output'], true);
$targetDb = $restoreDecoded['target_db'] ?? null;
if (!is_string($targetDb) || $targetDb === '') {
    restore_test_fail($json, 'restore reported success but produced no parseable target_db');
}
$checks['restore'] = ['status' => 'PASS', 'exit_code' => 0, 'summary' => "restored into {$targetDb}"];

$dbHost = (string)env('BACKEND_DB_HOST', '');
$dbPort = (string)env('BACKEND_DB_PORT', '3306');
$dbUser = (string)env('BACKEND_DB_USER', '');
$dbPass = (string)env('BACKEND_DB_PASS', '');

if (!$keep) {
    // register_shutdown_function, not try/finally -- restore_test_fail() below exit()s directly on
    // any check failure, which would otherwise skip cleanup and leave the disposable database
    // behind (same lesson as cron/backup.php's credentials-file fix, STEP 6/37).
    register_shutdown_function(static function () use ($dbHost, $dbPort, $dbUser, $dbPass, $targetDb): void {
        try {
            $server = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $server->exec('DROP DATABASE IF EXISTS `' . $targetDb . '`');
        } catch (Throwable $e) {}
    });
}

// ---- Step 2: point subsequent tools at the RESTORED database, never the original ----
putenv("BACKEND_DB_NAME={$targetDb}");

// ---- Step 3: migration ledger must be fully applied, nothing pending ----
$migCheck = restore_test_run_tool($root, 'cron/db-migrate.php', ['--status']);
$migrationFileCount = count(glob($root . '/db/migrations/*.sql'));
if ($migCheck['exit_code'] !== 0 || !str_contains($migCheck['output'], 'Pending (0):') || !str_contains($migCheck['output'], "Applied ({$migrationFileCount}):")) {
    $migCheck['status'] = 'FAIL';
    $migCheck['summary'] = 'restored database is not fully migrated: ' . $migCheck['summary'];
}
$checks['migration-status'] = $migCheck;

// ---- Step 4: every existing read-only integrity tool, against the restored data ----
foreach ([
    'db-integrity-check'     => 'cron/db-integrity-check.php',
    'tenant-integrity-check' => 'cron/tenant-integrity-check.php',
    'rbac-integrity-check'   => 'cron/rbac-integrity-check.php',
    'wallet-audit'           => 'cron/wallet-audit.php',
] as $name => $script) {
    $checks[$name] = restore_test_run_tool($root, $script);
}

// ---- Step 4b: financial consistency (STEP 14) -- wallet-audit above only catches drift against
// user_.currentcredit; these catch the restore-specific risks that check doesn't: a corrupt or
// partially-applied restore leaving negative balances, a ledger whose running balance no longer
// agrees with the account row, or duplicate payment-credit ledger entries (double-crediting).
// Never auto-repairs -- reports and fails, same policy as every other integrity tool here.
try {
    $fin = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$targetDb};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $negative = (int)$fin->query(
        'SELECT COUNT(*) FROM ellsms_wallet_accounts WHERE available_balance < 0 OR reserved_balance < 0'
    )->fetchColumn();

    $duplicatePaymentCredits = (int)$fin->query(
        "SELECT COUNT(*) FROM (
            SELECT reference_id FROM ellsms_wallet_transactions
            WHERE reference_type = 'payment' GROUP BY reference_id HAVING COUNT(*) > 1
         ) d"
    )->fetchColumn();

    // Ledger-derived balance: each account's available_balance must equal the balance_after of
    // its own most recent ledger entry (every wallet mutation writes both in the same
    // transaction, app/wallet.php) -- an account with no ledger entries at all (balance never
    // moved from its opening value) is not a mismatch, just excluded from this comparison.
    $ledgerMismatch = (int)$fin->query(
        "SELECT COUNT(*) FROM ellsms_wallet_accounts wa
         JOIN ellsms_wallet_transactions wt ON wt.user_id = wa.user_id
         JOIN (
            SELECT user_id, MAX(id) AS max_id FROM ellsms_wallet_transactions GROUP BY user_id
         ) latest ON latest.user_id = wt.user_id AND latest.max_id = wt.id
         WHERE wa.available_balance <> wt.balance_after"
    )->fetchColumn();

    $financialIssues = [];
    if ($negative > 0) $financialIssues[] = "{$negative} account(s) with a negative balance";
    if ($duplicatePaymentCredits > 0) $financialIssues[] = "{$duplicatePaymentCredits} payment(s) credited more than once";
    if ($ledgerMismatch > 0) $financialIssues[] = "{$ledgerMismatch} account(s) whose balance disagrees with their own ledger";

    $checks['financial-consistency'] = $financialIssues === []
        ? ['status' => 'PASS', 'exit_code' => 0, 'summary' => 'no negative balances, no duplicate payment credits, ledger-derived balances match']
        : ['status' => 'FAIL', 'exit_code' => 1, 'summary' => implode('; ', $financialIssues)];
} catch (Throwable $e) {
    $checks['financial-consistency'] = ['status' => 'FAIL', 'exit_code' => 1, 'summary' => "financial consistency check errored: {$e->getMessage()}"];
}

// ---- Step 5: representative cross-table query/action -- proves the schema is not just present
// but actually functionally wired (real joins across independently-owned tables), not merely that
// individual tables exist. Executes regardless of whether the source had any rows in them.
$representativeError = null;
try {
    $restored = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$targetDb};charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $restored->query(
        'SELECT o.id, m.user_id, wa.available_balance, wt.id AS txn_id
         FROM ellsms_organizations o
         LEFT JOIN ellsms_organization_memberships m ON m.organization_id = o.id
         LEFT JOIN ellsms_wallet_accounts wa ON wa.user_id = m.user_id
         LEFT JOIN ellsms_wallet_transactions wt ON wt.user_id = wa.user_id
         LIMIT 5'
    )->fetchAll();
    $restored->query(
        'SELECT t.id, r.id AS reply_id, p.id AS payment_id
         FROM ellsms_tickets t
         LEFT JOIN ellsms_ticket_replies r ON r.ticket_id = t.id
         LEFT JOIN ellsms_payments p ON p.user_id = t.user_id
         LIMIT 5'
    )->fetchAll();
} catch (Throwable $e) {
    $representativeError = $e->getMessage();
}
$checks['representative-query'] = $representativeError === null
    ? ['status' => 'PASS', 'exit_code' => 0, 'summary' => 'cross-table joins across org/membership/wallet/ticket/payment tables executed successfully']
    : ['status' => 'FAIL', 'exit_code' => 1, 'summary' => "representative query failed: {$representativeError}"];

// ---- Verdict ----
$overall = 'PASS';
foreach ($checks as $r) {
    if ($r['status'] === 'FAIL') { $overall = 'FAIL'; break; }
}
$elapsedSeconds = round(microtime(true) - $startedAt, 2);

Logger::info('restore_test.completed', ['backup_id' => $backupId, 'target_db' => $targetDb, 'overall' => $overall, 'elapsed_seconds' => $elapsedSeconds, 'kept' => $keep]);

$publicChecks = array_map(static fn($r) => ['status' => $r['status'], 'exit_code' => $r['exit_code'], 'summary' => $r['summary']], $checks);
if ($json) {
    echo json_encode(['status' => $overall, 'backup_id' => $backupId, 'target_db' => $targetDb, 'elapsed_seconds' => $elapsedSeconds, 'kept' => $keep, 'checks' => $publicChecks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS restore test — backup {$backupId} -> {$targetDb}\n\n";
    foreach ($publicChecks as $name => $r) {
        echo sprintf("  [%-4s] %-24s %s\n", $r['status'], $name, $r['summary']);
    }
    echo "\nOverall: {$overall} ({$elapsedSeconds}s" . ($keep ? ", database kept: {$targetDb}" : ', disposable database dropped') . ")\n";
}
exit($overall === 'FAIL' ? 1 : 0);
