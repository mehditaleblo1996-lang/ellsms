-- ---------------------------------------------------------------------------
-- «افزودن لغو۱۱ به انتهای پیام» for large file sends (docs/optout-suffix.md).
--
-- P2P and smart files above the sync limit go through the async import
-- pipeline, where each row's text is only read (and, for smart, rendered) by
-- the import worker. The send form's opt-out checkbox is stored here so the
-- worker appends "\nلغو۱۱" to every row that does not already carry it.
-- Default 0 keeps every existing / in-flight job exactly as it was.
--
-- Additive, guarded and rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'add_optout_suffix'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_import_jobs ADD COLUMN add_optout_suffix TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
