-- SMPP delivery-receipt mapping — docs/smpp-gateway.md §Delivery receipts, docs/delivery-states.md.
--
-- One nullable column on ellsms_sms_gateway_smpp_connectors: the gateway's own map from the receipt's
-- `stat` (DELIVRD, UNDELIV, EXPIRED, …) to an ELLSMS delivery state, set on Admin → درگاه‌های پیامک →
-- SMPP gateway → «نگاشت وضعیت رسید تحویل». NULL (every existing row) means the built-in default
-- (SMPP_DLR_DEFAULT_MAPPING in app/Sms/Smpp.php):
--   DELIVRD → delivered, UNDELIV → undelivered, EXPIRED → undelivered (final, charged, not refunded),
--   REJECTD → rejected, DELETED → failed (final, refunded), ACCEPTD → accepted, ENROUTE → sent,
--   UNKNOWN → unknown.
-- Before this migration UNDELIV / EXPIRED were `failed` / `expired`, which since
-- 2026_11_19_undelivered_state.sql are refunded in full; the operator still charges for them.
--
-- Additive, guarded and rerun-safe.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_smpp_connectors' AND column_name = 'status_mapping_json'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_sms_gateway_smpp_connectors ADD COLUMN status_mapping_json TEXT NULL AFTER dlr_id_format',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
