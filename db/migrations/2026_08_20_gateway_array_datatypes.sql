-- Array data types for ManyToMany gateway request bodies.
--
-- string_array : ["+989...","+989..."]
-- numeric_array: [50004940,50004940]  (numeric-when-numeric)
-- integer_array: [900000000000000001] (canonical decimal tokens, BIGINT-safe)
SET NAMES utf8mb4;

SET @needs_type = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_parameters'
    AND column_name = 'data_type' AND column_type LIKE '%string_array%'
);
SET @sql = IF(@needs_type = 1,
  "ALTER TABLE ellsms_sms_gateway_parameters
     MODIFY COLUMN data_type ENUM('string','integer','boolean','null','json','string_list','numeric','integer_list','string_array','numeric_array','integer_array')
       NOT NULL DEFAULT 'string'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
