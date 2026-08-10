<?php
/**
 * ELLSMS — production backup command (Phase 11, STEP 4).
 *
 * Consistent logical backup of the entire configured database (`BACKEND_DB_NAME`) via
 * `mysqldump --single-transaction` — a real InnoDB-consistent snapshot without holding any
 * application-level transaction open (Invariant B/H). See app/Backup.php's own docblock for why
 * this is a whole-database backup, not an ellsms_*-only allowlist.
 *
 * Success is NEVER declared from mysqldump's exit code alone (Invariant B) — the dump file is also
 * checked for mysqldump's own "-- Dump completed on" trailer AND at least one `CREATE TABLE`
 * statement before being treated as valid.
 *
 * Usage:
 *   php cron/backup.php
 *   php cron/backup.php --dry-run
 *   php cron/backup.php --json
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);
if ($json) {
    // Keep stdout pure JSON -- see Logger::setCliMirror()'s docblock.
    Logger::setCliMirror(false);
}

function backup_fail(bool $json, string $message, ?string $workDir = null): never {
    if ($workDir !== null && is_dir($workDir)) {
        // Invariant B/STEP 4: clean partial files on failure -- never leave a half-written backup
        // directory that a naive `ls storage/backups` would mistake for a real, restorable one.
        backup_rmrf($workDir);
    }
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

$dbHost = (string)env('BACKEND_DB_HOST', '');
$dbPort = (string)env('BACKEND_DB_PORT', '3306');
$dbName = (string)env('BACKEND_DB_NAME', '');
$dbUser = (string)env('BACKEND_DB_USER', '');
$dbPass = (string)env('BACKEND_DB_PASS', '');
if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    backup_fail($json, 'BACKEND_DB_HOST/NAME/USER must be set (see `make config-check`)');
}

$encryptionError = backup_validate_encryption_config();
if ($encryptionError !== null) {
    backup_fail($json, "encryption configuration invalid: {$encryptionError}");
}

if ($dryRun) {
    $plan = [
        'status' => 'DRY_RUN',
        'database' => $dbName,
        'backup_dir' => backup_dir(),
        'compression' => backup_compression_enabled() ? 'gzip' : 'none',
        'encryption' => backup_encryption_enabled() ? 'gpg-aes256' : 'none',
        'retention_days' => backup_retention_days(),
    ];
    echo $json ? json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
               : "DRY RUN — would create a backup of \"{$dbName}\" in " . backup_dir()
                 . ' (compression=' . $plan['compression'] . ', encryption=' . $plan['encryption'] . ")\n";
    exit(0);
}

$db = db();

// STEP 18: serialized so two operators/schedulers can never write conflicting artifacts under the
// same backup id race, and so retention pruning (cron/backup-prune.php) never sees a half-written
// directory (it takes the same lock before touching anything).
$gotLock = (bool)$db->query("SELECT GET_LOCK('ellsms_backup', 10)")->fetchColumn();
if (!$gotLock) {
    backup_fail($json, 'could not acquire the backup lock within 10s — another backup is likely already running');
}
register_shutdown_function(static function () use ($db): void {
    try { $db->query("SELECT RELEASE_LOCK('ellsms_backup')"); } catch (Throwable $e) {}
});

// STEP 18 (backup-vs-migration interaction): also serialized against cron/db-migrate.php --apply's
// own 'ellsms_db_migrate_apply' lock. mysqldump --single-transaction gives InnoDB-consistent DATA,
// but DDL (ALTER TABLE, the actual content of a migration) auto-commits outside any transaction --
// a schema change landing mid-dump could leave the dump internally inconsistent across tables in a
// way --single-transaction cannot protect against. Taking this second lock means a backup can
// never start while a migration is applying, and vice versa (GET_LOCK blocks the other side until
// this one releases, rather than either silently proceeding into the other's window).
$gotMigrationLock = (bool)$db->query("SELECT GET_LOCK('ellsms_db_migrate_apply', 10)")->fetchColumn();
if (!$gotMigrationLock) {
    backup_fail($json, 'could not acquire the migration lock within 10s — a migration (db-migrate.php --apply) is likely in progress; never back up mid-migration');
}
register_shutdown_function(static function () use ($db): void {
    try { $db->query("SELECT RELEASE_LOCK('ellsms_db_migrate_apply')"); } catch (Throwable $e) {}
});

$backupId = backup_generate_id();
$baseDir = backup_dir();
$workDir = $baseDir . '/' . $backupId;
if (!is_dir($baseDir) && !@mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
    backup_fail($json, "could not create backup directory: {$baseDir}");
}
if (!@mkdir($workDir, 0750, true)) {
    backup_fail($json, "could not create backup working directory: {$workDir}");
}

$credFile = backup_write_credentials_file($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
// A shutdown function, not just try/finally below: every failure path in this script calls
// backup_fail(), which exit()s directly -- and exit() called from inside a try block does NOT
// run the enclosing finally (confirmed the hard way: an earlier version of this script left the
// credentials file, containing BACKEND_DB_PASS in plaintext, behind on every single run, since
// the try/finally at the bottom never got a chance to execute). register_shutdown_function()
// runs regardless of how the script ends, including via exit() — this is the actual cleanup
// guarantee; the try/finally below is now redundant-but-harmless belt-and-suspenders for the one
// normal-return path that doesn't exit() early.
register_shutdown_function(static function () use ($credFile): void {
    @unlink($credFile);
});
try {
    $dumpPath = $workDir . '/dump.sql';
    $startedAt = microtime(true);
    [$exitCode, , $stderr] = backup_run([
        'mysqldump',
        '--defaults-extra-file=' . $credFile,
        '--single-transaction',
        '--routines',
        '--events',
        '--triggers',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        $dbName,
    ], null, $dumpPath);

    if ($exitCode !== 0) {
        Logger::error('backup.mysqldump_failed', ['exit_code' => $exitCode, 'stderr' => mb_strimwidth($stderr, 0, 500, '…')]);
        backup_fail($json, "mysqldump exited {$exitCode}", $workDir);
    }

    // Invariant B: exit code alone is not proof of a real, complete dump -- also check the file
    // actually looks like one. mysqldump writes its own completion trailer as the LAST line only
    // when it finished normally (a truncated/killed dump won't have it), and a real schema dump
    // must contain at least one CREATE TABLE.
    $dumpContent = file_get_contents($dumpPath);
    if ($dumpContent === false || filesize($dumpPath) === 0) {
        backup_fail($json, 'dump file is empty or unreadable after mysqldump reported success', $workDir);
    }
    if (!str_contains($dumpContent, '-- Dump completed on')) {
        backup_fail($json, 'dump file is missing mysqldump\'s own completion trailer — treating as incomplete/truncated', $workDir);
    }
    if (!preg_match('/CREATE TABLE/i', $dumpContent)) {
        backup_fail($json, 'dump file contains no CREATE TABLE statements — refusing to treat this as a valid database backup', $workDir);
    }
    unset($dumpContent); // don't hold the whole dump in memory longer than needed for this check

    $artifactPath = $dumpPath;
    $compression = 'none';
    if (backup_compression_enabled()) {
        [$gzExit] = backup_run(['gzip', '-f', $dumpPath]);
        if ($gzExit !== 0 || !is_file($dumpPath . '.gz')) {
            backup_fail($json, 'gzip compression failed', $workDir);
        }
        $artifactPath = $dumpPath . '.gz';
        $compression = 'gzip';
    }

    // STEP 7: compression happens before encryption (already the case above -- gzip runs on the
    // plaintext dump, encryption below runs on its output).
    $encryption = 'none';
    if (backup_encryption_enabled()) {
        $encPath = $artifactPath . '.gpg';
        [$gpgExit, , $gpgErr] = backup_run([
            'gpg', '--batch', '--yes', '--symmetric', '--cipher-algo', 'AES256',
            '--passphrase-file', backup_encryption_key_file(),
            '--output', $encPath,
            $artifactPath,
        ]);
        if ($gpgExit !== 0 || !is_file($encPath)) {
            Logger::error('backup.encryption_failed', ['stderr' => mb_strimwidth($gpgErr, 0, 300, '…')]);
            backup_fail($json, 'backup encryption failed — refusing to leave a plaintext artifact in its place', $workDir);
        }
        @unlink($artifactPath); // the plaintext (possibly compressed) intermediate must not survive
        $artifactPath = $encPath;
        $encryption = 'gpg-aes256';
    }
    chmod($artifactPath, 0640);

    $checksum = backup_sha256_file($artifactPath);
    $elapsedSeconds = round(microtime(true) - $startedAt, 2);

    $migrationHead = null;
    $migrationCount = 0;
    try {
        $rows = $db->query('SELECT version FROM ellsms_schema_migrations ORDER BY id DESC')->fetchAll(PDO::FETCH_COLUMN);
        $migrationCount = count($rows);
        $migrationHead = $rows[0] ?? null;
    } catch (Throwable $e) {
        // Ledger table may not exist yet on a very first backup of a very first install -- not a
        // backup failure, just an absent piece of metadata.
    }

    $mysqlVersion = null;
    try {
        $mysqlVersion = (string)$db->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) {}

    $manifest = [
        'backup_id'        => $backupId,
        'format_version'   => BACKUP_FORMAT_VERSION,
        'created_at'       => gmdate('c'),
        'app_version'      => app_version(),
        'database_name'    => $dbName,
        'mysql_version'    => $mysqlVersion,
        'migration_head'   => $migrationHead,
        'migration_count'  => $migrationCount,
        'table_scope'      => 'complete_database',
        'compression'      => $compression,
        'encryption'       => $encryption,
        'artifact_filename' => basename($artifactPath),
        'artifact_sha256'  => $checksum,
        'artifact_bytes'   => filesize($artifactPath),
        'dump_elapsed_seconds' => $elapsedSeconds,
        'hostname'         => gethostname() ?: 'unknown',
    ];

    // Verified BEFORE the manifest is ever written to disk, not after -- STEP 8/34: a manifest.json
    // that exists at all must be able to honestly claim whether Invariant B's post-creation check
    // ran and passed, rather than callers (cron/backup-status.php) having to infer it indirectly
    // from "well, the directory wasn't deleted, so it must have passed."
    $verifiedAtCreation = false;
    if (backup_verify_after_create()) {
        $verifyResult = backup_verify_artifact($workDir, $manifest);
        if ($verifyResult !== null) {
            backup_fail($json, "post-creation verification failed: {$verifyResult}", $workDir);
        }
        $verifiedAtCreation = true;
    }
    $manifest['verified_at_creation'] = $verifiedAtCreation;
    backup_write_manifest($workDir, $manifest);

    Logger::info('backup.created', [
        'backup_id' => $backupId, 'bytes' => $manifest['artifact_bytes'],
        'compression' => $compression, 'encryption' => $encryption,
        'elapsed_seconds' => $elapsedSeconds, 'migration_head' => $migrationHead,
    ]);

    if ($json) {
        echo json_encode(['status' => 'OK', 'manifest' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "OK — backup {$backupId} created (" . number_format($manifest['artifact_bytes']) . " bytes, "
            . "compression={$compression}, encryption={$encryption}, {$elapsedSeconds}s)\n";
    }
    exit(0);
} finally {
    @unlink($credFile);
}
