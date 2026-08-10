<?php
/**
 * ELLSMS — RBAC integrity audit (Phase 7, STEP 39).
 *
 * Read-only, same two-use-case design as cron/tenant-integrity-check.php (Phase 6) and
 * cron/db-integrity-check.php (Phase 5): migration preflight AND ongoing monitor. Never modifies
 * anything, never auto-fixes ownership or role state — a finding here means "an operator needs to
 * look at this," exactly like every other integrity tool in this codebase.
 *
 * Two kinds of checks, because this phase deliberately chose Option A (fixed built-in roles,
 * code-mapped permissions — see app/rbac.php's own docblock) over a database-backed roles/
 * permissions schema:
 *  - DB checks: real ellsms_organization_memberships row state (zero-owner organizations, invalid
 *    role values a raw SQL statement could still theoretically write even though the ENUM column
 *    normally rejects them).
 *  - Code checks: app/rbac.php's role_permissions() map is internally consistent with
 *    app/Support/Permissions.php's catalog (catches a typo'd permission string in the map at
 *    operator-run-time, not just whenever a developer happens to read the file). "Cross-org role
 *    linkage" and "unknown permission row" (STEP 39's DB-backed-oriented checks) do not apply here —
 *    there is no roles/permissions TABLE to check, by design; noted explicitly below rather than
 *    silently skipped.
 *
 * Usage: php cron/rbac-integrity-check.php
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

section('Owner protection (Invariant I)');
// Correct LEFT JOIN/NOT EXISTS form — every active organization must have at least one active
// 'owner' membership. (Originally duplicated here because cron/tenant-integrity-check.php's own
// version was a GROUP BY over EXISTING rows that could never detect a true zero-row case — TD-038.
// Phase 8, STEP 0 fixed that file in place to use this exact same query, so the two tools no longer
// disagree; this copy stays here too since rbac-integrity-check.php's whole point is not depending
// on tenant-integrity-check.php ever having been run.)
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_organizations o
     WHERE o.status <> 'disabled' AND NOT EXISTS (
       SELECT 1 FROM ellsms_organization_memberships m
       WHERE m.organization_id = o.id AND m.role = 'owner' AND m.status = 'active'
     )",
    'active/suspended organizations with zero active owners — needs manual operator remediation, never auto-fixed');

section('Role value integrity');
// Defensive only — ellsms_organization_memberships.role is an ENUM('owner','admin','member'), so a
// normal INSERT/UPDATE cannot write anything else; this catches the only way an unknown value could
// still appear (a raw statement bypassing the ENUM, or a future schema change that widens it without
// updating app/rbac.php's role_permissions() map to match).
$critical += report($db,
    "SELECT COUNT(*) FROM ellsms_organization_memberships WHERE status = 'active' AND role NOT IN ('owner','admin','member')",
    "active memberships with a role outside the known set (owner/admin/member) — membership_has_permission() fails closed for these (returns false for every permission), but the row itself needs investigation");

section('Code-level: role_permissions() map vs. Permissions catalog (no DB table — Option A design)');
$catalog = Permissions::all();
$mapIssues = 0;
foreach (['owner', 'admin', 'member'] as $role) {
    foreach (role_permissions($role) as $permission) {
        if (!in_array($permission, $catalog, true)) {
            echo "  [FOUND] role_permissions('{$role}') grants unknown permission string: {$permission}\n";
            $mapIssues++;
        }
    }
}
if ($mapIssues === 0) {
    echo "  [ok] every permission string granted by role_permissions() exists in Permissions::all()\n";
}
$critical += $mapIssues;
echo "  [n/a] cross-org role linkage / unknown-permission-row checks do not apply — permissions are compile-time constants, not database rows (Option A, no ellsms_permissions/ellsms_roles tables exist).\n";

echo "\n" . ($critical > 0
    ? "CRITICAL: {$critical} RBAC-integrity violation(s) found — see above.\n"
    : "OK: zero RBAC-integrity violations.\n");

Logger::info('rbac.integrity_check.finished', ['critical_violations' => $critical]);
exit($critical > 0 ? 1 : 0);
