-- PishgamRayan (پیشگام‌رایان) SMS gateway — WebAPI v5.5 (https://api.pishgamrayan.com).
--
-- ADDITIVE AND INERT: creates ONE new gateway row (code `pishgamrayan`) with its send connector, status
-- connector and parameters. It touches no existing gateway, route, number, price or setting, and it is
-- not the default gateway — nothing sends through it until an operator points a route (route.gateway_id)
-- or a number (numbers page) at it. Idempotent: re-running never overwrites an existing row, so a
-- credential or mapping edited in the admin panel afterwards survives a re-run.
--
-- SEND   POST /Send  {"messageBodies":[..], "recipientNumbers":["98913..."], "senderNumber":"50003..."}
--          answer    {"message":"..","result":[<long>,..]}  — positional: result[i] belongs to
--                    recipientNumbers[i]; a positive number is the message id, a negative one is that
--                    recipient's own error code (-5 link, -9 bad number, -11 blacklist, ...). The engine's
--                    `position` correlation fails ONLY that recipient, never its neighbours.
--          limit     100 recipients per packet (-2 otherwise) -> batch mapping `max_recipients: 100`.
-- STATUS POST /StatusWithTime  body is a BARE array of ids `[42676161,..]` (max 100) -> the root-body
--          parameter `__root__` (app/Sms/GatewayConnector.php GATEWAY_ROOT_BODY_KEY).
--          answer    {"result":[{"messageId":..,"status":..,"dateTime":".."}]} — correlated BY ID.
--          Status/StatusWithTime only know the last 48h -> poll_max_age_seconds = 172800.
--
-- CREDENTIAL: the API takes ONE token, sent raw as `Authorization: <Api Key>` (no "Bearer"). The value
-- below is a TEST PLACEHOLDER. A secret cannot be encrypted from SQL, so replace it from
-- sms-gateways.php (gateway -> parameters: Authorization, on the send AND the status connector), ideally
-- by storing the token as a secret (`api_key`) and switching the parameter to value type "secret".
--
-- Requires SMS_GATEWAY_TRANSPORT=1 to be used at all, exactly like every other gateway.
SET NAMES utf8mb4;

INSERT INTO ellsms_sms_gateways (code, name, description, status, is_default, send_mode, send_enabled, status_enabled)
SELECT 'pishgamrayan', 'پیشگام‌رایان', 'WebAPI v5.5 — api.pishgamrayan.com (Send / StatusWithTime)', 'active', 0, 'batch', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM ellsms_sms_gateways WHERE code = 'pishgamrayan');

SET @gid = (SELECT id FROM ellsms_sms_gateways WHERE code = 'pishgamrayan' LIMIT 1);

-- ---------------------------------------------------------------------------
-- Send connector
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO ellsms_sms_gateway_send_connectors
  (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify,
   auth_type, success_rule_json, response_mapping_json, error_mapping_json, batch_mapping_json)
VALUES
  (@gid, 'https://api.pishgamrayan.com/Send', 'POST', 'application/json', 5000, 30000, 1,
   'none',
   '{"http":{"min":200,"max":299},"require_json":true}',
   '{"error_message":"message"}',
   NULL,
   '{"correlation_mode":"position","provider_ids_path":"result","max_recipients":100}');

-- ---------------------------------------------------------------------------
-- Status connector
-- ---------------------------------------------------------------------------
-- Provider status -> canonical state (docs: Status codes). An unmapped code becomes `unknown`, never
-- `delivered`. Code 5 (partially delivered) is deliberately NOT `delivered`: it stays non-terminal
-- (`sent`) so a later 10 can still settle it; reporting a partly-delivered message as delivered would
-- be the one error that has real-world consequences.
--   -1 not found -> unknown      0 pending -> queued          1 sent to telecom -> sent
--    2 not reached telecom -> failed   3 blacklist -> rejected   4 not delivered to handset -> failed
--    5 partially delivered -> sent     10 delivered -> delivered 13 send error -> failed
INSERT IGNORE INTO ellsms_sms_gateway_status_connectors
  (gateway_id, endpoint_url, http_method, content_type, connect_timeout_ms, request_timeout_ms, tls_verify,
   auth_type, response_mapping_json, status_mapping_json,
   poll_initial_delay_seconds, poll_max_attempts, poll_max_age_seconds)
VALUES
  (@gid, 'https://api.pishgamrayan.com/StatusWithTime', 'POST', 'application/json', 5000, 15000, 1,
   'none',
   '{"items_path":"result","id_path":"messageId","status_path":"status","delivered_at_path":"dateTime"}',
   '{"-1":"unknown","0":"queued","1":"sent","2":"failed","3":"rejected","4":"failed","5":"sent","10":"delivered","13":"failed"}',
   120, 40, 172800);

-- ---------------------------------------------------------------------------
-- Parameters. active_slot format: '<gateway>:<connector>:<location>:<scope>:<scope_id>:<key>'
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO ellsms_sms_gateway_parameters
  (gateway_id, connector, location, scope, scope_id, param_key, value_type, value, data_type, status, sort_order, active_slot)
VALUES
  -- send: raw API token header (TEST PLACEHOLDER — replace), then the three body fields
  (@gid, 'send',   'header', 'gateway', NULL, 'Authorization',    'static',   'TEST_API_KEY_REPLACE_ME', 'string',       'active', 0, CONCAT(@gid, ':send:header:gateway::Authorization')),
  (@gid, 'send',   'body',   'gateway', NULL, 'messageBodies',    'variable', 'messages_array',         'string_array',  'active', 1, CONCAT(@gid, ':send:body:gateway::messageBodies')),
  (@gid, 'send',   'body',   'gateway', NULL, 'recipientNumbers', 'variable', 'recipients_array',       'string_array',  'active', 2, CONCAT(@gid, ':send:body:gateway::recipientNumbers')),
  (@gid, 'send',   'body',   'gateway', NULL, 'senderNumber',     'variable', 'sender',                 'string',        'active', 3, CONCAT(@gid, ':send:body:gateway::senderNumber')),
  -- status: same token header, and the whole body is the bare array of provider message ids
  (@gid, 'status', 'header', 'gateway', NULL, 'Authorization',    'static',   'TEST_API_KEY_REPLACE_ME', 'string',       'active', 0, CONCAT(@gid, ':status:header:gateway::Authorization')),
  (@gid, 'status', 'body',   'gateway', NULL, '__root__',         'variable', 'provider_message_ids',   'integer_list',  'active', 1, CONCAT(@gid, ':status:body:gateway::__root__'));
