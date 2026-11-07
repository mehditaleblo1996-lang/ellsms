-- ---------------------------------------------------------------------------
-- Part totals («تعداد پارت») in the pre-aggregated report tables.
--
-- The reports now show, next to each message count, how many SMS parts those
-- messages were (docs/message-parts.md §Reports). Two report tables are built
-- ahead of time and need the part total as well:
--
--   ellsms_operator_report_daily      operator reports, «گزارش تعداد پیام‌های ارسالی»
--     part_count — NULL on rows built before this migration. A day counts as
--     having part totals only once ellsms_operator_report_days.parts_built = 1;
--     until then the reports count that day live from the message tables (as they
--     already do for a day not built yet), and report-summary-worker rebuilds it,
--     newest first, whatever its age (operator_report_worker_pass()).
--
--   ellsms_report_daily_dimension_summary   breakdown of «گزارش ارسال حجیم»
--     part_count — filled by the next full rebuild, which this migration
--     brings forward to the worker's next pass (last_full_rebuild_at = NULL).
--
--   ellsms_send_dimension_log   direct / scheduled / auto-reply sends folded into
--     the same breakdown (app/Reports/SendDimensionLog.php)
--     part_count — the parts of a row's messages (every recipient of one send
--     shares one text), carried into the breakdown with its message_count.
--
-- Additive, guarded, rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_daily');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_daily' AND column_name = 'part_count');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_operator_report_daily ADD COLUMN part_count BIGINT UNSIGNED NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_days');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_days' AND column_name = 'parts_built');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_operator_report_days ADD COLUMN parts_built TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_report_daily_dimension_summary');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_report_daily_dimension_summary' AND column_name = 'part_count');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_report_daily_dimension_summary ADD COLUMN part_count BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Only when the column was just added: the next worker pass rebuilds the whole table with part totals.
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'UPDATE ellsms_report_dimension_summary_state SET last_full_rebuild_at = NULL WHERE id = 1',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_send_dimension_log');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_send_dimension_log' AND column_name = 'part_count');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_send_dimension_log ADD COLUMN part_count INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
