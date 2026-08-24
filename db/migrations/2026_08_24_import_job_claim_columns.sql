-- ---------------------------------------------------------------------------
-- REPAIR — restore ellsms_import_jobs.claimed_by/claimed_at.
--
-- WHY THIS EXISTS. import_claim_uploaded_job() (app/import_worker.php) performs
-- exactly the same atomic-claim pattern as bulk_claim_items() and
-- import_claim_chunk(): an UPDATE that both transitions status and stamps a
-- unique claim token, followed by a SELECT that reads the row back by that
-- token -- the only way to know WHICH row this call's UPDATE actually touched
-- when multiple workers race the same UPDATE concurrently.
--
--     UPDATE ellsms_import_jobs SET status='analyzing', ..., claimed_by=?
--       WHERE status='uploaded' ORDER BY id LIMIT 1
--     SELECT * FROM ellsms_import_jobs WHERE claimed_by=?
--
-- ellsms_import_chunks got this column pair in 2026_08_16_import_jobs.sql --
-- "Mirrors the proven claim/lease pattern used by ellsms_bulk_items" is that
-- migration's own comment. ellsms_import_jobs never did, on any migration.
-- On any database built from the committed migrations (a fresh deployment, a
-- restore, the integration test fixture) the claim fails outright:
--
--     SQLSTATE[42S22]: Unknown column 'claimed_by' in 'field list'
--
-- which means import_claim_uploaded_job() could never successfully claim a
-- job, which means pass 1 could never start, which means NO import through the
-- web UI (new-send.php gradual mode, p2p-send.php, smart-send.php) could ever
-- reach 'ready_for_confirmation' -- found writing the first integration tests
-- this pipeline has ever had (tests/Integration/LargeImportPipelineTest.php).
--
-- SCOPE. Only claimed_by/claimed_at are added here. lease_expires_at and
-- attempt_count exist on ellsms_import_chunks (a chunk can be reclaimed after a
-- crash) but import_claim_uploaded_job() has no reclaim branch of its own --
-- pass 1 for a stuck job is retried at the CHUNK level, since analyze chunks
-- already carry their own lease. Adding lease columns the code never reads
-- would be schema nobody uses; if job-level reclaim is wanted later, it is a
-- separate, deliberate change.
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped,
-- and a second run emits SELECT 1 no-ops.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'claimed_by'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN claimed_by VARCHAR(80) NULL AFTER status",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'claimed_at'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_jobs ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
