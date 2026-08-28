-- Allow platform-admin initiated large imports.
--
-- Platform admins are not required to belong to a tenant organization, so current_user() may
-- legitimately expose organization_id = NULL. import_create_job() preserves that value, but the
-- original import schema declared ellsms_import_jobs.organization_id NOT NULL. Large sends from a
-- platform admin therefore reached the async import threshold, attempted to insert NULL, and were
-- caught as the generic Persian "خطا در ثبت درخواست واردسازی." banner.
--
-- Ordinary tenant imports keep their organization_id exactly as before. NULL only represents the
-- existing platform-admin/global scope, which import_load_job() already supports when its caller
-- passes a NULL organization scope. Guarded and rerun-safe.

SET NAMES utf8mb4;

SET @needs_nullable = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_import_jobs'
    AND column_name = 'organization_id'
    AND is_nullable = 'NO'
);
SET @sql = IF(
  @needs_nullable > 0,
  'ALTER TABLE ellsms_import_jobs MODIFY COLUMN organization_id INT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
