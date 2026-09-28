-- #35 — low-credit alerts are actually sent (app/LowCreditAlerts.php).
--
-- ellsms_organization_notification_preferences already stores whether an organization wants a
-- low-credit alert, its threshold, channels and recipients (2026_08_12_customer_profile.sql), but
-- nothing ever sent one. These two columns are the sender's bookkeeping:
--   last_low_credit_alert_at  when the last alert went out (NULL = none since the last top-up)
--   low_credit_alert_count    alerts sent since the balance last rose back above the threshold
-- Together they let the worker send at most one alert per period and stop after a fixed number
-- until the organization tops up, which resets both. Plain columns, rerun-safe.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_notification_preferences'
    AND column_name = 'last_low_credit_alert_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_organization_notification_preferences
     ADD COLUMN last_low_credit_alert_at TIMESTAMP NULL DEFAULT NULL AFTER alert_mobile,
     ADD COLUMN low_credit_alert_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_low_credit_alert_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
