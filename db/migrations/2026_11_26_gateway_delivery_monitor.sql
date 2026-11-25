-- UTC time when a gateway receipt or poll first recorded a final outcome.
SET @sql = IF(EXISTS(
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ellsms_bulk_items'
    AND COLUMN_NAME = 'delivery_finalized_at'
), 'SELECT 1',
  'ALTER TABLE ellsms_bulk_items
     ADD COLUMN delivery_finalized_at DATETIME NULL,
     ADD KEY idx_bulk_delivery_finalized (delivery_finalized_at, gateway_id, delivery_status)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS(
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ellsms_message_attempts'
    AND COLUMN_NAME = 'delivery_finalized_at'
), 'SELECT 1',
  'ALTER TABLE ellsms_message_attempts
     ADD COLUMN delivery_finalized_at DATETIME NULL,
     ADD KEY idx_attempt_delivery_finalized (delivery_finalized_at, gateway_id, delivery_status)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
