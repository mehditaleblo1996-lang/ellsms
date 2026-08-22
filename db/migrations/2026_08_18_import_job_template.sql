-- Smart-send large imports need the per-row template and variable headers to be
-- rendered asynchronously by the import worker, not in the web request.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'template'
);
-- The AFTER clause is conditional on message_type actually existing. It did not
-- on a fresh database -- no migration ever created it, even though app/import.php
-- writes to it (repaired in 2026_08_21_import_job_send_columns.sql, which sorts
-- AFTER this file and so cannot help it here). Referencing a missing column in
-- AFTER is a hard error that aborts the whole schema load, so when message_type
-- is absent the columns are simply appended instead; 08_21 then adds
-- message_type and finds template already present. Column ORDER is cosmetic --
-- nothing in this project selects by ordinal position.
SET @anchor_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'message_type'
);
SET @sql = IF(@col_exists = 0,
  IF(@anchor_exists = 1,
    "ALTER TABLE ellsms_import_jobs
       ADD COLUMN template TEXT NULL AFTER message_type,
       ADD COLUMN variable_headers JSON NULL AFTER template",
    "ALTER TABLE ellsms_import_jobs
       ADD COLUMN template TEXT NULL,
       ADD COLUMN variable_headers JSON NULL"
  ),
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
