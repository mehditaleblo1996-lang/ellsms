<?php
/**
 * ELLSMS — verify an existing backup artifact's integrity (Phase 11, STEP 9).
 *
 * Thin CLI wrapper around app/Backup.php's backup_verify_artifact(): checksum match, successful
 * decrypt/decompress, and a structural check that the decoded content still looks like a real,
 * complete mysqldump. Does NOT restore anything into a database — cron/restore-test.php is the
 * tool that proves a backup is actually restorable end to end (STEP 42's hard criterion). This
 * command only proves the ARTIFACT ITSELF is not corrupt.
 *
 * Usage:
 *   php cron/backup-verify.php <backup_id>
 *   php cron/backup-verify.php <backup_id> --json
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}
$positional = array_values(array_filter(array_slice($argv ?? [], 1), static fn($a) => $a !== '--json'));
$backupId = $positional[0] ?? '';

function backup_verify_cli_fail(bool $json, string $message): never {
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

if ($backupId === '') {
    backup_verify_cli_fail($json, 'usage: php cron/backup-verify.php <backup_id> [--json]');
}

$baseDir = backup_dir();
try {
    $workDir = backup_safe_path($baseDir, $backupId);
} catch (Throwable $e) {
    backup_verify_cli_fail($json, $e->getMessage());
}

if (!is_dir($workDir)) {
    backup_verify_cli_fail($json, "no backup found with id {$backupId} in " . backup_dir());
}

// STEP 18: without this, a concurrent cron/backup-prune.php run could delete this exact backup's
// files mid-read (it's read-only, but the same on-disk directory the prune tool is also allowed
// to remove), producing a false "corrupt" verdict on a backup that was actually fine right up
// until it got pruned out from under this command.
$db = db();
$gotLock = (bool)$db->query("SELECT GET_LOCK('ellsms_backup', 10)")->fetchColumn();
if (!$gotLock) {
    backup_verify_cli_fail($json, 'could not acquire the backup lock within 10s — a backup, restore, or prune is likely already running');
}
register_shutdown_function(static function () use ($db): void {
    try { $db->query("SELECT RELEASE_LOCK('ellsms_backup')"); } catch (Throwable $e) {}
});

try {
    $manifest = backup_read_manifest($workDir);
} catch (Throwable $e) {
    backup_verify_cli_fail($json, "manifest error: {$e->getMessage()}");
}

$result = backup_verify_artifact($workDir, $manifest);

if ($result !== null) {
    Logger::error('backup.verify_failed', ['backup_id' => $backupId, 'error' => $result]);
    backup_verify_cli_fail($json, $result);
}

Logger::info('backup.verified', ['backup_id' => $backupId]);
if ($json) {
    echo json_encode(['status' => 'OK', 'backup_id' => $backupId, 'manifest' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "OK — backup {$backupId} verified (checksum matches, decrypts/decompresses cleanly, looks like a complete mysqldump)\n";
}
exit(0);
