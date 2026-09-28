-- #37 — inbound SMS (MO) in gateway mode.
--
-- With SMS_GATEWAY_TRANSPORT=1 ELLSMS sends straight to the provider, but inbound messages were still
-- read only from the legacy backend's `inbound_message` table, which a gateway-only install never
-- fills: the inbox stayed empty and the auto-responder never ran. This adds a RECEIVE connector (the
-- provider's "pull received messages" API, e.g. Vesal's /pullReceivedMessages) and ELLSMS's own
-- store for what it returns. app/Sms/GatewayReceive.php polls; app/Backend/messages.php reads both
-- stores. No generated columns (TD-070).
SET NAMES utf8mb4;

-- 1. Parameters may now belong to a receive connector.
SET @needs_receive = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateway_parameters'
    AND column_name = 'connector' AND column_type LIKE '%receive%'
);
SET @sql = IF(@needs_receive = 1,
  "ALTER TABLE ellsms_sms_gateway_parameters MODIFY COLUMN connector ENUM('send','status','receive') NOT NULL DEFAULT 'send'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. The receive connector: where to ask, how to read the answer, how often.
--    response_mapping_json: {"rows_path":"messageModels","sender_key":"originator","line_key":"destination",
--                            "content_key":"content","received_at_key":"insertDate","id_key":""}
--    per_line = 1 asks once per sender line routed to this gateway (the `line` variable);
--    lookback_seconds is the window re-read on every poll, so a message the provider already marked
--    as read is still picked up after a failed poll — duplicates are dropped by the unique key below.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_receive_connectors (
  gateway_id            INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled               TINYINT(1) NOT NULL DEFAULT 1,
  endpoint_url          VARCHAR(500) NOT NULL,
  http_method           ENUM('GET','POST') NOT NULL DEFAULT 'POST',
  content_type          ENUM('application/json','application/x-www-form-urlencoded') NOT NULL DEFAULT 'application/json',
  connect_timeout_ms    INT UNSIGNED NOT NULL DEFAULT 5000,
  request_timeout_ms    INT UNSIGNED NOT NULL DEFAULT 15000,
  tls_verify            TINYINT(1) NOT NULL DEFAULT 1,
  auth_type             ENUM('none','bearer','basic','header_api_key','query_api_key','ellsms_hmac','custom') NOT NULL DEFAULT 'none',
  auth_config_json      JSON NULL,
  success_rule_json     JSON NULL,
  response_mapping_json JSON NULL,
  per_line              TINYINT(1) NOT NULL DEFAULT 1,
  poll_interval_seconds INT UNSIGNED NOT NULL DEFAULT 30,
  lookback_seconds      INT UNSIGNED NOT NULL DEFAULT 3600,
  last_polled_at        TIMESTAMP NULL DEFAULT NULL,
  last_success_at       TIMESTAMP NULL DEFAULT NULL,
  last_error            VARCHAR(500) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_receive_connector_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. ELLSMS's own inbound store — same reading columns as the backend's inbound_message
--    (originator = the customer's number, destination = our line) so every consumer reads either.
--    Ids start at 10^15 so they can never collide with a backend inbound_message id: the
--    auto-responder's UNIQUE(inbound_message_id) claim therefore works across both stores unchanged.
--    dedupe_key = the provider's message id when it has one, else a fingerprint of
--    (sender, line, content, received_at) — Vesal's pull answer carries no id.
CREATE TABLE IF NOT EXISTS ellsms_inbound_messages (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id          INT UNSIGNED NOT NULL,
  originator          VARCHAR(20) NOT NULL,
  destination         VARCHAR(20) NOT NULL,
  content             TEXT NULL,
  received_at         DATETIME NOT NULL,
  provider_message_id VARCHAR(190) NULL,
  dedupe_key          CHAR(64) NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gateway_dedupe (gateway_id, dedupe_key),
  KEY idx_destination_received (destination, received_at),
  KEY idx_received (received_at),
  CONSTRAINT fk_inbound_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000000000000000;

-- An already-created table (rerun) keeps its counter; only raise it if it is still below the floor.
SET @next_id = (
  SELECT AUTO_INCREMENT FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_inbound_messages'
);
SET @sql = IF(@next_id IS NOT NULL AND @next_id < 1000000000000000,
  'ALTER TABLE ellsms_inbound_messages AUTO_INCREMENT = 1000000000000000',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
