-- SMS gateway status connector — batch request variables.
--
-- Adds the `integer_list` parameter data type: a JSON array of NUMBERS built from canonical decimal
-- strings. It exists because real delivery-status APIs take many provider message ids per lookup and
-- those ids are commonly 19 digits:
--
--   {"referenceids": [7310136179845801812, 776846774851635393]}
--
-- PHP's float carries 53 bits of mantissa, so 7310136179845801812 becomes 7310136179845801800 the
-- moment it touches one — three digits different, correlating to nothing, and indistinguishable at a
-- glance from "the provider has no record of this message". The value therefore stays a canonical
-- decimal STRING everywhere in this system, and only the JSON encoder emits it as an unquoted numeric
-- token (app/Sms/GatewayConnector.php, gateway_json_encode_body()). `integer_list` is the type that
-- selects that behaviour.
--
-- Distinct from `string_list`, which emits ["a","b"] — quoted strings. A provider that wants numbers
-- rejects quoted ids, and a provider that wants strings rejects unquoted ones; the difference is not
-- cosmetic, so it is a separate type rather than a guess.
--
-- NO GENERATED COLUMNS (TD-070). Schema-only: no existing parameter row changes type or meaning, and
-- an install that never configures a status connector is completely unaffected.
SET NAMES utf8mb4;

SET @needs_type = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_parameters'
    AND column_name = 'data_type' AND column_type LIKE '%integer_list%'
);
SET @sql = IF(@needs_type = 1,
  "ALTER TABLE ellsms_sms_gateway_parameters
     MODIFY COLUMN data_type ENUM('string','integer','boolean','null','json','string_list','numeric','integer_list')
       NOT NULL DEFAULT 'string'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A status connector may now carry additional success CONDITIONS.
--
-- Real status APIs answer HTTP 200 with a provider-level error inside the body:
--
--   {"states": [...], "errorModel": {"errorCode": 0, "timestamp": null}}
--
-- A non-zero errorCode means the lookup failed, and treating its (absent or stale) `states` as
-- delivery data would write fabricated delivery states onto real messages.
--
-- STRICTLY ADDITIVE, and enforced as such in code: the base rule (HTTP 2xx AND a parseable JSON body)
-- is always applied and is NOT configurable here. Only extra `rules` entries are read from this
-- column, so a configuration can make success HARDER to achieve and never easier. That is what makes
-- this safe to expose at all -- the original reason for withholding it was that a knob able to relax
-- the rule would let a failed poll be read as a delivery report.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_status_connectors'
    AND column_name = 'success_rule_json'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_sms_gateway_status_connectors ADD COLUMN success_rule_json JSON NULL AFTER auth_config_json',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
