-- ---------------------------------------------------------------------------
-- «افزودن لغو۱۱ به انتهای پیام» becomes a per-user setting a platform admin
-- controls (Users → user); customers no longer have a checkbox on the send
-- forms (docs/optout-suffix.md).
--
-- Default 1: every existing user keeps getting «لغو۱۱» exactly as before the
-- admin turns it off for them.
--
-- Additive, guarded and rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_meta' AND column_name = 'optout_suffix'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_meta ADD COLUMN optout_suffix TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
