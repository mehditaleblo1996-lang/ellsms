-- #46 — SMPP as a gateway protocol, carried by the smpp-bridge container (smpp-bridge/, docs/smpp-gateway.md).
--
-- ADDITIVE AND INERT: one column on ellsms_sms_gateways (default 'http', so every existing gateway is
-- unchanged) and three new tables. Nothing uses them until an admin creates a gateway with protocol
-- SMPP and the smpp-bridge service runs. Idempotent.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_gateways' AND column_name = 'protocol'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_sms_gateways ADD COLUMN protocol ENUM('http','smpp') NOT NULL DEFAULT 'http' AFTER code",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One row per SMPP gateway. The bind password is NOT here: it lives in the existing encrypted vault
-- (ellsms_sms_gateway_secrets, secret_key = 'smpp_password'), which the bridge decrypts with the same
-- SMS_GATEWAY_MASTER_KEY derivation the PHP side uses.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_smpp_connectors (
  gateway_id            INT UNSIGNED NOT NULL PRIMARY KEY,
  host                  VARCHAR(190) NOT NULL DEFAULT '',
  port                  INT UNSIGNED NOT NULL DEFAULT 2775,
  use_tls               TINYINT(1) NOT NULL DEFAULT 0,
  system_id             VARCHAR(16) NOT NULL DEFAULT '',
  system_type           VARCHAR(13) NOT NULL DEFAULT '',
  interface_version     ENUM('3.4','5.0') NOT NULL DEFAULT '3.4',
  bind_mode             ENUM('trx','tx_rx','tx') NOT NULL DEFAULT 'trx',
  session_count         TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- parallel sessions (of each kind for tx_rx)
  tps                   INT UNSIGNED NOT NULL DEFAULT 50,        -- per gateway, across its sessions
  window_size           INT UNSIGNED NOT NULL DEFAULT 10,        -- outstanding submit_sm per session
  enquire_link_s        INT UNSIGNED NOT NULL DEFAULT 30,
  reconnect_delay_s     INT UNSIGNED NOT NULL DEFAULT 5,
  submit_timeout_ms     INT UNSIGNED NOT NULL DEFAULT 10000,
  source_ton            TINYINT UNSIGNED NOT NULL DEFAULT 5,     -- 5 = alphanumeric/short code; auto-switches for long numbers
  source_npi            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  dest_ton              TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- 1 = international
  dest_npi              TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- 1 = ISDN/E.164
  data_coding           ENUM('auto','gsm7','ucs2','latin1') NOT NULL DEFAULT 'auto',
  long_message          ENUM('udh','sar','payload') NOT NULL DEFAULT 'udh',
  registered_delivery   TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- ask the SMSC for delivery receipts
  validity_minutes      INT UNSIGNED NOT NULL DEFAULT 0,         -- 0 = SMSC default
  destination_format    ENUM('international','national','as_is') NOT NULL DEFAULT 'international',
  dlr_id_format         ENUM('auto','as_is','hex_to_dec','dec_to_hex') NOT NULL DEFAULT 'auto',
  receive_enabled       TINYINT(1) NOT NULL DEFAULT 1,           -- store MO (deliver_sm that is not a receipt)
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_smpp_connector_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Durable hand-off from the bridge to PHP. The bridge inserts a row BEFORE acknowledging the
-- deliver_sm, so a receipt or MO is never lost if PHP (or the bridge) restarts; PHP's status-worker
-- applies it with the same monotonic rules as polled statuses and marks it processed.
CREATE TABLE IF NOT EXISTS ellsms_smpp_events (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id     INT UNSIGNED NOT NULL,
  event_type     ENUM('dlr','mo') NOT NULL,
  message_id     VARCHAR(190) NULL,        -- dlr: the id the receipt refers to
  dlr_stat       VARCHAR(20) NULL,         -- DELIVRD / UNDELIV / EXPIRED / REJECTD / …
  dlr_err        VARCHAR(10) NULL,
  done_at        DATETIME NULL,
  source         VARCHAR(30) NULL,         -- mo: sender mobile
  destination    VARCHAR(30) NULL,         -- mo: our line
  content        TEXT NULL,                -- mo: decoded, reassembled text
  received_at    DATETIME NOT NULL,
  attempts       INT UNSIGNED NOT NULL DEFAULT 0,
  processed_at   DATETIME NULL,
  result         VARCHAR(40) NULL,         -- updated / unchanged / unmatched / stored / duplicate
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_smpp_events_pending (processed_at, id),
  KEY idx_smpp_events_gateway (gateway_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Live session state written by the bridge every few seconds, read by the panel.
CREATE TABLE IF NOT EXISTS ellsms_smpp_sessions (
  gateway_id     INT UNSIGNED NOT NULL,
  session_key    VARCHAR(20) NOT NULL,     -- e.g. trx-1, tx-2, rx-1
  bind_type      VARCHAR(5) NOT NULL,
  state          VARCHAR(20) NOT NULL,     -- BOUND / CONNECTING / DISCONNECTED / DISABLED
  bound_since    DATETIME NULL,
  last_error     VARCHAR(500) NULL,
  last_error_at  DATETIME NULL,
  submitted      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  submit_ok      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  submit_failed  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  throttled      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dlr_received   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  mo_received    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  in_flight      INT UNSIGNED NOT NULL DEFAULT 0,
  reconnects     INT UNSIGNED NOT NULL DEFAULT 0,
  config_version INT UNSIGNED NOT NULL DEFAULT 0,
  bridge_id      VARCHAR(64) NOT NULL DEFAULT '',
  updated_at     DATETIME NOT NULL,
  PRIMARY KEY (gateway_id, session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
