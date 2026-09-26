-- ---------------------------------------------------------------------------
-- Store the user-entered send title on ellsms_import_jobs.
--
-- import_create_job() (app/import.php) has always accepted $title -- every
-- caller (new-send.php gradual mode, p2p-send.php, smart-send.php) passes the
-- title the user typed -- but no column existed for it, so it was discarded.
-- The imports list could only show the random storage filename, and the
-- staged bulk job was titled with that filename too, so users could not tell
-- their imports apart.
--
-- Additive, guarded and rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'title'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN title VARCHAR(190) NOT NULL DEFAULT '' AFTER source_type",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
