-- ELLSMS staging sanitizer. Run ONLY against the staging copy of production's database --
-- ops/staging/stage.sh applies it (and refuses unless the target database name contains "stage").
-- Idempotent: stage.sh runs it before AND after seeding the mock gateway.
--
-- After it, nothing the staging workers do reaches a real SMS provider, a customer's webhook URL,
-- a customer's database, production's backend API, Telegram chat or payment account, and none of
-- production's queued work is replayed.

-- 1. SMS gateways: every real gateway archived and disabled (the smpp-bridge only binds 'active'
--    gateways, and the status/receive pollers skip disabled ones). Once the mock gateway exists it
--    becomes the default and takes over every route and every pinned sender number.
UPDATE ellsms_sms_gateways SET default_slot = NULL WHERE is_mock = 0;
UPDATE ellsms_sms_gateways
   SET status = 'archived', is_default = 0, send_enabled = 0, status_enabled = 0, config_version = config_version + 1
 WHERE is_mock = 0 AND (status <> 'archived' OR send_enabled <> 0 OR status_enabled <> 0);

SET @mock := (SELECT id FROM ellsms_sms_gateways WHERE code = 'mock' AND is_mock = 1 LIMIT 1);
UPDATE ellsms_sms_gateways
   SET status = 'active', is_default = 1, default_slot = 1, send_enabled = 1, status_enabled = 1, config_version = config_version + 1
 WHERE id = @mock;
UPDATE ellsms_sms_routes SET gateway_id = @mock WHERE @mock IS NOT NULL AND gateway_id IS NOT NULL AND gateway_id <> 0 AND gateway_id <> @mock;
UPDATE ellsms_numbers    SET gateway_id = @mock WHERE @mock IS NOT NULL AND gateway_id IS NOT NULL AND gateway_id <> @mock;

-- 2. Production's queued work: cancelled, never sent from staging.
UPDATE ellsms_bulk_jobs              SET status = 'cancelled' WHERE status IN ('pending', 'processing', 'staged');
UPDATE ellsms_bulk_items             SET status = 'cancelled' WHERE status IN ('pending', 'processing');
UPDATE ellsms_schedule               SET status = 'cancelled' WHERE status IN ('active', 'processing');
UPDATE ellsms_direct_send_queue      SET status = 'failed'    WHERE status IN ('queued', 'processing');
UPDATE ellsms_import_jobs            SET status = 'cancelled' WHERE status IN ('uploaded', 'analyzing', 'ready_for_confirmation', 'queued', 'sending');
UPDATE ellsms_regional_bulk_requests SET status = 'cancelled' WHERE status IN ('draft', 'priced', 'confirmed', 'sending');

-- 3. Outbound integrations owned by customers: off.
UPDATE ellsms_webhook_endpoints   SET enabled = 0, disabled_reason = 'staging copy' WHERE enabled <> 0;
UPDATE ellsms_webhook_deliveries  SET status = 'dead_letter' WHERE status IN ('pending', 'processing');
UPDATE ellsms_remote_db_connections SET enabled = 0 WHERE enabled <> 0;

-- 4. Admin settings stored in the database win over .env (app/bootstrap.php setting()), so the
--    production values are removed here and staging falls back to its own .env (or nothing).
DELETE FROM ellsms_settings WHERE skey IN (
  'api_base_url',
  'telegram_bot_token', 'telegram_chat_id', 'telegram_webhook_secret', 'telegram_bot_allowed_chats',
  'zarinpal_merchant_id', 'zarinpal_callback_url', 'zarinpal_sandbox',
  'alert_email_recipient', 'registration_admin_mobiles',
  'bale_base_url', 'bale_bot_id',
  'vesal_bulk_base_url', 'vesal_bulk_username',
  'voice_api_key', 'voice_base_url'
);
UPDATE ellsms_settings SET svalue = '0' WHERE skey IN ('bale_enabled', 'regional_bulk_enabled', 'voice_enabled');

-- 5. TOTP secrets were encrypted with production's MFA_MASTER_KEY, which staging does not have:
--    without this nobody with 2FA could log in. Users can enrol again on staging.
DELETE FROM ellsms_totp_mfa;
