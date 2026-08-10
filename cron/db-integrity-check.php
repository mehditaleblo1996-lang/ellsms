<?php
/**
 * ELLSMS — database integrity audit (Phase 5, STEP 2/15).
 *
 * Read-only. Reports the exact same counts db/migrations/2026_07_28_data_integrity.sql's own
 * guards compute, so running this BEFORE applying that migration tells you in advance exactly
 * which constraints will actually apply vs. silently skip (see docs/database-migrations.md) —
 * and running it AFTER migrating (or periodically, ongoing) confirms nothing has drifted since.
 *
 * Never modifies anything. Exits non-zero only for CRITICAL findings — ELLSMS-owned orphans/
 * duplicates that indicate real data corruption or would block a constraint this project intends
 * to enforce. Backend-table soft-reference counts and unbounded-growth/staleness counts are
 * reported for visibility (STEP 6 — monitoring, not hard enforcement, since ELLSMS does not own
 * user_) and never affect the exit code — a backend account being independently deleted or
 * deactivated is not, by itself, an ELLSMS data-integrity bug.
 *
 * Usage: php cron/db-integrity-check.php
 */
require_once __DIR__ . '/../app/backend.php';

$db = db();
$critical = 0;

function section(string $title): void {
    echo "\n=== {$title} ===\n";
}

/** @return int the count, so callers can add it to $critical when it represents a real violation */
function report(PDO $db, string $sql, string $label): int {
    $count = (int)$db->query($sql)->fetchColumn();
    echo ($count > 0 ? "  [FOUND {$count}] " : '  [ok] ') . $label . "\n";
    return $count;
}

section('ELLSMS-owned orphans (child row whose parent no longer exists)');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_bulk_items i LEFT JOIN ellsms_bulk_jobs j ON j.id = i.job_id WHERE j.id IS NULL",
    'ellsms_bulk_items.job_id -> ellsms_bulk_jobs.id');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_number_category_items i LEFT JOIN ellsms_number_categories c ON c.id = i.category_id WHERE c.id IS NULL",
    'ellsms_number_category_items.category_id -> ellsms_number_categories.id');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_ticket_replies r LEFT JOIN ellsms_tickets t ON t.id = r.ticket_id WHERE t.id IS NULL",
    'ellsms_ticket_replies.ticket_id -> ellsms_tickets.id');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_wallet_transactions t LEFT JOIN ellsms_wallet_accounts a ON a.user_id = t.user_id WHERE a.user_id IS NULL",
    'ellsms_wallet_transactions.user_id -> ellsms_wallet_accounts.user_id');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_wallet_reservations r LEFT JOIN ellsms_wallet_accounts a ON a.user_id = r.user_id WHERE a.user_id IS NULL",
    'ellsms_wallet_reservations.user_id -> ellsms_wallet_accounts.user_id');

section('Known, deliberately-deferred gap (not counted as critical — see docs/database-migrations.md)');
report($db,
    "SELECT COUNT(*) FROM ellsms_autoreply_log l LEFT JOIN ellsms_autoreply_rules r ON r.id = l.rule_id WHERE r.id IS NULL",
    "ellsms_autoreply_log.rule_id -> ellsms_autoreply_rules.id (public/autoreply.php's delete action does not clean these up — a pre-existing gap, not introduced by Phase 5; adding a RESTRICT/CASCADE FK here would change delete-rule behavior, deferred pending a product decision)");

section('Duplicate logical keys (would block a UNIQUE constraint, or already should be impossible)');
$critical += report($db,
    "SELECT COUNT(*) FROM (SELECT name FROM ellsms_number_categories GROUP BY name HAVING COUNT(*) > 1) d",
    'ellsms_number_categories.name duplicates');
$critical += report($db,
    "SELECT COUNT(*) FROM (SELECT authority FROM ellsms_payments WHERE authority IS NOT NULL GROUP BY authority HAVING COUNT(*) > 1) d",
    'ellsms_payments.authority duplicates (non-NULL only)');
report($db,
    "SELECT COUNT(*) FROM (SELECT idempotency_key FROM ellsms_wallet_transactions GROUP BY idempotency_key HAVING COUNT(*) > 1) d",
    'ellsms_wallet_transactions.idempotency_key duplicates (should be structurally impossible — UNIQUE since Phase 3; a nonzero count here would mean the constraint itself is missing or was bypassed)');
report($db,
    "SELECT COUNT(*) FROM (SELECT reference_type, reference_id FROM ellsms_wallet_reservations GROUP BY reference_type, reference_id HAVING COUNT(*) > 1) d",
    'ellsms_wallet_reservations (reference_type, reference_id) duplicates (should be structurally impossible — UNIQUE since Phase 3)');

section('Deferred product decision — for review only, not enforced (see docs/database-migrations.md)');
report($db,
    "SELECT COUNT(*) FROM (SELECT user_id, mobile FROM ellsms_contacts GROUP BY user_id, mobile HAVING COUNT(*) > 1) d",
    'ellsms_contacts duplicate (user_id, mobile) pairs — candidate shape A');
report($db,
    "SELECT COUNT(*) FROM (SELECT user_id, mobile, group_name FROM ellsms_contacts GROUP BY user_id, mobile, group_name HAVING COUNT(*) > 1) d",
    'ellsms_contacts duplicate (user_id, mobile, group_name) triples — candidate shape B');

section('Backend-table soft references (ELLSMS does not own user_ — monitoring only, never enforced; STEP 6)');
$backendRefs = [
    'ellsms_meta.user_id'          => "SELECT COUNT(*) FROM ellsms_meta m LEFT JOIN user_ u ON u.id = m.user_id WHERE u.id IS NULL",
    'ellsms_schedule.user_id'      => "SELECT COUNT(*) FROM ellsms_schedule s LEFT JOIN user_ u ON u.id = s.user_id WHERE u.id IS NULL",
    'ellsms_bulk_jobs.user_id'     => "SELECT COUNT(*) FROM ellsms_bulk_jobs j LEFT JOIN user_ u ON u.id = j.user_id WHERE u.id IS NULL",
    'ellsms_contacts.user_id'      => "SELECT COUNT(*) FROM ellsms_contacts c LEFT JOIN user_ u ON u.id = c.user_id WHERE u.id IS NULL",
    'ellsms_payments.user_id'      => "SELECT COUNT(*) FROM ellsms_payments p LEFT JOIN user_ u ON u.id = p.user_id WHERE u.id IS NULL",
    'ellsms_wallet_accounts.user_id' => "SELECT COUNT(*) FROM ellsms_wallet_accounts w LEFT JOIN user_ u ON u.id = w.user_id WHERE u.id IS NULL",
    'ellsms_tickets.user_id'       => "SELECT COUNT(*) FROM ellsms_tickets t LEFT JOIN user_ u ON u.id = t.user_id WHERE u.id IS NULL",
];
foreach ($backendRefs as $label => $sql) {
    report($db, $sql, $label . ' -> user_.id');
}

section('Unbounded-growth tables (informational — see "Retention" in docs/database-migrations.md)');
foreach (['ellsms_audit_log', 'ellsms_autoreply_log', 'ellsms_2fa_codes', 'ellsms_bulk_items'] as $table) {
    $count = (int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    echo "  {$table}: {$count} row(s)\n";
}

section('Ephemeral data eligible for cleanup (see: make db-cleanup)');
report($db,
    "SELECT COUNT(*) FROM ellsms_2fa_codes WHERE expires_at < NOW()",
    'expired ellsms_2fa_codes rows');
report($db,
    "SELECT COUNT(*) FROM ellsms_rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)",
    'ellsms_rate_limits rows older than 1 day (buckets self-prune on hit, this is stale leftover from buckets not hit since)');

echo "\n" . ($critical > 0
    ? "CRITICAL: {$critical} ELLSMS-owned integrity violation(s) found — see above.\n"
    : "OK: zero ELLSMS-owned integrity violations.\n");

Logger::info('db.integrity_check.finished', ['critical_violations' => $critical]);
exit($critical > 0 ? 1 : 0);
