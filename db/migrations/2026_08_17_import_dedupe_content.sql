-- Large-scale import pipeline — make the dedupe table serve as bounded-memory staging.
--
-- Phase 1 created ellsms_import_dedupe only to enforce (import_job_id, mobile,
-- content_fingerprint) uniqueness. Phase 2's two-pass analysis needs a place to
-- hold the validated unique rows between the cost-preview pass and the bulk-item
-- insert pass. Rather than creating a third table, we extend the dedupe table
-- with the columns needed to reconstruct a row: content and segments. Unique
-- rows are written here during the first pass; the second pass reads them back
-- in chunks, prices them at the job's fixed analysis instant, and inserts them
-- into ellsms_bulk_items. This avoids holding the whole validated set in PHP
-- memory and avoids a separate staging table.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_dedupe' AND column_name = 'content'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_dedupe
     ADD COLUMN content TEXT NOT NULL AFTER mobile,
     ADD COLUMN segments INT UNSIGNED NOT NULL DEFAULT 0 AFTER content",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Phase 2 supports parallel insert chunks in addition to the analyze pass.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_chunks' AND column_name = 'phase'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_import_chunks
     ADD COLUMN phase ENUM('analyze','insert') NOT NULL DEFAULT 'insert' AFTER chunk_no,
     DROP KEY uniq_job_chunk,
     ADD UNIQUE KEY uniq_job_chunk_phase (import_job_id, chunk_no, phase)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
