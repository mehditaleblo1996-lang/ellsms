<?php
/**
 * ELLSMS — backup/DR monitoring snapshot (Phase 11, STEP 34).
 *
 * Read-only, no locking needed (a snapshot doesn't need to block a concurrent backup/restore/
 * prune -- it's fine if the picture is a moment stale). Reports latest backup age/size/id,
 * verification status, retention posture, and the last DR-drill result -- never a filesystem path
 * or any secret. Exit code is non-zero if there is no valid backup at all, or the newest one looks
 * unverified/stale, so this doubles as a cheap monitoring check (`make backup-status`, cron/alerting).
 *
 * Usage:
 *   php cron/backup-status.php
 *   php cron/backup-status.php --json
 */
require_once __DIR__ . '/../app/backend.php';
require_once __DIR__ . '/../app/Backup.php';

$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$baseDir = backup_dir();
$all = backup_list($baseDir);
$valid = array_values(array_filter($all, static fn($m) => empty($m['corrupt'])));
$corruptCount = count($all) - count($valid);

$latest = $valid[0] ?? null;
$status = 'FAIL';
$notes = [];

if ($latest === null) {
    $notes[] = 'no valid backup found';
} else {
    $ageSeconds = time() - (strtotime((string)$latest['created_at']) ?: time());
    $ageHours = round($ageSeconds / 3600, 1);
    if (empty($latest['verified_at_creation'])) {
        $notes[] = 'latest backup was not verified at creation time (BACKUP_VERIFY_AFTER_CREATE was off)';
    }
    // "Stale" is intentionally the same BACKUP_RETENTION_DAYS window backup-prune already uses as
    // its own age policy, not a separate invented threshold -- one operator-configured number,
    // not two that could disagree.
    $retentionDays = backup_retention_days();
    if ($ageSeconds > $retentionDays * 86400) {
        $notes[] = "latest backup is {$ageHours}h old, older than BACKUP_RETENTION_DAYS={$retentionDays}";
    }
    $status = $notes === [] ? 'OK' : (empty($latest['verified_at_creation']) ? 'FAIL' : 'WARN');
}

$pruneDecisions = backup_prune_decisions($all, backup_retention_min_count(), backup_retention_days(), true);
$wouldPruneCount = count(array_filter($pruneDecisions, static fn($d) => $d['action'] === 'would_delete'));

$drDrillFile = dr_drill_status_file();
$drDrill = null;
if (is_file($drDrillFile)) {
    $decoded = json_decode((string)file_get_contents($drDrillFile), true);
    if (is_array($decoded)) {
        // Only the fields relevant to an operator glance -- never the full drill log, which could
        // in principle contain more detail than belongs in a routine status check's output.
        $drDrill = [
            'status' => $decoded['status'] ?? 'UNKNOWN',
            'ran_at' => $decoded['ran_at'] ?? null,
            'elapsed_seconds' => $decoded['elapsed_seconds'] ?? null,
        ];
    }
}
if ($drDrill === null) {
    $notes[] = 'no DR drill has ever been recorded — see `make dr-drill`';
}

// Best-effort, today-only: failed backup ATTEMPTS never leave a directory behind (STEP 4 cleans
// partial files on failure), so the only trace of one is the structured log -- this is explicitly
// NOT a claim of complete historical failure tracking, just what's visible in today's log file.
$failedToday = 0;
$logFile = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__)) . '/storage/logs/ellsms-' . date('Y-m-d') . '.log';
if (is_file($logFile)) {
    $handle = fopen($logFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (str_contains($line, 'backup.mysqldump_failed') || str_contains($line, 'backup.encryption_failed')) {
                $failedToday++;
            }
        }
        fclose($handle);
    }
}

$result = [
    'status' => $status,
    'latest_backup' => $latest === null ? null : [
        'backup_id' => $latest['backup_id'],
        'created_at' => $latest['created_at'],
        'age_hours' => $ageHours ?? null,
        'bytes' => $latest['artifact_bytes'] ?? null,
        'compression' => $latest['compression'] ?? null,
        'encryption' => $latest['encryption'] ?? null,
        'verified_at_creation' => $latest['verified_at_creation'] ?? false,
    ],
    'valid_backup_count' => count($valid),
    'corrupt_backup_count' => $corruptCount,
    'failed_backup_attempts_today' => $failedToday,
    'retention' => [
        'retention_days' => backup_retention_days(),
        'retention_min_count' => backup_retention_min_count(),
        'would_prune_count' => $wouldPruneCount,
    ],
    'last_dr_drill' => $drDrill,
    'notes' => $notes,
];

Logger::info('backup.status.checked', ['status' => $status, 'valid_backup_count' => count($valid), 'latest_backup_id' => $latest['backup_id'] ?? null]);

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "ELLSMS backup status: {$status}\n\n";
    if ($latest !== null) {
        echo "  Latest backup:      {$latest['backup_id']}\n";
        echo "  Created:            {$latest['created_at']} ({$result['latest_backup']['age_hours']}h ago)\n";
        echo "  Size:               " . number_format((int)$latest['artifact_bytes']) . " bytes\n";
        echo "  Compression:        " . ($latest['compression'] ?? 'unknown') . "\n";
        echo "  Encryption:         " . ($latest['encryption'] ?? 'unknown') . "\n";
        echo "  Verified at create: " . (($latest['verified_at_creation'] ?? false) ? 'yes' : 'no') . "\n";
    } else {
        echo "  No valid backup found.\n";
    }
    echo "\n  Valid backups:       " . count($valid) . "\n";
    echo "  Corrupt entries:     {$corruptCount}\n";
    echo "  Failed today (log):  {$failedToday}\n";
    echo "  Would prune now:     {$wouldPruneCount} (retention_days=" . backup_retention_days() . ", min_count=" . backup_retention_min_count() . ")\n";
    echo "  Last DR drill:       " . ($drDrill !== null ? "{$drDrill['status']} at {$drDrill['ran_at']}" : 'never run') . "\n";
    if ($notes !== []) {
        echo "\n  Notes:\n";
        foreach ($notes as $note) {
            echo "    - {$note}\n";
        }
    }
}
exit($status === 'FAIL' ? 1 : 0);
