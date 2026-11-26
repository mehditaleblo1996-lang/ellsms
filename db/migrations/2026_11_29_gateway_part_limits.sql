-- Per-gateway SMS part sizes («تعداد کاراکتر هر پارت», docs/gateway-part-limits.md, app/SmsPartLimits.php).
--
-- Four nullable columns on ellsms_sms_gateways: how many characters one part of a message holds when
-- it goes through that gateway. NULL means the platform default — Persian/Unicode 70 for a one-part
-- message and 67 per part beyond that, Latin 160 and 153 — so every existing gateway is unchanged.
-- The part count of a send (what is priced, charged and stored in `parts`) and, on an SMPP gateway,
-- how the bridge splits the text, follow these numbers.
--
-- Additive and rerun-safe: each column is added only when missing.
SET NAMES utf8mb4;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'part_fa_single');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_sms_gateways ADD COLUMN part_fa_single SMALLINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'part_fa_multi');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_sms_gateways ADD COLUMN part_fa_multi SMALLINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'part_en_single');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_sms_gateways ADD COLUMN part_en_single SMALLINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'part_en_multi');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_sms_gateways ADD COLUMN part_en_multi SMALLINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
