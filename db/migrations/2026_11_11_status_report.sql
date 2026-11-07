-- «گزارش وضعیت‌ها» — the status report (public/status-report.php, app/Reports/StatusReport.php), and the
-- part counts (تعداد بخش) every main report now shows next to its message counts.
--
-- 1. send_channel on ellsms_message_attempts and ellsms_bulk_jobs — «عامل ارسال پیام». Panel and API
--    sends are both reference_type 'direct_send', so the channel is recorded at send time
--    (app/SendContext.php): 'panel', 'api', 'schedule' or 'autoreply' on an attempt, 'api' on a bulk
--    job created through the public API. NULL on rows written before this migration.
--
-- 2. ellsms_operator_report_daily gains `channel` and `parts` (the per-message part count) as
--    dimensions, so the operator report can sum parts (SUM(parts * message_count)) and the status
--    report can filter by «عامل ارسال پیام» and «تعداد بخش». The daily rows are rebuilt from the
--    message tables anyway, so the summary is emptied here and report-summary-worker rebuilds it
--    (OPERATOR_REPORT_BACKFILL_DAYS, newest first); until a day is rebuilt the reports count it live.
--    New stored statuses — failed_send, blacklisted, send_blacklist, blocked, invalid_sender — fit
--    the existing VARCHAR(16) status column (see app/Reports/OperatorReport.php).
--
-- 3. ellsms_send_drops — recipients the panel refused before sending (customer blacklist, opted out
--    with 11, a sender line the customer may not use). Grouped per send by (operator, encoding,
--    parts); an import job's or aimed-bulk request's drops count once that job/request was sent.
--    Backfilled once from the blacklist counters import jobs and aimed-bulk requests already keep.
--
-- Additive, guarded and rerun-safe. No foreign keys (same rationale as every ellsms_* table).
SET NAMES utf8mb4;

-- 1. send_channel ------------------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'send_channel'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD COLUMN send_channel VARCHAR(16) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND column_name = 'send_channel'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_jobs ADD COLUMN send_channel VARCHAR(16) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. channel + parts on the daily summary --------------------------------------------------------------
-- `layout` marks the days built with these dimensions (OPERATOR_REPORT_SUMMARY_LAYOUT = 2). The new
-- code reads and trusts only those, so a day an old worker rebuilds while a deploy is migrating (the
-- old containers keep running until the restart) is simply rebuilt again instead of lacking parts.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_days' AND column_name = 'layout'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_operator_report_days ADD COLUMN layout TINYINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_daily' AND column_name = 'channel'
);
-- Built days without the new dimensions would sum to zero parts: forget them so they are rebuilt.
SET @sql = IF(@col_exists = 0, 'DELETE FROM ellsms_operator_report_days', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@col_exists = 0, 'TRUNCATE TABLE ellsms_operator_report_daily', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_operator_report_daily
     ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT '' AFTER route_id,
     ADD COLUMN parts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER encoding,
     DROP PRIMARY KEY,
     ADD PRIMARY KEY (period_date, source, user_id, organization_id, sender, route_id, channel, operator, encoding, parts, status, hidden)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. recipients dropped before sending -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ellsms_send_drops (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at       DATETIME NOT NULL,                       -- UTC
  user_id          BIGINT NOT NULL,
  organization_id  INT UNSIGNED NOT NULL DEFAULT 0,
  sender           VARCHAR(32) NOT NULL DEFAULT '',
  channel          VARCHAR(16) NOT NULL DEFAULT 'panel',    -- app/SendContext.php SEND_CHANNELS
  reason           ENUM('blacklist','optout','invalid_sender') NOT NULL,
  operator         VARCHAR(120) NOT NULL DEFAULT '',        -- display name; '' = not known (backfill)
  encoding         ENUM('fa','en','na') NOT NULL DEFAULT 'na',
  parts            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  drop_count       INT UNSIGNED NOT NULL DEFAULT 0,
  import_job_id    BIGINT UNSIGNED NULL,                    -- counted once the import job was sent
  chunk_no         INT UNSIGNED NULL,                       -- the import chunk that recorded it
  aimed_request_id BIGINT UNSIGNED NULL,                    -- counted once the request was confirmed
  ref_key          VARCHAR(190) NULL,                       -- refType:refId — a retried send records once
  UNIQUE KEY uniq_send_drops_ref (ref_key, reason, sender, operator, encoding, parts),
  KEY idx_send_drops_created (created_at),
  KEY idx_send_drops_user (user_id, created_at),
  KEY idx_send_drops_org (organization_id, created_at),
  KEY idx_send_drops_import (import_job_id, chunk_no),
  KEY idx_send_drops_aimed (aimed_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill, once (only while the table is still empty): the blacklist counters import jobs and
-- aimed-bulk requests already keep. Their operator, encoding and parts were never recorded.
SET @drops_empty = (SELECT COUNT(*) = 0 FROM ellsms_send_drops);

SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_import_jobs' AND column_name = 'originator'
);
SET @sql = IF(@drops_empty = 1 AND @tbl_exists = 1,
  "INSERT INTO ellsms_send_drops (created_at, user_id, organization_id, sender, channel, reason, operator, encoding, parts, drop_count, import_job_id)
   SELECT ij.created_at, ij.user_id, ij.organization_id, ij.originator, ij.source_type, 'blacklist', '', 'na', 0, ij.blacklisted_rows, ij.id
   FROM ellsms_import_jobs ij
   WHERE ij.blacklisted_rows > 0",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_aimed_bulk_requests'
);
SET @sql = IF(@drops_empty = 1 AND @tbl_exists = 1,
  "INSERT INTO ellsms_send_drops (created_at, user_id, organization_id, sender, channel, reason, operator, encoding, parts, drop_count, aimed_request_id)
   SELECT COALESCE(r.confirmed_at, r.created_at), r.user_id, COALESCE(r.organization_id, 0), r.originator, 'bulk', 'blacklist', '',
          CASE WHEN CHAR_LENGTH(r.content) = 0 THEN 'na' WHEN OCTET_LENGTH(r.content) <> CHAR_LENGTH(r.content) THEN 'fa' ELSE 'en' END,
          CASE WHEN CHAR_LENGTH(r.content) = 0 THEN 0
               WHEN OCTET_LENGTH(r.content) <> CHAR_LENGTH(r.content) THEN IF(CHAR_LENGTH(r.content) <= 70, 1, CEIL(CHAR_LENGTH(r.content) / 67))
               ELSE IF(CHAR_LENGTH(r.content) <= 160, 1, CEIL(CHAR_LENGTH(r.content) / 153)) END,
          r.blacklisted_rows, r.id
   FROM ellsms_aimed_bulk_requests r
   WHERE r.blacklisted_rows > 0",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
