<?php
/**
 * ELLSMS — operational performance snapshot (Phase 9, STEP 9).
 *
 * Read-only, cheap (every query uses an existing index — see docs/observability-and-performance.md
 * §"Metrics catalog" for which one). Complements cron/jobs-status.php (per-status queue counts)
 * with the things an operator actually watches for "is this deployment healthy right now":
 * backlog age, stale wallet reservations, expired leases, and recent backend failure volume by
 * error class. Deliberately NOT a full-table scan of anything — see each query's own comment for
 * the index it relies on. Never put in `/health` or `/health/ready` (STEP 32): those must stay
 * cheap enough to hit on every load-balancer probe, this is an on-demand operator command.
 *
 * Usage:
 *   php cron/performance-snapshot.php
 *   php cron/performance-snapshot.php --json
 */
require_once __DIR__ . '/../app/backend.php';

$json = in_array('--json', $argv ?? [], true);
$db = db();

// Backlog age -- uses idx_claim/idx_due, same as jobs-status.php's oldest-pending-age.
$oldestBulkItemAge = $db->query(
    "SELECT TIMESTAMPDIFF(SECOND, MIN(IFNULL(next_attempt_at, created_at)), NOW()) age
     FROM ellsms_bulk_items WHERE status='pending' AND IFNULL(next_attempt_at, created_at) <= NOW()"
)->fetch()['age'];
$oldestScheduleAge = $db->query(
    "SELECT TIMESTAMPDIFF(SECOND, MIN(IFNULL(next_attempt_at, run_at)), NOW()) age
     FROM ellsms_schedule WHERE status='active' AND IFNULL(next_attempt_at, run_at) <= NOW()"
)->fetch()['age'];

// Expired leases -- uses idx_lease (status, lease_expires_at) on both tables.
$expiredBulkLeases = (int)$db->query(
    "SELECT COUNT(*) c FROM ellsms_bulk_items WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
)->fetch()['c'];
$expiredScheduleLeases = (int)$db->query(
    "SELECT COUNT(*) c FROM ellsms_schedule WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
)->fetch()['c'];
$expiredAutoreplyLeases = (int)$db->query(
    "SELECT COUNT(*) c FROM ellsms_autoreply_log WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < NOW()"
)->fetch()['c'];

// Stale reservations -- ellsms_wallet_reservations has no (status, expires_at) index (only
// (user_id, status)), so this is a full scan of that one table. Documented, not hidden: row
// count there tracks in-flight sends, not historical volume (committed/released rows accumulate
// but stay small relative to outbound_message), so this has stayed cheap in every measured
// workload this phase ran (see docs/observability-and-performance.md §15/21) -- flagged there as
// the one query to revisit with a dedicated index if that table ever grows large enough to matter.
$staleReservations = (int)$db->query(
    "SELECT COUNT(*) c FROM ellsms_wallet_reservations WHERE status='active' AND expires_at IS NOT NULL AND expires_at < NOW()"
)->fetch()['c'];
$oldestActiveReservationAge = $db->query(
    "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) age FROM ellsms_wallet_reservations WHERE status='active'"
)->fetch()['age'];

// Recent backend failures -- uses idx (user_id, attempted_at); scoped to the last hour so this
// never scans the full history of ellsms_message_attempts.
$recentFailuresByClass = $db->query(
    "SELECT error_code, COUNT(*) c FROM ellsms_message_attempts
     WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY error_code ORDER BY c DESC"
)->fetchAll();

$snapshot = [
    'generated_at' => date('c'),
    'backlog' => [
        'oldest_bulk_item_pending_age_seconds' => $oldestBulkItemAge !== null ? (int)$oldestBulkItemAge : null,
        'oldest_schedule_due_age_seconds'      => $oldestScheduleAge !== null ? (int)$oldestScheduleAge : null,
    ],
    'expired_leases' => [
        'bulk_items'    => $expiredBulkLeases,
        'schedules'     => $expiredScheduleLeases,
        'autoreply_log' => $expiredAutoreplyLeases,
    ],
    'wallet_reservations' => [
        'stale_active_past_expiry'        => $staleReservations,
        'oldest_active_reservation_age_seconds' => $oldestActiveReservationAge !== null ? (int)$oldestActiveReservationAge : null,
    ],
    'backend_failures_last_hour' => $recentFailuresByClass,
];

if ($json) {
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "ELLSMS performance snapshot — {$snapshot['generated_at']}\n";

echo "\n=== Backlog ===\n";
echo '  oldest claimable bulk item age: ' . ($snapshot['backlog']['oldest_bulk_item_pending_age_seconds'] ?? 'n/a (no pending items)') . "s\n";
echo '  oldest due schedule age: ' . ($snapshot['backlog']['oldest_schedule_due_age_seconds'] ?? 'n/a (no due schedules)') . "s\n";

echo "\n=== Expired leases ===\n";
echo "  bulk items: {$expiredBulkLeases}\n  schedules: {$expiredScheduleLeases}\n  autoreply log: {$expiredAutoreplyLeases}\n";

echo "\n=== Wallet reservations ===\n";
echo "  stale (active, past expiry): {$staleReservations}\n";
echo '  oldest active reservation age: ' . ($snapshot['wallet_reservations']['oldest_active_reservation_age_seconds'] ?? 'n/a (none active)') . "s\n";

echo "\n=== Backend failures, last hour (by error class) ===\n";
if (!$recentFailuresByClass) {
    echo "  (none)\n";
}
foreach ($recentFailuresByClass as $row) {
    echo "  {$row['error_code']}: {$row['c']}\n";
}
