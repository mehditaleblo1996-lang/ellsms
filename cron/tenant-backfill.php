<?php
/**
 * ELLSMS — legacy-user-to-organization backfill (Phase 6, STEP 4/9).
 *
 * One-time, idempotent, operator-run migration: every existing ELLSMS-managed account (a row in
 * ellsms_meta — the closest proxy for "has ever been a real panel user," since that table is never
 * deleted, only revoked, per docs/database-audit.md) that does not already have an organization
 * gets its own default "{name}'s Workspace" organization, with that user as its sole 'owner'
 * member — the "one organization per existing user" strategy this phase's own instructions
 * recommend as the safest choice: it preserves exact current isolation (nobody gains access to
 * anyone else's data as a side effect of this migration) rather than guessing at any grouping.
 *
 * Then backfills organization_id on every tenant-owned table FROM the owning user's now-guaranteed
 * organization — additive only, never touches user_id/currentcredit/balances/any existing column.
 *
 * Ambiguous ownership is quarantined, not guessed (STEP 34): a row whose user_id has no
 * corresponding ellsms_meta row (never a real panel user — orphaned data, a pre-existing condition
 * this migration didn't create) is skipped and reported, organization_id left NULL.
 *
 * ellsms_number_categories — Phase 6's own first pass deliberately deferred this table (kept
 * organization_id=NULL, preserving pre-Phase-6 visible-to-everyone behavior). This closure pass
 * resolves that deferral: categories are now backfilled from their created_by admin's own
 * organization, same as every other tenant table. This IS a disclosed behavior change for any
 * install where a legacy category was actually used by someone other than its creator — see
 * docs/phase-6-final-report.md's Breaking Changes section. Requires
 * db/migrations/2026_07_30_number_category_tenancy.sql to have been applied first (adds the
 * tenant-local UNIQUE(organization_id, name) this backfill's target column needs to already exist
 * for — the column itself was already added by 2026_07_29_organizations.sql).
 *
 * Usage:
 *   php cron/tenant-backfill.php --dry-run   # report only, changes nothing
 *   php cron/tenant-backfill.php             # apply
 */
require_once __DIR__ . '/../app/backend.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$db = db();

echo $dryRun ? "DRY RUN — no changes will be made.\n\n" : "APPLYING — this will write to the database.\n\n";

// Step 1: one organization per user who doesn't already have one.
$candidateIds = $db->query(
    "SELECT m.user_id
     FROM ellsms_meta m
     LEFT JOIN ellsms_organization_memberships om ON om.user_id = m.user_id
     WHERE om.id IS NULL
     ORDER BY m.user_id"
)->fetchAll(PDO::FETCH_COLUMN);
$usersById = backend_users_by_ids($candidateIds);

echo 'Users needing a default organization: ' . count($usersById) . "\n";
$created = 0;
foreach ($candidateIds as $userId) {
    if (!isset($usersById[$userId])) {
        continue;
    }
    $row = $usersById[$userId];
    $row['user_id'] = $userId;
    $displayName = trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['username'];
    $orgName = $displayName . "'s Workspace";
    if ($dryRun) {
        echo "  would create organization \"{$orgName}\" for user_id={$row['user_id']} ({$row['username']}), role=owner\n";
        continue;
    }
    $result = create_organization((int)$row['user_id'], $orgName);
    if (!$result['ok']) {
        fwrite(STDERR, "  FAILED to create organization for user_id={$row['user_id']}: " . ($result['reason'] ?? 'unknown') . "\n");
        continue;
    }
    $created++;
    echo "  created organization #{$result['organization_id']} \"{$orgName}\" for user_id={$row['user_id']}\n";
}
if (!$dryRun) {
    echo "Created {$created} organization(s).\n";
}
echo "\n";

// Step 2: backfill organization_id on every tenant-owned table (except ellsms_number_categories —
// see this file's own docblock), sourced from each row's owning user's now-guaranteed
// organization. ellsms_organization_memberships.user_id has no unique constraint alone (only
// (organization_id, user_id) is unique), but at this point in a legacy-only migration every user
// has exactly one row — the subquery below tolerates more than one by taking the earliest
// (lowest id), which for a legacy user is their sole default organization.
$targets = [
    ['ellsms_wallet_accounts', 'user_id'],
    ['ellsms_wallet_transactions', 'user_id'],
    ['ellsms_wallet_reservations', 'user_id'],
    ['ellsms_payments', 'user_id'],
    ['ellsms_numbers', 'assigned_user_id'],
    ['ellsms_contacts', 'user_id'],
    ['ellsms_bulk_jobs', 'user_id'],
    ['ellsms_schedule', 'user_id'],
    ['ellsms_autoreply_rules', 'user_id'],
    ['ellsms_campaigns', 'user_id'],
    ['ellsms_tickets', 'user_id'],
    ['ellsms_number_categories', 'created_by'],
    ['ellsms_audit_log', 'user_id'],
];

$orgForUserSql = "(SELECT MIN(om2.organization_id) FROM ellsms_organization_memberships om2 WHERE om2.user_id = %s AND om2.status = 'active')";

$totalBackfilled = 0;
$totalUnresolved = 0;
foreach ($targets as [$table, $ownerColumn]) {
    $orgExpr = sprintf($orgForUserSql, $ownerColumn);
    $countSql = "SELECT COUNT(*) FROM {$table} WHERE organization_id IS NULL AND {$ownerColumn} IS NOT NULL AND {$orgExpr} IS NOT NULL";
    $eligible = (int)$db->query($countSql)->fetchColumn();

    $unresolvedSql = "SELECT COUNT(*) FROM {$table} WHERE organization_id IS NULL AND ({$ownerColumn} IS NULL OR {$orgExpr} IS NULL)";
    $unresolved = (int)$db->query($unresolvedSql)->fetchColumn();
    $totalUnresolved += $unresolved;

    if ($dryRun) {
        echo "{$table}: {$eligible} row(s) would be backfilled, {$unresolved} row(s) unresolved (no resolvable owner — quarantined, left NULL)\n";
        continue;
    }
    if ($eligible > 0) {
        $updateSql = "UPDATE {$table} SET organization_id = {$orgExpr} WHERE organization_id IS NULL AND {$ownerColumn} IS NOT NULL AND {$orgExpr} IS NOT NULL";
        $affected = $db->exec($updateSql);
        $totalBackfilled += $affected;
        echo "{$table}: {$affected} row(s) backfilled";
        if ($unresolved > 0) {
            echo ", {$unresolved} row(s) unresolved (quarantined, left NULL)";
            Logger::warning('tenant.backfill.unresolved_rows', ['table' => $table, 'count' => $unresolved]);
        }
        echo "\n";
    } else {
        echo "{$table}: 0 row(s) to backfill" . ($unresolved > 0 ? ", {$unresolved} unresolved" : '') . "\n";
    }
}

echo "\n";
if ($dryRun) {
    echo "Dry run complete — nothing changed. Re-run without --dry-run to apply.\n";
} else {
    echo "Backfill complete. Total rows backfilled: {$totalBackfilled}. Total unresolved (quarantined): {$totalUnresolved}.\n";
    Logger::info('tenant.backfill.finished', ['organizations_created' => $created, 'rows_backfilled' => $totalBackfilled, 'rows_unresolved' => $totalUnresolved]);
}
if ($totalUnresolved > 0) {
    echo "Run 'make tenant-integrity-check' for the exact unresolved rows before treating this migration as complete.\n";
}
