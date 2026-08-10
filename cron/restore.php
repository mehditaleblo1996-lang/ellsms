<?php
/**
 * ELLSMS — restore a backup (Phase 11, STEP 5/STEP 36).
 *
 * Two distinct modes, deliberately asymmetric in how much friction they require (Invariant C/D):
 *
 *   1. DEFAULT / SAFE: no --target-db given, or --target-db names a database that doesn't exist yet
 *      or exists but is empty (0 tables). Nothing is overwritten -- the database is created fresh
 *      and the dump is loaded into it. This is the path `restore-test`/`dr-drill` use.
 *
 *   2. DESTRUCTIVE / REPLACEMENT: --target-db names a database that already has at least one table.
 *      The existing database is DROPPED and recreated from the backup. Requires ALL of:
 *        - ALLOW_DESTRUCTIVE_RESTORE=1 in the environment (not a CLI flag -- an env var is harder
 *          to fat-finger into a shell history / copy-pasted command than a flag would be)
 *        - --confirm=<target-db-name> matching --target-db exactly (typo guard: a wrong database
 *          name is the single most damaging mistake this command could make)
 *      If APP_ENV=production, the confirm requirement applies even when ALLOW_DESTRUCTIVE_RESTORE=1
 *      is set for an unrelated reason (e.g. left set in a shared shell) -- STEP 5's "fail closed on
 *      ambiguous environment configuration" (Invariant K).
 *
 * This command is CLI-only by design (STEP 36) -- nothing under public/ calls into it, and it is
 * never wired to any web-reachable admin action.
 *
 * Usage:
 *   php cron/restore.php <backup_id> [--target-db=NAME] [--json]
 *   ALLOW_DESTRUCTIVE_RESTORE=1 php cron/restore.php <backup_id> --target-db=NAME --confirm=NAME
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$args = $argv ?? [];
$json = in_array('--json', $args, true);
if ($json) {
    Logger::setCliMirror(false);
}

$positional = null;
$targetDbArg = null;
$confirmArg = null;
foreach (array_slice($args, 1) as $a) {
    if ($a === '--json') continue;
    if (str_starts_with($a, '--target-db=')) { $targetDbArg = substr($a, 12); continue; }
    if (str_starts_with($a, '--confirm=')) { $confirmArg = substr($a, 10); continue; }
    if ($positional === null) { $positional = $a; continue; }
}
$backupId = $positional ?? '';

function restore_fail(bool $json, string $message, array $tempFiles = []): never {
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

if ($backupId === '') {
    restore_fail($json, 'usage: php cron/restore.php <backup_id> [--target-db=NAME] [--json] (destructive replacement additionally needs ALLOW_DESTRUCTIVE_RESTORE=1 and --confirm=NAME)');
}

$dbHost = (string)env('BACKEND_DB_HOST', '');
$dbPort = (string)env('BACKEND_DB_PORT', '3306');
$configuredDbName = (string)env('BACKEND_DB_NAME', '');
$dbUser = (string)env('BACKEND_DB_USER', '');
$dbPass = (string)env('BACKEND_DB_PASS', '');
if ($dbHost === '' || $dbUser === '') {
    restore_fail($json, 'BACKEND_DB_HOST/USER must be set (see `make config-check`)');
}

$baseDir = backup_dir();
try {
    $workDir = backup_safe_path($baseDir, $backupId);
} catch (Throwable $e) {
    restore_fail($json, $e->getMessage());
}
if (!is_dir($workDir)) {
    restore_fail($json, "no backup found with id {$backupId} in {$baseDir}");
}

try {
    $manifest = backup_read_manifest($workDir); // throws on unsupported format_version -- STEP 5
} catch (Throwable $e) {
    restore_fail($json, "manifest error: {$e->getMessage()}");
}

$verifyError = backup_verify_artifact($workDir, $manifest);
if ($verifyError !== null) {
    restore_fail($json, "refusing to restore a backup that failed verification: {$verifyError}");
}

// Determine target database name and whether this is a destructive replacement.
if ($targetDbArg !== null) {
    $targetDb = $targetDbArg;
} else {
    $sourceDb = (string)($manifest['database_name'] ?? 'ellsms');
    $targetDb = substr($sourceDb . '_restore_' . str_replace('-', '', $backupId), 0, 64);
}
if (!restore_valid_db_identifier($targetDb)) {
    restore_fail($json, "target database name is not a valid identifier: {$targetDb}");
}

// A server-level connection (no dbname) -- restore must be able to CREATE/DROP the target
// database itself, which db()'s connection (bound to BACKEND_DB_NAME) can't do cleanly, and
// restoring into a brand-new disposable name must not require BACKEND_DB_NAME to already exist.
try {
    $server = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    restore_fail($json, 'could not connect to the database server (credentials/host not printed)');
}

$gotLock = (bool)$server->query("SELECT GET_LOCK('ellsms_backup', 10)")->fetchColumn();
if (!$gotLock) {
    restore_fail($json, 'could not acquire the backup/restore lock within 10s — a backup or another restore is likely already running');
}
register_shutdown_function(static function () use ($server): void {
    try { $server->query("SELECT RELEASE_LOCK('ellsms_backup')"); } catch (Throwable $e) {}
});

$stmt = $server->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
$stmt->execute([$targetDb]);
$existingTableCount = (int)$stmt->fetchColumn();
$destructive = $existingTableCount > 0;

$appEnv = (string)env('APP_ENV', '');
if ($destructive) {
    if (env('ALLOW_DESTRUCTIVE_RESTORE', '0') !== '1') {
        restore_fail($json, "target database \"{$targetDb}\" already has {$existingTableCount} table(s) — refusing to overwrite it. Set ALLOW_DESTRUCTIVE_RESTORE=1 and pass --confirm={$targetDb} to proceed, or restore without --target-db to create a fresh disposable database instead.");
    }
    // Invariant D/K: an env var alone is not enough for a destructive replacement -- the exact
    // target name must also be typed back, and in a production-flagged environment this is
    // required unconditionally (never skippable just because the flag happens to already be set).
    if ($confirmArg !== $targetDb) {
        restore_fail($json, "destructive restore requires --confirm={$targetDb} (must match --target-db exactly) — refusing without it");
    }
    if ($appEnv === 'production' && $targetDb === $configuredDbName) {
        Logger::warning('restore.destructive_production_target', ['target_db' => $targetDb, 'backup_id' => $backupId]);
    }
}

// ---- Decrypt / decompress the artifact to a plaintext temp file ----
$artifactPath = $workDir . '/' . $manifest['artifact_filename'];
$plainPath = $artifactPath;
$tempFiles = [];

if (($manifest['encryption'] ?? 'none') === 'gpg-aes256') {
    $keyFile = backup_encryption_key_file();
    if ($keyFile === '') {
        restore_fail($json, 'backup is encrypted but BACKUP_ENCRYPTION_KEY_FILE is not set — cannot restore');
    }
    $decrypted = tempnam(sys_get_temp_dir(), 'ellsms_restore_');
    chmod($decrypted, 0600);
    [$exit, , $err] = backup_run(['gpg', '--batch', '--yes', '--decrypt', '--passphrase-file', $keyFile, '--output', $decrypted, $artifactPath]);
    if ($exit !== 0) {
        restore_fail($json, 'decryption failed — wrong key or corrupt artifact: ' . mb_strimwidth($err, 0, 200, '…'), [$decrypted]);
    }
    $tempFiles[] = $decrypted;
    $plainPath = $decrypted;
}
if (($manifest['compression'] ?? 'none') === 'gzip') {
    $decompressed = tempnam(sys_get_temp_dir(), 'ellsms_restore_');
    chmod($decompressed, 0600);
    [$exit] = backup_run(['gzip', '-dc', $plainPath], null, $decompressed);
    if ($exit !== 0) {
        restore_fail($json, 'decompression failed — corrupt gzip artifact', array_merge($tempFiles, [$decompressed]));
    }
    $tempFiles[] = $decompressed;
    $plainPath = $decompressed;
}
// register_shutdown_function, not try/finally -- every failure path below calls restore_fail(),
// which exit()s directly, and exit() from inside a try does not run the enclosing finally (the
// same lesson learned the hard way in cron/backup.php's credentials-file cleanup, STEP 6/37: a
// decrypted plaintext dump left behind here would be strictly worse than that was).
register_shutdown_function(static function () use ($tempFiles): void {
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
});

$credFile = backup_write_credentials_file($dbHost, $dbPort, '', $dbUser, $dbPass);
register_shutdown_function(static function () use ($credFile): void {
    @unlink($credFile);
});

$startedAt = microtime(true);

if ($destructive) {
    $server->exec('DROP DATABASE `' . $targetDb . '`');
    Logger::warning('restore.dropped_database', ['target_db' => $targetDb, 'backup_id' => $backupId]);
}
$server->exec('CREATE DATABASE IF NOT EXISTS `' . $targetDb . '` CHARACTER SET utf8mb4');

[$loadExit, , $loadErr] = backup_run([
    'mysql',
    '--defaults-extra-file=' . $credFile,
    $targetDb,
], $plainPath);

if ($loadExit !== 0) {
    Logger::error('restore.load_failed', ['exit_code' => $loadExit, 'stderr' => mb_strimwidth($loadErr, 0, 500, '…'), 'target_db' => $targetDb]);
    restore_fail($json, "mysql load into \"{$targetDb}\" exited {$loadExit}");
}

$elapsedSeconds = round(microtime(true) - $startedAt, 2);

// Best-effort, informational only -- restore-test.php is the tool that turns this into a hard
// pass/fail via the full integrity-check suite.
$migrationHead = null;
try {
    $tstmt = $server->prepare('SELECT version FROM `' . $targetDb . '`.ellsms_schema_migrations ORDER BY id DESC LIMIT 1');
    $tstmt->execute();
    $migrationHead = $tstmt->fetchColumn() ?: null;
} catch (Throwable $e) {}

Logger::info('restore.completed', [
    'backup_id' => $backupId, 'target_db' => $targetDb, 'destructive' => $destructive,
    'elapsed_seconds' => $elapsedSeconds, 'migration_head' => $migrationHead,
]);

$result = [
    'status' => 'OK',
    'backup_id' => $backupId,
    'target_db' => $targetDb,
    'destructive' => $destructive,
    'elapsed_seconds' => $elapsedSeconds,
    'migration_head' => $migrationHead,
];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "OK — restored {$backupId} into \"{$targetDb}\" (" . ($destructive ? 'destructive replacement' : 'fresh database') . ", {$elapsedSeconds}s)\n";
    echo "NOTE: this command only proves the data loaded. Run \`make restore-test BACKUP={$backupId}\` for a validated end-to-end restore (migration status + integrity checks).\n";
}
exit(0);
