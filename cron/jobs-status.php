<?php
/**
 * ELLSMS — job queue health visibility (Phase 4, STEP 20/33; extended Phase 9, STEP 8).
 *
 * Read-only counts across every background execution path — no message content, no destination
 * numbers, no secrets, just status/lease/retry counters. Cheap: each query is an indexed
 * status/lease-expiry aggregate, not a full-table scan of message content, so this is safe to run
 * on demand (unlike an addition to /health, which STEP 33 explicitly says to avoid for this reason).
 *
 * Phase 9 additions: oldest-pending-age per queue (how long has the longest-waiting claimable row
 * been waiting — the single most useful "is the queue keeping up" number) and a distinct-worker
 * count currently holding a live claim, both still cheap indexed aggregates. `--json` emits the
 * same data machine-readably for cron/performance-snapshot.php and any future dashboard to consume
 * without re-parsing text output.
 *
 * Usage:
 *   php cron/jobs-status.php
 *   php cron/jobs-status.php --json
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);
$db = db();

/** @return array<string,mixed> one row per status, plus oldest_pending_age_seconds and a running active-worker set. */
function queue_table_status(PDO $db, string $table, string $dueColumn, string $pendingStatus, string $retryWaitCol, ?string $leaseCol = null, ?string $processingStatus = null): array {
    $rows = $db->query(
        "SELECT status, COUNT(*) AS total" .
        ($retryWaitCol ? ", SUM(status='{$pendingStatus}' AND {$retryWaitCol} IS NOT NULL AND {$retryWaitCol} > NOW()) AS retry_wait" : '') .
        ($leaseCol && $processingStatus ? ", SUM(status='{$processingStatus}' AND {$leaseCol} IS NOT NULL AND {$leaseCol} < NOW()) AS expired_lease" : '') .
        " FROM {$table} GROUP BY status ORDER BY status"
    )->fetchAll();

    $oldestAge = $db->query(
        "SELECT TIMESTAMPDIFF(SECOND, MIN({$dueColumn}), NOW()) AS age
         FROM {$table} WHERE status = '{$pendingStatus}' AND {$dueColumn} <= NOW()"
    )->fetch()['age'];

    return ['by_status' => $rows, 'oldest_pending_age_seconds' => $oldestAge !== null ? (int)$oldestAge : null];
}

/** Distinct claimed_by values currently holding a non-expired lease — a cheap proxy for "how many workers are actively claiming right now," not a worker registry (none exists). */
function active_worker_count(PDO $db): int {
    $counts = [
        $db->query("SELECT COUNT(DISTINCT claimed_by) c FROM ellsms_bulk_items WHERE status='processing' AND claimed_by IS NOT NULL AND (lease_expires_at IS NULL OR lease_expires_at >= NOW())")->fetch()['c'],
        $db->query("SELECT COUNT(DISTINCT claimed_by) c FROM ellsms_schedule WHERE status='processing' AND claimed_by IS NOT NULL AND (lease_expires_at IS NULL OR lease_expires_at >= NOW())")->fetch()['c'],
    ];
    // Not a simple sum -- the same worker_id can legitimately hold claims in more than one table
    // at once (one process, three passes per tick) -- but with no cross-table worker registry,
    // a per-table distinct count is the honest, cheap approximation this command can offer; a
    // true unique-worker count across tables isn't derivable from these rows alone.
    return (int)max($counts);
}

$bulkItems = queue_table_status($db, 'ellsms_bulk_items', 'IFNULL(next_attempt_at, created_at)', 'pending', 'next_attempt_at', 'lease_expires_at', 'processing');
$bulkJobs  = $db->query('SELECT status, COUNT(*) AS total FROM ellsms_bulk_jobs GROUP BY status ORDER BY status')->fetchAll();
$schedules = queue_table_status($db, 'ellsms_schedule', 'IFNULL(next_attempt_at, run_at)', 'active', 'next_attempt_at', 'lease_expires_at', 'processing');
$autoreply = $db->query(
    "SELECT status, COUNT(*) AS total,
            SUM(status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()) AS expired_lease
     FROM ellsms_autoreply_log GROUP BY status ORDER BY status"
)->fetchAll();
$activeWorkers = active_worker_count($db);

if ($json) {
    echo json_encode([
        'generated_at'    => date('c'),
        'bulk_items'      => $bulkItems,
        'bulk_jobs'       => $bulkJobs,
        'schedules'       => $schedules,
        'autoreply_log'   => $autoreply,
        'active_workers'  => $activeWorkers,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

function section(string $title): void {
    echo "\n=== {$title} ===\n";
}

section('Bulk items');
if (!$bulkItems['by_status']) echo "  (no rows)\n";
foreach ($bulkItems['by_status'] as $r) {
    $extra = [];
    if ((int)($r['retry_wait'] ?? 0) > 0)    $extra[] = "retry-wait: {$r['retry_wait']}";
    if ((int)($r['expired_lease'] ?? 0) > 0) $extra[] = "expired-lease: {$r['expired_lease']}";
    echo "  {$r['status']}: {$r['total']}" . ($extra ? ' (' . implode(', ', $extra) . ')' : '') . "\n";
}
if ($bulkItems['oldest_pending_age_seconds'] !== null) {
    echo "  oldest claimable pending item age: {$bulkItems['oldest_pending_age_seconds']}s\n";
}

section('Bulk jobs');
if (!$bulkJobs) echo "  (no rows)\n";
foreach ($bulkJobs as $r) {
    echo "  {$r['status']}: {$r['total']}\n";
}

section('Schedules');
if (!$schedules['by_status']) echo "  (no rows)\n";
foreach ($schedules['by_status'] as $r) {
    $extra = [];
    if ((int)($r['retry_wait'] ?? 0) > 0)    $extra[] = "retry-wait: {$r['retry_wait']}";
    if ((int)($r['expired_lease'] ?? 0) > 0) $extra[] = "expired-lease: {$r['expired_lease']}";
    echo "  {$r['status']}: {$r['total']}" . ($extra ? ' (' . implode(', ', $extra) . ')' : '') . "\n";
}
if ($schedules['oldest_pending_age_seconds'] !== null) {
    echo "  oldest due schedule age: {$schedules['oldest_pending_age_seconds']}s\n";
}

section('Auto-reply log');
if (!$autoreply) echo "  (no rows)\n";
foreach ($autoreply as $r) {
    $extra = (int)$r['expired_lease'] > 0 ? " (expired-lease: {$r['expired_lease']})" : '';
    echo "  {$r['status']}: {$r['total']}{$extra}\n";
}

section('Workers');
echo "  active (distinct claimed_by holding a live lease, max across tables): {$activeWorkers}\n";

echo "\n(run 'make jobs-recover' for detail on individual stuck rows, or 'make jobs-recover-force' to make them immediately reclaimable)\n";
