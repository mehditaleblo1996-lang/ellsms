-- ---------------------------------------------------------------------------
-- «گزارش ارسال حجیم» breakdown: stop the hourly full rebuild from deleting what
-- it cannot rebuild (docs/daily-metadata-aggregation.md §Sources).
--
-- ellsms_report_daily_dimension_summary was fed by two writers — the bulk pass
-- (from ellsms_bulk_items) and the send-log pass (direct / scheduled /
-- auto-reply sends, from ellsms_send_dimension_log) — while the bulk pass's
-- hourly full rebuild emptied the whole table and refilled it from the bulk
-- items alone. Every direct-send row was gone within the hour (the send pass
-- never folds a log row twice), and so was every day whose items had been
-- archived, and, after a restore, every day the backup kept only as summary.
--
-- Now:
--   - direct / scheduled / auto-reply sends are summed into their own table,
--     ellsms_report_daily_send_summary, which the bulk rebuild never touches;
--     the breakdown reads both. Its cursor (summary_log_id) starts at 0, so the
--     whole send log — every row lost so far included — is folded in again.
--   - the bulk rebuild replaces only the days its live items still hold, and
--     never a day before an archive run's cutoff (report_dimension_summary_full_rebuild()).
--   - rebuild_all = 1 makes the first rebuild after this migration empty the
--     bulk table once, clearing the direct-send rows mixed into it until now
--     (they are in the new table instead). It runs on the worker's next pass.
--
-- Additive, guarded, rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_report_daily_send_summary (
  period_date     DATE NOT NULL,
  organization_id INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = no tenant
  message_type    VARCHAR(40) NOT NULL,               -- MessageClass.php canonical class
  sender_number   VARCHAR(20) NOT NULL DEFAULT '',
  route_id        INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = legacy backend
  operator_id     INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = unresolved
  status          VARCHAR(20) NOT NULL,               -- sent / failed (at dispatch)
  message_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  part_count      BIGINT UNSIGNED NOT NULL DEFAULT 0, -- SMS parts of those messages (docs/message-parts.md)
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (period_date, organization_id, message_type, sender_number, route_id, operator_id, status),
  KEY idx_send_summary_period (period_date),
  KEY idx_send_summary_org_period (organization_id, period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_send_dimension_summary_state');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_send_dimension_summary_state' AND column_name = 'summary_log_id');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_send_dimension_summary_state ADD COLUMN summary_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_report_dimension_summary_state');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_report_dimension_summary_state' AND column_name = 'rebuild_all');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_report_dimension_summary_state ADD COLUMN rebuild_all TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Only when the column was just added: one full emptying rebuild, on the worker's next pass.
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'UPDATE ellsms_report_dimension_summary_state SET rebuild_all = 1, last_full_rebuild_at = NULL WHERE id = 1',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
