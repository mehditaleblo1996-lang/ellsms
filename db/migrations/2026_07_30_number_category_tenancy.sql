-- Phase 6 closure — number categories become fully organization-owned (docs/multi-tenancy-architecture.md).
--
-- Phase 6's own first pass deliberately left ellsms_number_categories on its pre-Phase-6
-- global/visible-to-everyone semantics, to avoid silently cutting off users who weren't a
-- category's creator from a category they could previously see. This migration resolves that
-- deferral explicitly, per direct instruction: categories become organization-owned like every
-- other tenant resource, with tenant-LOCAL name uniqueness (two organizations may legitimately
-- share a category name) replacing Phase 5's global UNIQUE(name).
--
-- This IS a real, disclosed behavior change for any install with a legacy category that multiple
-- DIFFERENT users relied on seeing (not just its creator) — see docs/phase-6-final-report.md's
-- Breaking Changes section for the explicit callout. The actual backfill (assigning
-- organization_id from each category's created_by) happens in cron/tenant-backfill.php, an
-- explicit operator-run step, exactly like every other Phase 6 backfill — never here.
--
-- Guarded the same three ways Phase 5 established: existence check, then a real duplicate-count
-- check before dropping/adding anything, so this never force-applies over data it can't safely
-- change. Run `make tenant-integrity-check` before applying — see that command's own updated
-- category-specific checks.
SET NAMES utf8mb4;

-- Drop Phase 5's global UNIQUE(name) — safe to attempt unconditionally guarded, since dropping a
-- uniqueness constraint can never fail on data (only adding one can); no-ops if already gone.
SET @old_uniq_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_categories' AND constraint_name = 'uniq_category_name'
);
SET @sql = IF(@old_uniq_exists > 0,
  'ALTER TABLE ellsms_number_categories DROP INDEX uniq_category_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add the tenant-local replacement — guarded against duplicates the same way every Phase 5
-- constraint was: if two categories in the SAME organization (or two still-NULL/unbackfilled rows)
-- already share a name, this skips rather than forcing through; make tenant-integrity-check
-- reports the exact count so that's never a silent surprise. Expected to apply cleanly once
-- cron/tenant-backfill.php has resolved every row to a real organization_id — before that,
-- multiple NULL-organization legacy rows sharing a name would NOT collide under this constraint
-- (standard SQL NULL semantics, same caveat Phase 6 originally documented), which is fine: this
-- constraint's job starts once backfill has run, not before.
SET @new_uniq_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_categories' AND constraint_name = 'uniq_org_category_name'
);
SET @dupes = (
  SELECT COUNT(*) FROM (
    SELECT organization_id, name FROM ellsms_number_categories
    WHERE organization_id IS NOT NULL
    GROUP BY organization_id, name HAVING COUNT(*) > 1
  ) d
);
SET @sql = IF(@new_uniq_exists = 0 AND @dupes = 0,
  'ALTER TABLE ellsms_number_categories ADD CONSTRAINT uniq_org_category_name UNIQUE (organization_id, name)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
