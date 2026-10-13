<?php
/**
 * ELLSMS — off-server copy of the backups (docs/backup-and-disaster-recovery.md, "Off-server copy").
 *
 * Copies every finished backup directory (one with a manifest.json) under BACKUP_DIR to the rclone
 * destination BACKUP_RCLONE_REMOTE — any rclone remote: S3/ArvanCloud/MinIO, SFTP, Google Drive...
 * — then deletes from there what is older than BACKUP_OFFSITE_RETENTION_DAYS. rclone copies only
 * what the destination doesn't already have, so a run after the nightly backup sends one backup.
 * Does nothing (exit 0) while BACKUP_RCLONE_REMOTE is empty.
 *
 * rclone reads its remotes from RCLONE_CONFIG (storage/rclone/rclone.conf by default, see
 * docker-compose.yml) or from RCLONE_CONFIG_<NAME>_* environment variables.
 *
 * Usage:
 *   php cron/backup-offsite.php
 *   php cron/backup-offsite.php --dry-run
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$remote = backup_rclone_remote();
if ($remote === '') {
    echo "Off-server copy is off (BACKUP_RCLONE_REMOTE is empty) — backups stay on this server only.\n";
    exit(0);
}
if (!backup_encryption_enabled()) {
    Logger::warning('backup.offsite.unencrypted', ['remote' => $remote]);
    echo "WARNING: backups are copied off the server unencrypted — set BACKUP_ENCRYPTION_ENABLED=1.\n";
}

$baseDir = backup_dir();
$finished = [];
foreach (backup_list($baseDir) as $backup) {
    if (empty($backup['corrupt'])) {
        $finished[] = (string)$backup['backup_id'];
    }
}
if ($finished === []) {
    echo "No finished backup in {$baseDir} to copy.\n";
    exit(0);
}

// Only finished backups: a directory still being written has no manifest.json yet.
$filterFile = tempnam(sys_get_temp_dir(), 'ellsms_offsite_');
file_put_contents($filterFile, implode("\n", array_map(static fn(string $id): string => '+ /' . $id . '/**', $finished)) . "\n- **\n");
register_shutdown_function(static fn() => @unlink($filterFile));

$common = $dryRun ? ['--dry-run'] : [];
[$exit, $out, $err] = backup_run(array_merge(['rclone', 'copy', $baseDir, $remote, '--filter-from', $filterFile], $common));
echo $out . $err;
if ($exit !== 0) {
    Logger::error('backup.offsite.copy_failed', ['remote' => $remote, 'exit_code' => $exit, 'stderr' => mb_strimwidth($err, 0, 500, '…')]);
    fwrite(STDERR, "FAIL: rclone copy exited {$exit}\n");
    exit(1);
}

$retentionDays = backup_offsite_retention_days();
if ($retentionDays > 0) {
    [$exit, $out, $err] = backup_run(array_merge(['rclone', 'delete', $remote, '--min-age', $retentionDays . 'd', '--rmdirs'], $common));
    echo $out . $err;
    if ($exit !== 0) {
        Logger::error('backup.offsite.prune_failed', ['remote' => $remote, 'exit_code' => $exit, 'stderr' => mb_strimwidth($err, 0, 500, '…')]);
        fwrite(STDERR, "FAIL: rclone delete exited {$exit}\n");
        exit(1);
    }
}

Logger::info('backup.offsite.copied', ['remote' => $remote, 'backups' => count($finished), 'dry_run' => $dryRun]);
echo ($dryRun ? '[dry-run] ' : '') . 'OK — ' . count($finished) . " backup(s) in sync with {$remote}" . ($retentionDays > 0 ? ", older than {$retentionDays} day(s) removed there" : '') . ".\n";
exit(0);
