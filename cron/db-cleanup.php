<?php
/**
 * ELLSMS — ephemeral data retention cleanup (Phase 5, STEP 14/21).
 *
 * Deletes ONLY data classified as operationally-ephemeral with zero retrieval value once past its
 * own natural expiry — never financial, payment, or audit records (see docs/database-migrations.md
 * "Data lifecycle" for the full classification). Two targets:
 *
 *   - ellsms_2fa_codes: rows past their own expires_at. A code is single-use and 5-minute-lived;
 *     once expired it has no verification value and no audit value either (docs/database-audit.md
 *     already flagged this table as unbounded/never-pruned).
 *   - ellsms_rate_limits: rows older than 24h. rate_limit_hit() (app/rate_limit.php) already
 *     opportunistically prunes stale rows for a bucket every time that SAME bucket is hit again —
 *     this only catches buckets that simply stopped being hit (e.g. an abandoned/blocked IP),
 *     which would otherwise sit forever with zero remaining relevance to any sliding window.
 *
 * Defaults to DRY RUN — reports exactly what would be deleted, deletes nothing, unless --apply is
 * passed explicitly. Never touches ellsms_audit_log, ellsms_payments, ellsms_wallet_*,
 * ellsms_autoreply_log, or ellsms_ticket_replies — nothing here is a general-purpose "vacuum," it
 * is two specific, named, reviewed targets.
 *
 * Usage:
 *   php cron/db-cleanup.php              # dry run (default) — reports counts, deletes nothing
 *   php cron/db-cleanup.php --apply      # actually deletes
 */
require_once __DIR__ . '/../app/backend.php';

$apply = in_array('--apply', $argv ?? [], true);
$db = db();

$targets = [
    [
        'label' => 'expired ellsms_2fa_codes',
        'count' => "SELECT COUNT(*) FROM ellsms_2fa_codes WHERE expires_at < NOW()",
        'delete' => "DELETE FROM ellsms_2fa_codes WHERE expires_at < NOW()",
    ],
    [
        'label' => 'stale ellsms_rate_limits (older than 24h)',
        'count' => "SELECT COUNT(*) FROM ellsms_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
        'delete' => "DELETE FROM ellsms_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
    ],
];

$totalDeleted = 0;
foreach ($targets as $t) {
    $count = (int)$db->query($t['count'])->fetchColumn();
    if (!$apply) {
        echo "{$t['label']}: {$count} row(s) would be deleted (dry run — nothing changed)\n";
        continue;
    }
    if ($count === 0) {
        echo "{$t['label']}: 0 row(s), nothing to do\n";
        continue;
    }
    $affected = $db->exec($t['delete']);
    $totalDeleted += $affected;
    echo "{$t['label']}: {$affected} row(s) deleted\n";
    Logger::info('db.cleanup.deleted', ['target' => $t['label'], 'count' => $affected]);
}

if ($apply) {
    Logger::info('db.cleanup.finished', ['total_deleted' => $totalDeleted]);
    echo "\nTotal deleted: {$totalDeleted}\n";
} else {
    echo "\nDry run complete — nothing changed. Re-run with --apply to actually delete.\n";
}
