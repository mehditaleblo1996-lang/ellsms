-- PishgamRayan (پیشگام‌رایان): only status 10 («رسیده به گوشی») is final — docs/sms-gateway-connectors.md.
--
-- The provider answers a status check with 4 («نرسیده به گوشی») minutes after a send while the operator
-- is still retrying the handset, and later turns many of those into 10. The seeded mapping
-- (2026_10_04_pishgamrayan_gateway.sql) called 2, 3, 4 and 13 `failed`/`rejected` — final states — so
-- polling stopped and a later 10 could never be recorded. From now on every code except 10 is `sent`
-- («ارسال‌شده»), the message keeps being checked until it is 10 or 48 hours old, and the raw code stays
-- in provider_status for the admin. A SEND error (a negative id in the Send answer) is still a failed
-- message — that is the send connector, not this mapping.
--
-- 1. ellsms_sms_gateway_status_connectors.poll_backoff_max_seconds (0 = no backoff, every existing
--    connector unchanged): when set, the wait between status checks doubles after each check up to this
--    many seconds (app/Sms/GatewayStatus.php gateway_status_poll_gap()).
-- 2. PishgamRayan's status connector: the new mapping, unlimited checks within 48 hours, backoff
--    120 s → 1 hour (about 50 checks per undelivered message instead of one every 2 minutes).
--
-- Rows already stored as failed with a code other than 10 are reopened separately, in small chunks:
--   php cron/gateway-status-reopen.php --gateway=pishgamrayan            (dry run: counts)
--   php cron/gateway-status-reopen.php --gateway=pishgamrayan --apply
--
-- Additive, guarded and rerun-safe. Applying it again re-applies the mapping above, overwriting an
-- admin's later edit of PishgamRayan's status mapping.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_status_connectors' AND column_name = 'poll_backoff_max_seconds'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_sms_gateway_status_connectors
     ADD COLUMN poll_backoff_max_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER poll_max_age_seconds',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE ellsms_sms_gateway_status_connectors sc
JOIN ellsms_sms_gateways g ON g.id = sc.gateway_id AND g.code = 'pishgamrayan'
SET sc.status_mapping_json = '{"-1":"sent","0":"sent","1":"sent","2":"sent","3":"sent","4":"sent","5":"sent","10":"delivered","13":"sent"}',
    sc.poll_max_attempts = 0,
    sc.poll_max_age_seconds = 172800,
    sc.poll_backoff_max_seconds = 3600;

-- The compiled-connector cache keys on the gateway's config version (app/Sms/GatewayCache.php).
UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE code = 'pishgamrayan';
