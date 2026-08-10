-- Phase 7 — RBAC / fine-grained authorization (docs/rbac-architecture.md).
--
-- No new tables and no ENUM change: ellsms_organization_memberships.role already covers exactly the
-- three built-in roles (owner/admin/member) this phase's permission model maps in code
-- (app/rbac.php's role_permissions()) — Option A from this phase's own design menu (fixed roles,
-- code-mapped permissions), deliberately not Option B (database-backed custom roles/permissions),
-- since no current product requirement justifies that extra schema/query surface. See
-- docs/rbac-architecture.md section "Database Migration" for the full reasoning.
--
-- The one real schema need this phase has: organization_change_member_role()/
-- organization_remove_member() (app/rbac.php) enforce Invariant I (the last owner can never be
-- removed/demoted, even under concurrency) by running `SELECT ... FOR UPDATE` against every ACTIVE
-- membership row of one organization inside a transaction before deciding — see STEP 8/31's explicit
-- "a plain read-then-update without locking is insufficient." That query already has a usable index
-- via the existing `KEY (user_id, status)`, but not one led by organization_id (the actual WHERE
-- clause), so on an organization with many memberships it would fall back to a full scan of that
-- lookup under lock. This adds the missing composite index so the locking query stays an efficient
-- index range scan under real concurrent load, not a correctness fix (the transaction/locking logic
-- is correct either way) but a genuine operational one.
--
-- Guarded the same way every migration since Phase 5 is: check-then-conditionally-apply via
-- information_schema, never force. A plain ADD INDEX cannot violate data (unlike UNIQUE/FK), so there
-- is no dirty-data case to preflight here — only "does it already exist."
SET NAMES utf8mb4;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_memberships' AND index_name = 'idx_org_role_status'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_organization_memberships ADD INDEX idx_org_role_status (organization_id, role, status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
