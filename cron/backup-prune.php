<?php
/**
 * ELLSMS — backup retention cleanup (Phase 11, STEP 16).
 *
 * Policy: keep the newest BACKUP_RETENTION_MIN_COUNT valid backups (default 1) unconditionally,
 * regardless of age -- Invariant G, "retention cleanup must not delete the newest valid backup".
 * Among everything else, keep anything younger than BACKUP_RETENTION_DAYS (default 14); delete
 * the rest. A backup directory with a missing/corrupt manifest is never auto-deleted -- reported
 * separately so an operator can investigate, since guessing "this looks broken, discard it" could
 * just as easily destroy forensic evidence of what went wrong as clean up genuine debris.
 *
 * Serialized against cron/backup.php and cron/restore.php via the same ellsms_backup MySQL named
 * lock, so retention can never race a backup that's still being written (a directory without a
 * manifest.json yet is invisible to backup_list() in the first place, but the lock also prevents
 * less obvious overlap, e.g. deleting while a concurrent verify is mid-decrypt).
 *
 * Usage:
 *   php cron/backup-prune.php --dry-run
 *   php cron/backup-prune.php
 *   php cron/backup-prune.php --json
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

function prune_fail(bool $json, string $message): never {
    if ($json) {
        echo json_encode(['status' => 'FAIL', 'error' => $message], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "FAIL: {$message}\n");
    }
    exit(1);
}

$baseDir = backup_dir();
$db = db();
$gotLock = (bool)$db->query("SELECT GET_LOCK('ellsms_backup', 10)")->fetchColumn();
if (!$gotLock) {
    prune_fail($json, 'could not acquire the backup lock within 10s — a backup or restore is likely already running');
}
register_shutdown_function(static function () use ($db): void {
    try { $db->query("SELECT RELEASE_LOCK('ellsms_backup')"); } catch (Throwable $e) {}
});

$all = backup_list($baseDir); // newest-first, corrupt entries flagged with 'corrupt' => true
$corrupt = array_values(array_filter($all, static fn($m) => !empty($m['corrupt'])));

$minCount = backup_retention_min_count();
$retentionDays = backup_retention_days();
$decisions = backup_prune_decisions($all, $minCount, $retentionDays, $dryRun);

$valid = array_values(array_filter($all, static fn($m) => empty($m['corrupt'])));
$deletedIds = [];
if (!$dryRun) {
    foreach ($decisions as $d) {
        if ($d['action'] !== 'deleted') continue;
        try {
            $path = backup_safe_path($baseDir, $d['backup_id']); // rejects symlink/traversal escapes
        } catch (Throwable $e) {
            // Never happens for an id backup_list() itself just enumerated from $baseDir, but fail
            // closed rather than deleting anything if it somehow did.
            continue;
        }
        backup_rmrf($path);
        $deletedIds[] = $d['backup_id'];
    }
}

Logger::info('backup.prune.completed', [
    'dry_run' => $dryRun, 'kept' => count(array_filter($decisions, static fn($d) => $d['action'] === 'keep')),
    'deleted' => $deletedIds, 'skipped_corrupt' => count($corrupt),
]);

if ($json) {
    echo json_encode(['status' => 'OK', 'dry_run' => $dryRun, 'decisions' => $decisions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS backup retention" . ($dryRun ? ' (DRY RUN — nothing will be deleted)' : '') . "\n\n";
    foreach ($decisions as $d) {
        echo sprintf("  [%-13s] %-28s %s\n", strtoupper($d['action']), $d['backup_id'], $d['reason']);
    }
    $deleteCount = count(array_filter($decisions, static fn($d) => in_array($d['action'], ['deleted', 'would_delete'], true)));
    echo "\n" . count($valid) . " valid backup(s), {$deleteCount} " . ($dryRun ? 'would be deleted' : 'deleted') . ", " . count($corrupt) . " corrupt (skipped, not auto-deleted).\n";
}
exit(0);
