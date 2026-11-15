-- PishgamRayan (پیشگام‌رایان): label every Send request with the bulk item ids it carries —
-- docs/sms-gateway-connectors.md «PishgamRayan and duplicate sends».
--
-- PishgamRayan's Send has no client message id and no duplicate check, so a bulk row whose outcome is
-- unknown ([SEND_UNCERTAIN], a timeout) is never resent automatically (docs/job-queue-architecture.md
-- «No automatic resend for bulk items»). To find out whether such a row actually went out, the admin
-- looks it up in PishgamRayan's own report: this adds the optional body field `userTag` (≤ 40
-- characters) filled from the `request_tag` variable — 'ellsms:<first>-<last>' bulk item id of the
-- request, 'ellsms' for a direct/API send.
--
-- Only the seeded gateway (code `pishgamrayan`). Another gateway on PishgamRayan's WebAPI gets the same
-- parameter in «درگاه‌های پیامک» → send connector parameters: body, `userTag`, variable `request_tag`,
-- string. Rerun-safe: the parameter is added only when the gateway has no active `userTag` yet, and the
-- config version is bumped only then.
SET NAMES utf8mb4;

SET @gid = (SELECT id FROM ellsms_sms_gateways WHERE code = 'pishgamrayan' LIMIT 1);

INSERT IGNORE INTO ellsms_sms_gateway_parameters
  (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
SELECT @gid, 'send', 'body', 'gateway', NULL, 'userTag', 'variable', 'request_tag', 'string', 'active', 4,
       CONCAT(@gid, ':send:body:gateway::userTag')
FROM DUAL
WHERE @gid IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ellsms_sms_gateway_parameters
                  WHERE gateway_id = @gid AND connector = 'send' AND param_key = 'userTag' AND status = 'active');
SET @added = ROW_COUNT();

-- The compiled-connector cache keys on the gateway's config version (app/Sms/GatewayCache.php).
UPDATE ellsms_sms_gateways SET config_version = config_version + 1 WHERE id = @gid AND @added > 0;
