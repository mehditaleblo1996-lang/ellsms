<?php
/**
 * ELLSMS — stuck-job / expired-lease visibility & recovery (Phase 4, STEP 19).
 *
 * Every claim query in app/backend.php already self-heals: a normal worker tick automatically
 * reclaims any row whose lease has expired (bulk items, schedules, auto-reply log) — see
 * docs/job-queue-architecture.md. This script exists for OPERATOR VISIBILITY (see what's currently
 * stuck without waiting for or reading through worker logs) and, with --force, makes expired-lease
 * rows immediately reclaimable rather than waiting for a lease that already expired to be noticed
 * by the next organic tick. It never touches a row whose lease is still valid — an actively-owned
 * row is always left alone, forced or not.
 *
 * Usage:
 *   php cron/jobs-recover.php              # report only, changes nothing
 *   php cron/jobs-recover.php --force      # also clear expired leases so the next tick reclaims them
 *   php cron/jobs-recover.php --force --dry-run   # show what --force would do, without doing it
 */
require_once __DIR__ . '/../app/backend.php';

$force  = in_array('--force', $argv ?? [], true);
$dryRun = in_array('--dry-run', $argv ?? [], true);

$db = db();

// (label, table, primary key column) — every target uses the identical
// "status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()" shape that
// app/backend.php's own reclaim queries check, so this script's report is exactly "what the next
// tick would reclaim on its own," nothing more.
$targets = [
    ['bulk_items', 'ellsms_bulk_items',   'id'],
    ['schedules',  'ellsms_schedule',     'id'],
    ['autoreply',  'ellsms_autoreply_log', 'id'],
];

$totalStuck = 0;
foreach ($targets as [$label, $table, $pk]) {
    $rows = $db->query(
        "SELECT {$pk} AS id, claimed_by, lease_expires_at, attempt_count
         FROM {$table}
         WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()
         ORDER BY lease_expires_at ASC"
    )->fetchAll();

    $count = count($rows);
    $totalStuck += $count;
    echo "{$label}: {$count} row(s) with an expired lease\n";
    foreach ($rows as $r) {
        echo "  #{$r['id']}  claimed_by={$r['claimed_by']}  lease_expired_at={$r['lease_expires_at']}  attempts={$r['attempt_count']}\n";
    }

    if ($force && $count > 0) {
        if ($dryRun) {
            echo "  -> would clear " . $count . " lease(s) (dry run, nothing changed)\n";
            continue;
        }
        // Set to NOW() (already "expired" the instant this commits), not
        // NULL — the reclaim queries in app/backend.php require
        // lease_expires_at IS NOT NULL AND < NOW() to treat a 'processing'
        // row as reclaimable; NULL would mean "not actively claimed" and
        // fall outside that check entirely, making the row unreclaimable
        // by the normal path instead of immediately reclaimable.
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE {$table} SET lease_expires_at = NOW() WHERE {$pk} IN ({$ph})")->execute($ids);
        echo "  -> cleared, immediately reclaimable on the next tick\n";
    }
}

Logger::info('jobs.recover.finished', ['stuck_count' => $totalStuck, 'forced' => $force, 'dry_run' => $dryRun]);
echo "\nTotal stuck: {$totalStuck}" . ($dryRun ? ' (dry run — nothing changed)' : '') . "\n";
