<?php
/**
 * ELLSMS — Idempotency-Key record retention (Phase 12, STEP 55).
 *
 * Thin CLI wrapper around idempotency_prune() (app/Idempotency.php) — deletes only COMPLETED
 * records older than API_IDEMPOTENCY_TTL_HOURS; an in_progress row is never touched here regardless
 * of age (idempotency_begin()'s own staleness reclaim is what handles those, not pruning — deleting
 * one out from under a request that might still be running would defeat the whole lock).
 *
 * Usage:
 *   php cron/idempotency-prune.php --dry-run
 *   php cron/idempotency-prune.php
 */
require_once __DIR__ . '/../app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);
if ($json) {
    Logger::setCliMirror(false);
}

$ttlHours = max(1, (int)(env('API_IDEMPOTENCY_TTL_HOURS', '48') ?? '48'));
$count = idempotency_prune($ttlHours, $dryRun);

if (!$dryRun) {
    Logger::info('idempotency.prune.completed', ['deleted' => $count, 'ttl_hours' => $ttlHours]);
}

$result = ['dry_run' => $dryRun, 'ttl_hours' => $ttlHours, 'completed_records' => $count];
if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo ($dryRun ? "[dry-run] would delete" : "Deleted") . " {$count} completed idempotency record(s) older than {$ttlHours}h.\n";
}
exit(0);
