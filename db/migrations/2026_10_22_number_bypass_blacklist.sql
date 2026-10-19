-- Service lines (شماره‌ی خدماتی): a sender line whose sends skip the do-not-contact list
-- (ellsms_blacklist), like Vesal's Number.byPassBlackList (column bypassblacklist), which
-- MTSMSServiceImpl checks before checkBlackList(). Line opt-outs ("11", ellsms_line_optouts) are
-- NOT skipped — as in Vesal, where a bypass line still honours the internal blacklist.
-- 0 (the default) = every existing line keeps being checked exactly as before. Toggled by a
-- platform admin on /numbers.php. See docs/service-lines.md.
-- Guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'bypass_blacklist'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_numbers ADD COLUMN bypass_blacklist TINYINT(1) NOT NULL DEFAULT 0 AFTER label',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
