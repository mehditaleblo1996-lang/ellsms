-- WebEngage SMS service provider (SSP) integration (app/WebEngage.php, docs/webengage.md).
--
-- ellsms_webengage_settings: one row per organization — whether WebEngage may send through it, which
--   WebEngage data center receives delivery status notifications (DSNs), and the DSN token WebEngage
--   issued, encrypted with WEBHOOK_MASTER_KEY like a webhook secret (never stored in clear).
-- ellsms_webengage_messages: one row per WebEngage messageId. The UNIQUE (organization_id,
--   we_message_id) key makes a retried request replay the first answer instead of sending twice. The
--   dsn_* columns are the outbound DSN's own small queue, worked by cron/webhook-worker.php:
--     waiting    accepted, waiting for the gateway's delivery report (attempt_id links to it)
--     pending    DSN due (dsn_next_at), not yet delivered to WebEngage
--     processing claimed by a worker until dsn_lease_expires_at
--     sent       WebEngage answered 2xx
--     failed     permanent HTTP error or attempts exhausted
--     none       no DSN is owed (the message was rejected synchronously)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_webengage_settings (
  organization_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled            TINYINT(1) NOT NULL DEFAULT 0,
  dsn_region         ENUM('us','in','ksa','custom') NOT NULL DEFAULT 'us',
  dsn_url            VARCHAR(500) NULL,
  token_ciphertext   VARBINARY(1024) NULL,
  token_nonce        VARBINARY(12) NULL,
  token_tag          VARBINARY(16) NULL,
  updated_by_user_id BIGINT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_webengage_messages (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id      INT UNSIGNED NOT NULL,
  api_key_id           BIGINT UNSIGNED NULL,
  user_id              BIGINT NULL,
  we_message_id        VARCHAR(190) NOT NULL,
  to_number            VARCHAR(64) NOT NULL,
  destination          VARCHAR(32) NULL,
  originator           VARCHAR(32) NULL,
  campaign_type        VARCHAR(32) NULL,
  status               ENUM('processing','accepted','rejected') NOT NULL DEFAULT 'processing',
  status_code          INT NULL,
  http_status          SMALLINT UNSIGNED NULL,
  response_json        TEXT NULL,
  parts                INT UNSIGNED NOT NULL DEFAULT 1,
  gateway_id           INT UNSIGNED NULL,
  provider_message_id  VARCHAR(190) NULL,
  attempt_id           BIGINT UNSIGNED NULL,
  delivery_state       VARCHAR(20) NULL,
  dsn_status           ENUM('none','waiting','pending','processing','sent','failed') NOT NULL DEFAULT 'none',
  dsn_sms_status       VARCHAR(20) NULL,
  dsn_status_code      INT NULL,
  dsn_attempts         INT UNSIGNED NOT NULL DEFAULT 0,
  dsn_next_at          DATETIME NULL,
  dsn_claimed_by       VARCHAR(120) NULL,
  dsn_lease_expires_at DATETIME NULL,
  dsn_http_status      SMALLINT UNSIGNED NULL,
  dsn_error            VARCHAR(190) NULL,
  dsn_sent_at          DATETIME NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_org_message (organization_id, we_message_id),
  KEY idx_attempt (attempt_id),
  KEY idx_dsn_due (dsn_status, dsn_next_at),
  KEY idx_dsn_waiting (dsn_status, created_at),
  KEY idx_org_created (organization_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
