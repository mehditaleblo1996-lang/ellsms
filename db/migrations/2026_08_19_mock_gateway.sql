-- Mock/Sandbox SMS gateway support.
--
-- A fake gateway is impossible to accidentally select in production unless
-- ELLSMS_MOCK_GATEWAY_ENABLED=1 is explicitly set. The flag lives on the row
-- so the existing gateway-selection code can reject it with the same shape it
-- uses for any other unavailable gateway.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'is_mock'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_sms_gateways
     ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
