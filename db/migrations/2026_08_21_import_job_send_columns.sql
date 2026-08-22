-- ---------------------------------------------------------------------------
-- REPAIR — restore the ellsms_import_jobs send-configuration columns.
--
-- WHY THIS EXISTS. app/import.php's job insert writes message_type,
-- throttle_count and throttle_minutes, and 2026_08_18_import_job_template.sql
-- adds `template` positioned "AFTER message_type" -- but no migration ever
-- CREATED those three columns. 2026_08_16_import_jobs.sql's CREATE TABLE does
-- not list them.
--
-- On a database that predates the split this went unnoticed, because the
-- columns happened to exist from an earlier hand-applied change. On any FRESH
-- database -- a new deployment, a restore, or the integration test fixture --
-- the import job insert fails with:
--
--     SQLSTATE[42S22]: Unknown column 'message_type' in 'ellsms_import_jobs'
--
-- and 2026_08_18's ALTER cannot resolve its AFTER clause either. This took the
-- entire integration suite down (539 errors) on a clean test database, so it is
-- repaired here rather than worked around.
--
-- ORDERING. Dated 2026_08_21 so it runs BEFORE 2026_08_22_report_exports.sql and
-- AFTER 2026_08_18_import_job_template.sql. That ordering matters: 08_18 wants
-- to place `template` AFTER message_type. If 08_18 already ran successfully on
-- an older database, its columns exist and its guard makes it a no-op; the
-- guards below likewise skip whatever is already present. Every branch is a
-- no-op on a database that is already correct.
--
-- Column definitions mirror the send path that consumes them:
--   message_type     -- same ENUM domain as ellsms_sms_routes.message_type, so a
--                       job's type can be matched against a route without a cast.
--   throttle_count   -- NULL means "no throttle" (send as fast as the worker
--   throttle_minutes    allows); both are set together or not at all.
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped, and
-- a second run emits SELECT 1 no-ops.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- --- message_type ----------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'message_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs
     ADD COLUMN message_type ENUM('promotional','transactional','otp','default')
       NOT NULL DEFAULT 'default' AFTER chunk_size",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- throttle_count --------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'throttle_count'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs
     ADD COLUMN throttle_count INT UNSIGNED NULL AFTER message_type",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- throttle_minutes ------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'throttle_minutes'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs
     ADD COLUMN throttle_minutes INT UNSIGNED NULL AFTER throttle_count",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- template / variable_headers -------------------------------------------
-- Normally created by 2026_08_18_import_job_template.sql. That migration runs
-- BEFORE this one and fails on a fresh database, because its AFTER clause
-- references message_type, which did not exist yet. These guarded blocks make
-- the pair self-healing in either order: whichever runs second finds the
-- columns present and no-ops.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'template'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN template TEXT NULL AFTER message_type",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'variable_headers'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN variable_headers JSON NULL AFTER template",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
