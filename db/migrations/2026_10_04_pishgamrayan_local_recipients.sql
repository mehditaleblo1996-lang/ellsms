-- PishgamRayan rejects recipients in the 98 form (989197684063) — it wants the national form
-- (09197684063). Switch ONLY this gateway's recipientNumbers to the `recipients_local_array` variable,
-- which rewrites 98/+98/0098 mobiles to 0... and leaves every other gateway untouched.
-- Requires the code that defines the variable (gateway_local_msisdn) to be deployed first.
SET NAMES utf8mb4;

UPDATE ellsms_sms_gateway_parameters p
JOIN ellsms_sms_gateways g ON g.id = p.gateway_id
SET p.value = 'recipients_local_array'
WHERE g.code = 'pishgamrayan' AND p.connector = 'send' AND p.param_key = 'recipientNumbers'
  AND p.status = 'active' AND p.value = 'recipients_array';

-- Running workers recompile a gateway only when its config_version changes.
UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE code = 'pishgamrayan';
