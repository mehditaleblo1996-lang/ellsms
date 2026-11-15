-- Delivery state `undelivered` («تحویل داده‌نشده») and free failed messages — docs/delivery-states.md.
--
-- 1. `undelivered` is appended to the delivery_status ENUM of ellsms_bulk_items and
--    ellsms_message_attempts. Appending keeps every existing value and its stored index, so no row
--    changes. It is a FINAL state an admin maps a provider code to (e.g. PishgamRayan {"4":"undelivered"}):
--    polling stops, the message is charged and NOT refunded, and the customer sees it as not delivered.
-- 2. Setting `delivery_failed_free_since` = now. A message SENT at or after this moment whose final
--    delivery state is failed / rejected / expired (blacklisted is stored as rejected) is refunded 100%
--    for every customer, whether or not delivery-based billing is on (app/DeliveryRefunds.php).
--    Messages sent before it keep the old rule (refund only under delivery-based billing, at its
--    percentage), so nothing already sent is re-charged or newly refunded.
--
-- Additive, guarded and rerun-safe: the ENUM is changed only while it lacks 'undelivered', and the
-- setting is never overwritten (INSERT IGNORE), so re-running it cannot move the cutoff.
SET NAMES utf8mb4;

SET @needs = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'delivery_status'
    AND column_type NOT LIKE '%''undelivered''%'
);
SET @sql = IF(@needs > 0,
  'ALTER TABLE ellsms_bulk_items
     MODIFY COLUMN delivery_status ENUM(''accepted'',''queued'',''sent'',''delivered'',''failed'',''rejected'',''expired'',''unknown'',''undelivered'') NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'delivery_status'
    AND column_type NOT LIKE '%''undelivered''%'
);
SET @sql = IF(@needs > 0,
  'ALTER TABLE ellsms_message_attempts
     MODIFY COLUMN delivery_status ENUM(''accepted'',''queued'',''sent'',''delivered'',''failed'',''rejected'',''expired'',''unknown'',''undelivered'') NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO ellsms_settings (skey, svalue)
VALUES ('delivery_failed_free_since', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'));
