-- Generic SMS gateway connectors — admin-configurable send/status APIs, multi-operator assignment,
-- encrypted secrets and a versioned compiled-config cache (docs/sms-gateway-connectors.md).
--
-- Seven new ELLSMS-owned tables plus additive columns on ellsms_sms_routes. Nothing here touches a
-- backend-owned table, nothing existing is dropped or rewritten, and NO send path changes as a
-- result of applying this file: creating the legacy gateway row is a separate, explicit operator
-- command (cron/sms-gateway-backfill.php), and the transport only starts consulting these tables
-- once that gateway exists and is assigned to a route.
--
-- NO GENERATED COLUMNS anywhere (TD-070, docs/td-070-restore-safety-closure.md): the mysqldump this
-- project ships emits them as ordinary data and MySQL then refuses to reload the dump. "Unique among
-- active rows" is expressed with an application-maintained slot column, exactly as the pricing and
-- profile schemas do.
--
-- CONFIGURATION IS DATA, NEVER CODE. Every value below is a static string, an allowlisted variable
-- name, a secret reference, or a bounded enum. There is no expression language, and nothing in this
-- schema is ever eval()'d, interpolated into PHP, or passed to a shell — see
-- docs/sms-gateway-connectors.md §Safety.
SET NAMES utf8mb4;

-- One row per configurable gateway.
--
-- `config_version` is the heart of the performance model: workers compile a gateway's connector ONCE
-- and keep it in memory keyed by (gateway_id, config_version), so the per-message hot path performs
-- no config query, no secret decrypt and no mapping compilation. Every runtime-relevant admin change
-- increments this counter, which is the ONLY thing a worker has to re-read to notice a change.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateways (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40) NOT NULL,           -- stable internal identifier, never the display name
  name           VARCHAR(120) NOT NULL,
  description    VARCHAR(500) NOT NULL DEFAULT '',
  status         ENUM('active','archived') NOT NULL DEFAULT 'active',
  is_default     TINYINT(1) NOT NULL DEFAULT 0,  -- used when a route names no gateway
  config_version INT UNSIGNED NOT NULL DEFAULT 1,
  -- Batch gateways accept many recipients in ONE request (the existing REST integration does exactly
  -- this: `destinations` is an array). Per-message gateways take one recipient per request. Modelling
  -- only the per-message shape would have silently broken the gateway this product actually uses.
  send_mode      ENUM('per_message','batch') NOT NULL DEFAULT 'per_message',
  send_enabled   TINYINT(1) NOT NULL DEFAULT 1,
  status_enabled TINYINT(1) NOT NULL DEFAULT 0,  -- a gateway may support sending but no status API
  last_tested_at TIMESTAMP NULL,
  last_test_result VARCHAR(255) NOT NULL DEFAULT '',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  default_slot   TINYINT(1) NULL,                -- 1 while this gateway is the ACTIVE default, NULL otherwise
  UNIQUE KEY uniq_gateway_code (code),
  UNIQUE KEY uniq_default_gateway (default_slot),
  KEY idx_gateway_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The send connector. One per gateway; `gateway_id` is the primary key rather than a plain FK
-- because "two send connectors for one gateway" has no meaning and would make runtime ambiguous.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_send_connectors (
  gateway_id           INT UNSIGNED NOT NULL PRIMARY KEY,
  endpoint_url         VARCHAR(500) NOT NULL,
  http_method          ENUM('GET','POST','PUT','PATCH') NOT NULL DEFAULT 'POST',
  content_type         ENUM('application/json','application/x-www-form-urlencoded') NOT NULL DEFAULT 'application/json',
  connect_timeout_ms   INT UNSIGNED NOT NULL DEFAULT 5000,
  request_timeout_ms   INT UNSIGNED NOT NULL DEFAULT 30000,
  tls_verify           TINYINT(1) NOT NULL DEFAULT 1,
  -- 'ellsms_hmac' is the signing scheme this platform's own backend already speaks
  -- (docs/service-boundaries.md). It is a NAMED scheme implemented in code and merely SELECTED by
  -- configuration -- an admin picks it and names which secrets hold the service id/secret, and cannot
  -- describe a new signing algorithm, which would be configuration becoming code.
  auth_type            ENUM('none','bearer','basic','header_api_key','query_api_key','ellsms_hmac','custom') NOT NULL DEFAULT 'none',
  auth_config_json     JSON NULL,     -- header/param NAMES and secret REFERENCES only; never a secret value
  -- How to read the provider's answer. A bounded, declarative rule set — not an expression language:
  -- {"http":{"min":200,"max":299},"rules":[{"source":"body","path":"status","operator":"in","values":["OK"]}]}
  success_rule_json    JSON NULL,
  -- Restricted dot-paths into the decoded body, e.g. {"provider_message_id":"data.messageId"}.
  response_mapping_json JSON NULL,
  -- Provider error code/message -> one of the finite internal BackendError classes.
  error_mapping_json   JSON NULL,
  -- For batch gateways: which response path holds the per-recipient rows and which keys identify
  -- destination/status within each row. Null for per-message gateways.
  batch_mapping_json   JSON NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_send_connector_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The delivery-status connector. OPTIONAL — a gateway with `status_enabled = 0` simply never has its
-- messages polled, and their delivery state stays whatever the send response established.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_status_connectors (
  gateway_id             INT UNSIGNED NOT NULL PRIMARY KEY,
  endpoint_url           VARCHAR(500) NOT NULL,
  http_method            ENUM('GET','POST') NOT NULL DEFAULT 'GET',
  content_type           ENUM('application/json','application/x-www-form-urlencoded') NOT NULL DEFAULT 'application/json',
  connect_timeout_ms     INT UNSIGNED NOT NULL DEFAULT 5000,
  request_timeout_ms     INT UNSIGNED NOT NULL DEFAULT 15000,
  tls_verify             TINYINT(1) NOT NULL DEFAULT 1,
  auth_type              ENUM('none','bearer','basic','header_api_key','query_api_key','ellsms_hmac','custom') NOT NULL DEFAULT 'none',
  auth_config_json       JSON NULL,
  response_mapping_json  JSON NULL,   -- where the provider status value lives, plus optional delivered_at
  -- Provider status token -> ELLSMS canonical state. An UNMAPPED value becomes 'unknown', never
  -- 'delivered': guessing in the optimistic direction would report undelivered messages as delivered.
  status_mapping_json    JSON NULL,
  poll_initial_delay_seconds INT UNSIGNED NOT NULL DEFAULT 30,
  poll_max_attempts      INT UNSIGNED NOT NULL DEFAULT 6,
  poll_max_age_seconds   INT UNSIGNED NOT NULL DEFAULT 86400,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_status_connector_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Encrypted gateway credentials.
--
-- A SEPARATE vault from the public-API key hashes, the webhook secrets and the backend HMAC secret —
-- deliberately: those have different lifecycles, different blast radii and different rotation
-- stories, and a single shared vault would couple all of them. Values are encrypted with a key
-- derived from SMS_GATEWAY_MASTER_KEY (see app/Sms/GatewaySecrets.php); the plaintext exists only in
-- worker memory during connector compilation and is never logged, never rendered, and never written
-- to disk.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_secrets (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id      INT UNSIGNED NOT NULL,
  secret_key      VARCHAR(60) NOT NULL,          -- the name a connector refers to: {{secret:api_key}}
  ciphertext      BLOB NOT NULL,
  nonce           VARBINARY(24) NOT NULL,
  tag             VARBINARY(16) NOT NULL,
  key_fingerprint CHAR(16) NOT NULL DEFAULT '',  -- which master key encrypted this, so a mismatch is diagnosable
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gateway_secret (gateway_id, secret_key),
  CONSTRAINT fk_gateway_secret_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which operators a gateway can carry. MANY-TO-MANY, deliberately and explicitly: one gateway
-- serves several operators, and one operator is reachable through several gateways. Assuming
-- one-to-one is the single most common way this kind of model goes wrong.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_operators (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id   INT UNSIGNED NOT NULL,
  operator_id  INT UNSIGNED NOT NULL,
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  priority     INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gateway_operator (gateway_id, operator_id),
  KEY idx_operator_gateways (operator_id, status),
  CONSTRAINT fk_gateway_operator_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT,
  CONSTRAINT fk_gateway_operator_operator FOREIGN KEY (operator_id) REFERENCES ellsms_sms_operators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Request parameters, in three scopes with a fixed precedence: gateway < route < operator.
--
-- A deliberately FLAT model rather than an inheritance tree — three named scopes are enough for every
-- case this product has, and are explainable in one sentence. `scope_id` is the route or operator id,
-- NULL for gateway-wide defaults.
--
-- The uniqueness slot makes "two active values for the same key in the same scope" impossible, which
-- is what stops a merge from silently depending on row order.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_parameters (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id   INT UNSIGNED NOT NULL,
  connector    ENUM('send','status') NOT NULL DEFAULT 'send',
  location     ENUM('header','query','body') NOT NULL,
  scope        ENUM('gateway','route','operator') NOT NULL DEFAULT 'gateway',
  scope_id     INT UNSIGNED NULL,                 -- route id / operator id; NULL for gateway scope
  param_key    VARCHAR(120) NOT NULL,
  value_type   ENUM('static','variable','secret','env_secret','timestamp','uuid','template') NOT NULL DEFAULT 'static',
  value        VARCHAR(1000) NOT NULL DEFAULT '', -- a literal, an allowlisted variable NAME, a secret KEY, or a template
  -- How the resolved value is TYPED in the outgoing request. 'string_list' splits a comma-separated
  -- variable (recipients) into a JSON array, and 'numeric' emits a number when the value is all
  -- digits and a string otherwise -- both exist because the existing REST integration sends exactly
  -- those shapes, and byte-level parity with it is a hard requirement, not a nicety.
  data_type    ENUM('string','integer','boolean','null','json','string_list','numeric') NOT NULL DEFAULT 'string',
  status       ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_slot  VARCHAR(220) NULL,                 -- '<gateway>:<connector>:<location>:<scope>:<scope_id>:<key>' while active
  UNIQUE KEY uniq_active_parameter (active_slot),
  KEY idx_gateway_params (gateway_id, connector, status),
  CONSTRAINT fk_gateway_parameter_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only history of runtime-relevant configuration changes, so "why did sends start failing at
-- 14:20" is answerable. Secret VALUES are never recorded — only that a secret changed.
CREATE TABLE IF NOT EXISTS ellsms_sms_gateway_config_audit (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id     INT UNSIGNED NOT NULL,
  actor_user_id  BIGINT NULL,
  change_type    VARCHAR(60) NOT NULL,
  version_before INT UNSIGNED NULL,
  version_after  INT UNSIGNED NULL,
  detail         TEXT NULL,                       -- safe metadata only; never a secret value
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gateway_audit (gateway_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Route -> gateway assignment.
--
-- ONE gateway per route, deliberately: this task explicitly excludes smart routing, so a route with
-- two candidate gateways would make runtime ambiguous with no rule to resolve it. Gateway -> many
-- routes remains fine, and operator support stays many-to-many.
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_routes' AND column_name = 'gateway_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_sms_routes
     ADD COLUMN gateway_id INT UNSIGNED NULL AFTER provider_id,
     ADD KEY idx_route_gateway (gateway_id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Per-message transport provenance on bulk items: which gateway and which CONFIG VERSION actually
-- sent this row, plus the provider's own message id.
--
-- The provider message id is what makes a later status lookup possible at all, and the gateway id is
-- what makes it use the SAME gateway that sent the message — never a re-routed one.
-- ---------------------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'gateway_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_bulk_items
     ADD COLUMN gateway_id INT UNSIGNED NULL,
     ADD COLUMN gateway_config_version INT UNSIGNED NULL,
     ADD COLUMN provider_message_id VARCHAR(190) NULL,
     ADD COLUMN delivery_status ENUM('accepted','queued','sent','delivered','failed','rejected','expired','unknown') NULL,
     ADD COLUMN delivery_checked_at TIMESTAMP NULL,
     ADD COLUMN delivery_attempts INT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN delivered_at TIMESTAMP NULL,
     ADD KEY idx_delivery_polling (delivery_status, delivery_checked_at)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
