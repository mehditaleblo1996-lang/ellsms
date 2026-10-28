-- Number details (/numbers.php → «ویرایش شماره»), modelled on the classic SMS-panel number record:
--   status            active / inactive / blocked / deleted / transferred / reserved. Only an
--                     'active' line can be sent from (authorization.php, number_unsendable_map());
--                     inbound SMS, the inbox and delivery reports are not affected.
--   expires_on        last day (Tehran) the line may send; NULL = never expires.
--   priority          message priority 1..10, recorded and shown only.
--   bandwidth / bandwidth_max   allotted bandwidth and the ceiling that may be allotted, recorded only.
--   confidential      «ارسال محرمانه», recorded only.
--   pay_inbound_cost  «پرداخت هزینه پیامک دریافتی», recorded only.
--   commitment_file   uploaded «تعهدنامه شماره», stored under storage/number-commitments/.
-- Every existing line becomes 'active' with no expiry, so sending keeps working exactly as before.
-- Each column is guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'status'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN status ENUM(''active'',''inactive'',''blocked'',''deleted'',''transferred'',''reserved'') NOT NULL DEFAULT ''active''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'expires_on'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN expires_on DATE NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'priority'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 5', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'bandwidth'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN bandwidth INT UNSIGNED NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'bandwidth_max'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN bandwidth_max INT UNSIGNED NOT NULL DEFAULT 20', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'confidential'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN confidential TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'pay_inbound_cost'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN pay_inbound_cost TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'commitment_file'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN commitment_file VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND index_name = 'idx_numbers_status'
);
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE ellsms_numbers ADD KEY idx_numbers_status (status, expires_on)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
