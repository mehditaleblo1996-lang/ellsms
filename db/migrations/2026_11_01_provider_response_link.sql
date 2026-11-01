-- ---------------------------------------------------------------------------
-- Links a message the gateway turned down to the provider's exact answer.
--
-- provider_audit_id points at the ellsms_provider_response_audit row (raw body, HTTP status, provider
-- error code) of the request that rejected this recipient. The send report shows that answer to admins
-- only; customers keep seeing a plain reason.
--
-- Additive, guarded and rerun-safe. Rows written before this migration keep NULL.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'provider_audit_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD COLUMN provider_audit_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'provider_audit_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD COLUMN provider_audit_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
