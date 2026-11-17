-- SMPP gateway to the SMSC at 192.168.111.80:40112 — docs/smpp-gateway.md §The 192.168.111.80 gateway.
--
-- ADDITIVE AND INERT: creates ONE new gateway (code `smpp_192_168_111_80`, protocol SMPP) with its SMPP
-- settings, exactly as «درگاه جدید» → SMPP in the panel would. It touches no existing gateway, route,
-- number, price or setting and is not the default gateway, so nothing sends through it until an admin
-- points a route or a number at it. Idempotent: an existing row is never overwritten, so the settings
-- and credentials entered in the panel survive a re-run.
--
-- CREDENTIALS ARE NOT HERE. system_id is left empty and no password is stored (a secret cannot be
-- encrypted from SQL): until an admin enters both on Admin → درگاه‌های پیامک → this gateway →
-- «تنظیمات SMPP», the bridge shows "no smpp_password secret stored" and binds nothing.
--
-- Settings are the panel defaults (smpp_connector_defaults()): one TRX session, 50 TPS, window 10,
-- source TON/NPI 5/0, destination 1/1 in international format (98912…), automatic GSM/UCS-2, UDH for
-- long messages, delivery receipts requested, received messages stored. Change them in the panel to
-- what the operator specifies.
--
-- Requires 2026_10_06_smpp_gateway.sql (tables) and the smpp-bridge service; sending through any
-- gateway requires SMS_GATEWAY_TRANSPORT=1.
SET NAMES utf8mb4;

INSERT INTO ellsms_sms_gateways (code, protocol, name, description, status, is_default, send_mode, send_enabled, status_enabled, config_version)
SELECT 'smpp_192_168_111_80', 'smpp', 'SMPP 192.168.111.80', 'SMSC 192.168.111.80:40112 (SMPP 3.4)', 'active', 0, 'batch', 1, 0, 1
WHERE NOT EXISTS (SELECT 1 FROM ellsms_sms_gateways WHERE code = 'smpp_192_168_111_80');

SET @gid = (SELECT id FROM ellsms_sms_gateways WHERE code = 'smpp_192_168_111_80' LIMIT 1);

INSERT IGNORE INTO ellsms_sms_gateway_smpp_connectors
  (gateway_id, host, port, use_tls, system_id, system_type, interface_version, bind_mode, session_count, tps, window_size,
   enquire_link_s, reconnect_delay_s, submit_timeout_ms, source_ton, source_npi, dest_ton, dest_npi, data_coding,
   long_message, registered_delivery, validity_minutes, destination_format, dlr_id_format, receive_enabled)
VALUES
  (@gid, '192.168.111.80', 40112, 0, '', '', '3.4', 'trx', 1, 50, 10,
   30, 5, 10000, 5, 0, 1, 1, 'auto',
   'udh', 1, 0, 'international', 'auto', 1);

-- The panel creates an (unused) empty send connector for every gateway; mirrored so the gateway looks
-- exactly like one made there.
INSERT IGNORE INTO ellsms_sms_gateway_send_connectors (gateway_id, endpoint_url, success_rule_json)
VALUES (@gid, '', '{"http":{"min":200,"max":299},"require_json":true,"rules":[]}');
