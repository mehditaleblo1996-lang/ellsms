-- ---------------------------------------------------------------------------
-- «گزارش اپراتورها بر اساس تأمین‌کننده» by SMS gateway.
--
-- The «بر اساس تأمین‌کننده» view of public/operator-reports.php used to list the
-- pricing catalog's providers (ellsms_sms_providers), which on most installs hold
-- only the seeded «ارائه‌دهنده‌ی پیش‌فرض» — none of the real suppliers. It now lists
-- the SMS gateways (ellsms_sms_gateways: Pishgamrayan, SMPP, …) and counts the
-- messages that actually left through the chosen one (bulk items' and message
-- attempts' gateway_id).
--
-- ellsms_operator_report_daily gains gateway_id (0 = none/unknown: legacy backend
-- sends, messages still in queue) and it joins the primary key. Days are written
-- with layout 3 from now on (OPERATOR_REPORT_SUMMARY_LAYOUT in
-- app/Reports/OperatorReport.php). Unfiltered reports keep reading layout-2 days;
-- the gateway-filtered view counts them live until report-summary-worker has
-- rebuilt them (newest first). A day whose messages are no longer all in the
-- tables (archived, or a restored backup) keeps its counts with gateway 0.
--
-- Additive, guarded, rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_daily');
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_report_daily' AND column_name = 'gateway_id');
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_operator_report_daily
     ADD COLUMN gateway_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER route_id,
     DROP PRIMARY KEY,
     ADD PRIMARY KEY (period_date, source, user_id, organization_id, sender, route_id, gateway_id, channel, operator, encoding, parts, status, hidden)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
