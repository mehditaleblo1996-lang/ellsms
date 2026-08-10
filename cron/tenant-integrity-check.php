<?php
/**
 * ELLSMS — tenant/organization integrity audit (Phase 6, STEP 35).
 *
 * Read-only. Same two-use-case design as cron/db-integrity-check.php (Phase 5): doubles as
 * tenant-migration preflight (`make tenant-migration-preflight` is this same script — see
 * docs/multi-tenancy-architecture.md) and as an ongoing post-backfill monitor. Never modifies
 * anything, never auto-assigns an ambiguous row to an organization.
 *
 * Usage: php cron/tenant-integrity-check.php
 */
require_once __DIR__ . '/../app/backend.php';

$db = db();
$critical = 0;

function section(string $title): void {
    echo "\n=== {$title} ===\n";
}

function report(PDO $db, string $sql, string $label): int {
    $count = (int)$db->query($sql)->fetchColumn();
    echo ($count > 0 ? "  [FOUND {$count}] " : '  [ok] ') . $label . "\n";
    return $count;
}

section('Tenant-owned rows missing organization_id (owner has no resolvable organization)');
$mustResolve = [
    'ellsms_wallet_accounts'    => 'user_id',
    'ellsms_wallet_transactions'=> 'user_id',
    'ellsms_wallet_reservations'=> 'user_id',
    'ellsms_payments'           => 'user_id',
    'ellsms_contacts'           => 'user_id',
    'ellsms_bulk_jobs'          => 'user_id',
    'ellsms_schedule'           => 'user_id',
    'ellsms_autoreply_rules'    => 'user_id',
    'ellsms_campaigns'          => 'user_id',
    'ellsms_tickets'            => 'user_id',
    'ellsms_number_categories'  => 'created_by',
];
foreach ($mustResolve as $table => $col) {
    $critical += report($db,
        "SELECT COUNT(*) FROM {$table} WHERE organization_id IS NULL AND {$col} IS NOT NULL",
        "{$table} rows with a real owner ({$col}) but no organization_id — run make tenant-backfill");
}

section('Informational — expected to have NULL organization_id, not a violation');
$numbersUnassigned = (int)$db->query("SELECT COUNT(*) FROM ellsms_numbers WHERE assigned_user_id IS NULL AND organization_id IS NULL")->fetchColumn();
echo "  [info] ellsms_numbers unassigned pool rows (no org expected): {$numbersUnassigned}\n";
$numbersAssignedNoOrg = (int)$db->query("SELECT COUNT(*) FROM ellsms_numbers WHERE assigned_user_id IS NOT NULL AND organization_id IS NULL")->fetchColumn();
if ($numbersAssignedNoOrg > 0) {
    echo "  [FOUND {$numbersAssignedNoOrg}] ellsms_numbers assigned to a user but missing organization_id — run make tenant-backfill\n";
    $critical += $numbersAssignedNoOrg;
} else {
    echo "  [ok] every assigned ellsms_numbers row has organization_id\n";
}
// Phase 6 closure: categories are now backfilled/required like every other tenant table (see
// db/migrations/2026_07_30_number_category_tenancy.sql and cron/tenant-backfill.php) — the
// "informational, deliberately NULL" treatment from Phase 6's first pass no longer applies; a
// category missing organization_id is now a real finding, caught by $mustResolve above.
report($db,
    "SELECT COUNT(*) FROM ellsms_number_category_items i
     LEFT JOIN ellsms_number_categories c ON c.id = i.category_id WHERE c.id IS NULL",
    'ellsms_number_category_items referencing a non-existent category (should be impossible — FK-enforced since Phase 5)');

section('Sender/wallet/payment/job organization_id consistency (must match the owning user\'s own membership)');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_numbers n
     JOIN ellsms_organization_memberships m ON m.user_id = n.assigned_user_id AND m.status = 'active'
     WHERE n.assigned_user_id IS NOT NULL AND n.organization_id IS NOT NULL AND n.organization_id <> m.organization_id",
    'ellsms_numbers.organization_id mismatched with assigned_user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_wallet_accounts w
     JOIN ellsms_organization_memberships m ON m.user_id = w.user_id AND m.status = 'active'
     WHERE w.organization_id IS NOT NULL AND w.organization_id <> m.organization_id",
    'ellsms_wallet_accounts.organization_id mismatched with user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_payments p
     JOIN ellsms_organization_memberships m ON m.user_id = p.user_id AND m.status = 'active'
     WHERE p.organization_id IS NOT NULL AND p.organization_id <> m.organization_id",
    'ellsms_payments.organization_id mismatched with user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_bulk_jobs j
     JOIN ellsms_organization_memberships m ON m.user_id = j.user_id AND m.status = 'active'
     WHERE j.organization_id IS NOT NULL AND j.organization_id <> m.organization_id",
    'ellsms_bulk_jobs.organization_id mismatched with user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_contacts c
     JOIN ellsms_organization_memberships m ON m.user_id = c.user_id AND m.status = 'active'
     WHERE c.organization_id IS NOT NULL AND c.organization_id <> m.organization_id",
    'ellsms_contacts.organization_id mismatched with user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_number_categories n
     JOIN ellsms_organization_memberships m ON m.user_id = n.created_by AND m.status = 'active'
     WHERE n.organization_id IS NOT NULL AND n.organization_id <> m.organization_id",
    'ellsms_number_categories.organization_id mismatched with created_by\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_campaigns c
     JOIN ellsms_organization_memberships m ON m.user_id = c.user_id AND m.status = 'active'
     WHERE c.organization_id IS NOT NULL AND c.organization_id <> m.organization_id",
    'ellsms_campaigns.organization_id mismatched with user_id\'s real membership');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_tickets t
     JOIN ellsms_organization_memberships m ON m.user_id = t.user_id AND m.status = 'active'
     WHERE t.organization_id IS NOT NULL AND t.organization_id <> m.organization_id",
    'ellsms_tickets.organization_id mismatched with user_id\'s real membership');
// STEP 6: payment/wallet organization mismatch — the same user's payment and wallet account
// must agree on which organization they belong to (both are backfilled from the same user_id's
// membership, so any disagreement means one of the two drifted independently of the other).
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_payments p
     JOIN ellsms_wallet_accounts w ON w.user_id = p.user_id
     WHERE p.organization_id IS NOT NULL AND w.organization_id IS NOT NULL AND p.organization_id <> w.organization_id",
    'ellsms_payments.organization_id disagrees with the same user\'s ellsms_wallet_accounts.organization_id');

section('Membership integrity');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_organization_memberships m
     LEFT JOIN ellsms_organizations o ON o.id = m.organization_id WHERE o.id IS NULL",
    'memberships referencing a non-existent organization (should be impossible — FK-enforced)');
// Phase 8 (STEP 0 / TD-038): this was previously `GROUP BY organization_id HAVING COUNT(*) = 0`
// over EXISTING owner-membership rows — which can only ever produce groups with COUNT >= 1, since a
// GROUP BY can't materialize a group for rows that don't exist. That made this check a permanent,
// silent no-op: an organization with truly ZERO owner rows never appeared as a group at all, so it
// was never counted, no matter how broken the data actually was. Phase 7's cron/rbac-integrity-check.php
// independently implemented the correct LEFT JOIN/NOT EXISTS form of the same check; this now uses
// that same, single, authoritative version instead of maintaining two disagreeing algorithms for the
// same invariant (Invariant I) — and is now a real CRITICAL check, not merely informational, since it
// can actually detect the condition it claims to check.
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_organizations o
     WHERE o.status <> 'disabled' AND NOT EXISTS (
       SELECT 1 FROM ellsms_organization_memberships m
       WHERE m.organization_id = o.id AND m.role = 'owner' AND m.status = 'active'
     )",
    'active/suspended organizations with zero active owners — needs manual operator remediation, never auto-fixed');
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_meta m
     LEFT JOIN ellsms_organization_memberships om ON om.user_id = m.user_id
     WHERE om.id IS NULL",
    'ELLSMS-managed users with zero organization membership — run make tenant-backfill');

echo "\n" . ($critical > 0
    ? "CRITICAL: {$critical} tenant-integrity violation(s) found — see above.\n"
    : "OK: zero tenant-integrity violations.\n");

Logger::info('tenant.integrity_check.finished', ['critical_violations' => $critical]);
exit($critical > 0 ? 1 : 0);
