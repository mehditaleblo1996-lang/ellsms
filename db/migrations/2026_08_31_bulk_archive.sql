-- Six-month admin-approved archive workflow for ellsms_bulk_items (issue #13).
--
-- docs/database-audit.md flagged ellsms_bulk_items as one of four ELLSMS-owned tables that "grow
-- without bound and are never pruned," and its Phase 5 update explicitly left it "permanent by
-- policy" pending a real retention decision. This is that decision, implemented as a controlled,
-- admin-approved, resumable move to cold storage -- never an automatic silent purge, and never a
-- destructive DELETE with no way back (see app/BulkArchive.php's restore path).
--
-- ellsms_bulk_jobs is NOT archived: it is small (one row per campaign, not per recipient) and stays
-- in place so an archived item's job_id always still resolves.
SET NAMES utf8mb4;

-- Full original row preserved as JSON rather than mirroring ellsms_bulk_items' column list 1:1 --
-- that list has grown across five separate migrations already (pricing, gateway routing, delivery
-- polling...) and will keep growing; a fixed mirrored schema would need updating every time or
-- silently drop new columns on archive. `id` is the SAME id the item had in ellsms_bulk_items (not
-- a fresh autoincrement), so re-archiving the same row twice is a harmless no-op (INSERT ... ON
-- DUPLICATE KEY UPDATE id = id) rather than a duplicate.
CREATE TABLE IF NOT EXISTS ellsms_bulk_items_archive (
  id              BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  job_id          INT UNSIGNED NOT NULL,
  status          VARCHAR(20) NOT NULL,
  created_at      TIMESTAMP NOT NULL,
  archive_run_id  BIGINT UNSIGNED NOT NULL,
  archived_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload         JSON NOT NULL,
  KEY idx_archive_job (job_id),
  KEY idx_archive_created (created_at),
  KEY idx_archive_run (archive_run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per archive cycle: preview snapshot -> explicit approval -> resumable chunked execution.
-- last_archived_item_id is the same high-water-mark-in-one-transaction-per-chunk shape as issue
-- #12's report aggregation worker, for the same reason: a crash mid-chunk must leave no partial
-- effect, and a rerun must resume exactly where the last committed chunk left off.
CREATE TABLE IF NOT EXISTS ellsms_bulk_archive_runs (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status                  ENUM('pending_approval','approved','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending_approval',
  cutoff_date             DATE NOT NULL,
  reason                  VARCHAR(500) NOT NULL DEFAULT '',
  requested_by_user_id    BIGINT NOT NULL,
  approved_by_user_id     BIGINT NULL,
  approved_at             DATETIME NULL,
  preview_count           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  preview_min_created_at  DATETIME NULL,
  preview_max_created_at  DATETIME NULL,
  last_archived_item_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rows_archived           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  started_at              DATETIME NULL,
  completed_at            DATETIME NULL,
  error_message           VARCHAR(500) NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_archive_run_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
