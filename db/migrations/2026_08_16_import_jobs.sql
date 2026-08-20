-- Large-scale SMS import pipeline (docs/large-import-architecture.md).
--
-- THE PROBLEM THIS SOLVES. The existing bulk send flow (public/p2p-send.php, public/smart-send.php,
-- public/new-send.php gradual mode) builds the entire recipient list in PHP memory, prices every row
-- synchronously, and inserts all items inside one transaction. For files above a few tens of thousands
-- of rows this exhausts PHP memory and request timeouts, and it makes the browser wait for work that
-- has nothing to do with the upload itself.
--
-- THE SOLUTION. A separate import-job layer tracks uploaded files and their analysis progress. The
-- web request stores the file, creates an import job, and returns immediately. A dedicated worker
-- streams the file in bounded chunks, normalizes/validates/dedupes/blacklists/prices rows, and writes
-- them into the existing ellsms_bulk_items table in batches. Only after analysis completes does the
-- user see a cost summary and confirm; confirmation promotes the linked ellsms_bulk_jobs row from
-- 'staged' to 'pending' so the normal send worker takes over.
--
-- DESIGN CHOICES.
-- * New import tables, NOT a widening of ellsms_bulk_jobs. A bulk job means "messages queued to send";
--   an import job means "parse/validate/dedupe a file". Mixing the two would force every bulk worker
--   and report to ignore import rows.
-- * ellsms_bulk_items is reused for the durable send rows. It already carries frozen price columns,
--   gateway/delivery metadata, and a proven claim/lease/retry mechanism. Duplicating that structure
--   would be wasteful and risky.
-- * A unique staging constraint on (import_job_id, normalized_mobile) gives database-backed dedupe
--   across chunks, so a 1M-row import never needs a 1M-entry PHP associative array.
-- * No generated columns (TD-070). Every column is ordinary and backup/restore safe.
--
-- BACKWARD COMPATIBILITY. Existing bulk jobs keep working unchanged: ellsms_bulk_jobs gets a nullable
-- source_import_job_id column, and every pre-existing row is simply NULL. The synchronous small-file
-- path remains for files below SMS_SYNC_MAX_RECIPIENTS.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Import job header: one row per uploaded file.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ellsms_import_jobs (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id     INT UNSIGNED NOT NULL,
  user_id             BIGINT NOT NULL,
  source_type         ENUM('p2p','smart','gradual') NOT NULL,
  original_filename   VARCHAR(255) NOT NULL DEFAULT '',
  storage_key         VARCHAR(120) NOT NULL,        -- opaque path under storage/imports/
  status              ENUM('uploaded','analyzing','ready_for_confirmation','queued','sending','completed','failed','cancelled')
                        NOT NULL DEFAULT 'uploaded',
  total_rows          BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows seen in file
  processed_rows      BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows analyzed so far
  valid_rows          BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows that became bulk_items
  invalid_rows        BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows rejected (bad mobile / empty content)
  duplicate_rows      BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- duplicates within this import
  blacklisted_rows    BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- removed by blacklist filter
  priced_rows         BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows with a resolved price
  unpriced_rows       BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows that could not be priced
  queued_rows         BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- rows promoted to send queue
  sent_rows           BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- terminal sent count (updated by worker)
  failed_rows         BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- terminal failed count (updated by worker)
  estimated_cost_credits BIGINT UNSIGNED NOT NULL DEFAULT 0, -- worst-case cost at analysis time
  currency            VARCHAR(8) NOT NULL DEFAULT 'credit',
  chunk_size          INT UNSIGNED NOT NULL DEFAULT 5000,
  error_message       TEXT NULL,
  analysis_started_at DATETIME NULL,
  analysis_completed_at DATETIME NULL,
  sending_started_at  DATETIME NULL,
  completed_at        DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org_status (organization_id, status),
  KEY idx_user_created (user_id, created_at),
  KEY idx_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Import chunk tracking: one row per chunk of the source file.
--
-- Mirrors the proven claim/lease pattern used by ellsms_bulk_items and
-- ellsms_webhook_deliveries. A worker claims a chunk with an atomic UPDATE,
-- analyzes it, and writes the resulting bulk items before marking the chunk
-- completed. Crashed or timed-out chunks are reclaimed by lease_expires_at.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ellsms_import_chunks (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_job_id       BIGINT UNSIGNED NOT NULL,
  chunk_no            INT UNSIGNED NOT NULL,
  byte_offset         BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- CSV/TXT byte offset; 0 for XLSX row-based
  first_row           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_row            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status              ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  claimed_by          VARCHAR(80) NULL,
  claimed_at          DATETIME NULL,
  lease_expires_at    DATETIME NULL,
  attempt_count       INT UNSIGNED NOT NULL DEFAULT 0,
  rows_total          INT UNSIGNED NOT NULL DEFAULT 0,
  rows_valid          INT UNSIGNED NOT NULL DEFAULT 0,
  rows_invalid        INT UNSIGNED NOT NULL DEFAULT 0,
  rows_duplicate      INT UNSIGNED NOT NULL DEFAULT 0,
  rows_blacklisted    INT UNSIGNED NOT NULL DEFAULT 0,
  rows_priced         INT UNSIGNED NOT NULL DEFAULT 0,
  rows_unpriced       INT UNSIGNED NOT NULL DEFAULT 0,
  error_log           TEXT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_job_chunk (import_job_id, chunk_no),
  KEY idx_claim (status, lease_expires_at),
  KEY idx_job_status (import_job_id, status),
  CONSTRAINT fk_import_chunks_job FOREIGN KEY (import_job_id) REFERENCES ellsms_import_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Staging table for deduplication within an import job.
--
-- The worker inserts every valid normalized mobile here BEFORE inserting the
-- corresponding bulk item. The UNIQUE key makes duplicates fail cleanly, so
-- the import never creates duplicate bulk_items for the same import job.
-- (p2p/smart files may legitimately contain the same mobile twice with
-- different content; those are different ROWS and are NOT deduped here. This
-- table dedupes identical mobile+content fingerprints instead.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ellsms_import_dedupe (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  import_job_id       BIGINT UNSIGNED NOT NULL,
  mobile              VARCHAR(20) NOT NULL,
  content_fingerprint CHAR(64) NOT NULL,            -- sha256(content) so same-mobile-different-content stays distinct
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_import_dedupe (import_job_id, mobile, content_fingerprint),
  KEY idx_import_job (import_job_id),
  CONSTRAINT fk_import_dedupe_job FOREIGN KEY (import_job_id) REFERENCES ellsms_import_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Link bulk jobs back to their originating import job.
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND column_name = 'source_import_job_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_jobs
     ADD COLUMN source_import_job_id BIGINT UNSIGNED NULL AFTER organization_id,
     ADD KEY idx_source_import (source_import_job_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND constraint_name = 'fk_bulk_jobs_import'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE ellsms_bulk_jobs
     ADD CONSTRAINT fk_bulk_jobs_import FOREIGN KEY (source_import_job_id) REFERENCES ellsms_import_jobs(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- A 'staged' status for bulk jobs that are fully imported but not yet
-- confirmed by the user. The existing worker only promotes 'pending' jobs to
-- 'processing', so a staged job sits idle until confirmation flips it.
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs'
    AND column_name = 'status' AND column_type LIKE "%'staged'%"
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_bulk_jobs
     MODIFY COLUMN status ENUM('pending','processing','done','cancelled','staged') NOT NULL DEFAULT 'pending'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Performance indexes for large bulk jobs.
-- ---------------------------------------------------------------------------
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND index_name = 'idx_job_status_id'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD KEY idx_job_status_id (job_id, status, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND index_name = 'idx_provider_msg'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD KEY idx_provider_msg (provider_message_id, gateway_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
