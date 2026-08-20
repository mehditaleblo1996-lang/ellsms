-- Smart-send large imports need the per-row template and variable headers to be
-- rendered asynchronously by the import worker, not in the web request.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'template'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs
     ADD COLUMN template TEXT NULL AFTER message_type,
     ADD COLUMN variable_headers JSON NULL AFTER template",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
