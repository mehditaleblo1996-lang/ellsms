-- Phase 12 — Public API, API keys, idempotency, webhooks (docs/public-api.md, docs/webhooks.md).
--
-- Six new ELLSMS-owned tables, nothing touching a backend-owned table (user_/domain/
-- outbound_message/inbound_message) — every FK here is ELLSMS-owned-to-ELLSMS-owned, matching the
-- standing policy in db/database-audit.md. CREATE TABLE IF NOT EXISTS is natively idempotent, no
-- information_schema/PREPARE guard needed (same reasoning as 2026_08_01_message_attempts.sql).
--
-- ellsms_api_keys:          one row per issued key. Only a prefix + SHA-256 hash of the secret are
--                            stored — the raw secret is shown exactly once at creation/rotation and
--                            never persisted anywhere (Invariant E). scopes_json is a JSON array of
--                            Support/ApiScopes.php constants, validated against the catalog at
--                            write time (never trusted as free-form at read time).
-- ellsms_idempotency_keys:  the Idempotency-Key lock/replay table (STEP 17/18). UNIQUE
--                            (organization_id, endpoint, idempotency_key) IS the concurrency
--                            primitive — a second concurrent INSERT for the same tuple fails
--                            atomically in MySQL, which is what makes "concurrent duplicate
--                            requests execute once" provable without any application-level lock.
-- ellsms_webhook_endpoints: tenant-scoped webhook destinations. secret_ciphertext/_nonce/_tag hold
--                            an AES-256-GCM envelope-encrypted signing secret (Option A from
--                            STEP 30 — decryptable, because delivery must compute a live HMAC; see
--                            app/Webhooks.php's webhook_encrypt_secret()/webhook_decrypt_secret()).
-- ellsms_webhook_events:    the outbox — one durable row per business event, independent of how
--                            many endpoints ultimately receive it (an event fans out to N
--                            deliveries, but keeps ONE event_id/signature payload — STEP 32).
-- ellsms_webhook_deliveries: one row per (event, endpoint) delivery attempt lifecycle — claim/lease/
--                            retry columns mirror ellsms_bulk_items' Phase 4 shape exactly, on
--                            purpose, so cron/webhook-worker.php reuses the same proven claim
--                            pattern as run_bulk_send_pass() rather than inventing a new one.
-- ellsms_api_messages:      the API's OWN durable resource for POST /api/v1/messages (STEP 19/20) —
--                            deliberately NOT a read against backend-owned outbound_message, so a
--                            GET /api/v1/messages/{id} never has to reach across the Phase 8
--                            boundary or guess which outbound_message row(s) a given API call
--                            produced.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_api_keys (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id     INT UNSIGNED NOT NULL,
  name                VARCHAR(120) NOT NULL,
  environment         ENUM('live','test') NOT NULL DEFAULT 'live',
  key_prefix          VARCHAR(24) NOT NULL,   -- public lookup id, e.g. "a1b2c3d4e5f6" — not secret
  secret_hash         CHAR(64) NOT NULL,      -- SHA-256(secret), hex — see app/ApiKeys.php for why SHA-256 over password_hash()
  scopes_json         TEXT NOT NULL,          -- JSON array of Support/ApiScopes.php constants
  status              ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_by_user_id  BIGINT NOT NULL,        -- = user_.id; acting principal for wallet/messaging (see app/ApiKeys.php docblock)
  last_used_at        TIMESTAMP NULL,
  last_used_ip_hash   CHAR(64) NULL,          -- SHA-256(ip) — enough for abuse correlation, never the raw IP at rest
  expires_at          TIMESTAMP NULL,
  revoked_at          TIMESTAMP NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_key_prefix (key_prefix),
  KEY idx_org_status (organization_id, status),
  CONSTRAINT fk_api_keys_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_idempotency_keys (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  INT UNSIGNED NOT NULL,
  api_key_id       BIGINT UNSIGNED NOT NULL,
  endpoint         VARCHAR(80) NOT NULL,      -- e.g. "POST /api/v1/messages" — a stable route identifier, not the raw URL
  idempotency_key  VARCHAR(200) NOT NULL,     -- caller-supplied Idempotency-Key header, normalized (see app/Idempotency.php)
  request_hash     CHAR(64) NOT NULL,         -- SHA-256 of the normalized request body — detects key-reused-with-different-payload
  status            ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
  response_status   SMALLINT UNSIGNED NULL,
  response_body     MEDIUMTEXT NULL,          -- bounded by app/Idempotency.php before storage
  resource_type     VARCHAR(40) NULL,
  resource_id       VARCHAR(64) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at      TIMESTAMP NULL,
  UNIQUE KEY uniq_idem (organization_id, endpoint, idempotency_key),
  KEY idx_created (created_at),
  CONSTRAINT fk_idempotency_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_idempotency_key FOREIGN KEY (api_key_id) REFERENCES ellsms_api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_webhook_endpoints (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id     INT UNSIGNED NOT NULL,
  url                 VARCHAR(2048) NOT NULL,
  description         VARCHAR(160) NOT NULL DEFAULT '',
  secret_ciphertext   VARBINARY(512) NOT NULL,
  secret_nonce        VARBINARY(16) NOT NULL,
  secret_tag          VARBINARY(16) NOT NULL,
  enabled             TINYINT(1) NOT NULL DEFAULT 1,
  event_types_json    TEXT NOT NULL,          -- JSON array of Webhooks::EVENT_* constants this endpoint subscribes to
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at     TIMESTAMP NULL,
  last_failure_at     TIMESTAMP NULL,
  last_error_code     VARCHAR(60) NULL,
  disabled_reason     VARCHAR(120) NULL,      -- set when auto-disabled (STEP 37), NULL for manually-enabled/never-disabled
  created_by_user_id  BIGINT NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org (organization_id),
  KEY idx_enabled (enabled),
  CONSTRAINT fk_webhook_endpoints_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_webhook_events (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_uuid       CHAR(36) NOT NULL,         -- the STABLE event identity sent as X-ELLSMS-Event-ID — never regenerated across retries (STEP 32)
  organization_id  INT UNSIGNED NOT NULL,
  event_type       VARCHAR(60) NOT NULL,      -- Webhooks::EVENT_* constant
  resource_type    VARCHAR(40) NOT NULL,
  resource_id      VARCHAR(64) NOT NULL,
  payload_json     MEDIUMTEXT NOT NULL,       -- the exact "data" object serialized into every delivery's body (STEP 28)
  occurred_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_event_uuid (event_uuid),
  KEY idx_org_type (organization_id, event_type, created_at),
  CONSTRAINT fk_webhook_events_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_webhook_deliveries (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id          BIGINT UNSIGNED NOT NULL,
  endpoint_id       BIGINT UNSIGNED NOT NULL,
  status            ENUM('pending','processing','delivered','failed','dead_letter') NOT NULL DEFAULT 'pending',
  attempt_count     INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at   DATETIME NULL,
  claimed_by        VARCHAR(80) NULL,
  claimed_at        DATETIME NULL,
  lease_expires_at  DATETIME NULL,
  http_status       SMALLINT NULL,
  error_code        VARCHAR(60) NULL,         -- e.g. "timeout" / "connection_failed" / "http_4xx" / "ssrf_blocked"
  response_excerpt  VARCHAR(1024) NULL,       -- bounded, sanitized (app/Webhooks.php) — never the full response body
  duration_ms       INT UNSIGNED NULL,
  started_at        TIMESTAMP NULL,
  completed_at      TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_claim (status, next_attempt_at),
  KEY idx_lease (status, lease_expires_at),
  KEY idx_event (event_id),
  KEY idx_endpoint (endpoint_id, status),
  CONSTRAINT fk_webhook_deliveries_event FOREIGN KEY (event_id) REFERENCES ellsms_webhook_events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_webhook_deliveries_endpoint FOREIGN KEY (endpoint_id) REFERENCES ellsms_webhook_endpoints(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_api_messages (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NOT NULL,
  api_key_id         BIGINT UNSIGNED NOT NULL,
  user_id            BIGINT NOT NULL,         -- = user_.id — the acting principal (ellsms_api_keys.created_by_user_id at send time)
  originator         VARCHAR(40) NOT NULL,
  destinations_json  TEXT NOT NULL,
  content            TEXT NOT NULL,
  status             ENUM('sent','partially_sent','failed') NOT NULL,
  sent_count         INT UNSIGNED NOT NULL DEFAULT 0,
  total_count        INT UNSIGNED NOT NULL DEFAULT 0,
  parts_per_message  INT UNSIGNED NOT NULL DEFAULT 0,
  error_code         VARCHAR(60) NULL,
  idempotency_key    VARCHAR(200) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_org_created (organization_id, created_at),
  CONSTRAINT fk_api_messages_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_api_messages_key FOREIGN KEY (api_key_id) REFERENCES ellsms_api_keys(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
