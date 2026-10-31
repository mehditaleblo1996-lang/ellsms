-- Shared sending service (app/SharedService.php, docs/shared-service.md): a customer sends a recipient
-- and a text, never a sender. The platform picks a line from its own pool, so the customer depends on no
-- number and no provider; swapping provider is a pool change the customer never sees.
--
-- ellsms_shared_lines: the pool. Each line is a number on /numbers.php (its route/gateway decides the
--   provider) and serves one kind: 'service' (خدماتی, transactional) or 'promo' (تبلیغاتی). Enabled lines
--   of a kind are tried in ascending priority; equal priorities share the load by the recipient's number.
-- ellsms_shared_trust: a platform admin's trust level for a customer. 'supervised' = every message is
--   held for a human; 'trusted' = only a text not seen (approved) before is held. No row = the default
--   from the shared_service_default_trust setting.
-- ellsms_shared_texts: one row per customer, kind and text "shape" (fingerprint: normalized text with
--   every run of digits replaced, so «کد 1234» and «کد 9876» are the same text). Approved once, a trusted
--   customer's later messages with the same shape go straight out.
-- ellsms_shared_messages: one message. 'held' waits for review; 'queued' waits for its send time (a
--   promotional message outside the allowed hours, or an approved one the worker has not sent yet).
--
-- Price rows for the two kinds live in ellsms_gateway_prices as two virtual "gateways" (gateway_id NULL,
-- gateway_slot 4294967001 / 4294967002, app/GatewayPrices.php) — no schema change needed there.
--
-- Times are DATETIME written by PHP in the app's local time, never NOW().
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_shared_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number      VARCHAR(20) NOT NULL,
  kind        ENUM('service','promo') NOT NULL,
  priority    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  note        VARCHAR(190) NULL,
  created_by  BIGINT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NULL,
  UNIQUE KEY uniq_shared_line_number (number),
  KEY idx_shared_line_kind (kind, enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_shared_trust (
  user_id     BIGINT NOT NULL PRIMARY KEY,
  trust       ENUM('trusted','supervised') NOT NULL,
  note        VARCHAR(190) NULL,
  updated_by  BIGINT NULL,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_shared_texts (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  INT UNSIGNED NULL,
  user_id          BIGINT NOT NULL,
  kind             ENUM('service','promo') NOT NULL,
  fingerprint      CHAR(64) NOT NULL,
  sample           VARCHAR(1000) NOT NULL,
  status           ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_note      VARCHAR(500) NULL,
  reviewed_by      BIGINT NULL,
  reviewed_at      DATETIME NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NULL,
  UNIQUE KEY uniq_shared_text (user_id, kind, fingerprint),
  KEY idx_shared_text_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_shared_messages (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id   INT UNSIGNED NULL,
  user_id           BIGINT NOT NULL,
  kind              ENUM('service','promo') NOT NULL,
  text_id           BIGINT UNSIGNED NULL,
  destination       VARCHAR(20) NOT NULL,
  content           VARCHAR(1000) NOT NULL,
  status            ENUM('held','queued','sending','sent','failed','rejected','expired') NOT NULL,
  hold_reason       VARCHAR(20) NULL,
  send_after        DATETIME NULL,
  originator        VARCHAR(20) NULL,
  parts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_code        VARCHAR(40) NULL,
  error             VARCHAR(500) NULL,
  review_note       VARCHAR(500) NULL,
  reviewed_by       BIGINT NULL,
  reviewed_at       DATETIME NULL,
  source            ENUM('panel','api') NOT NULL DEFAULT 'api',
  api_key_id        BIGINT UNSIGNED NULL,
  client_message_id VARCHAR(200) NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NULL,
  sent_at           DATETIME NULL,
  KEY idx_shared_msg_user (user_id, id),
  KEY idx_shared_msg_org (organization_id, id),
  KEY idx_shared_msg_status (status, send_after),
  KEY idx_shared_msg_text (text_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
