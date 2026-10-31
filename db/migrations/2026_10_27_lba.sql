-- LBA (location-based) sending through a provider's "smart SMS" API, first PishgamRayan's
-- (app/Lba.php, docs/lba.md). Separate from the ordinary SMS gateways: an LBA gateway does not carry
-- ordinary messages, and an ordinary gateway does not do LBA. A number sends LBA only through the LBA
-- gateway an admin pins to it (ellsms_numbers.lba_gateway_id), whatever its ordinary gateway is.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_lba_gateways (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(40) NOT NULL,
  name              VARCHAR(120) NOT NULL,
  provider          VARCHAR(40) NOT NULL DEFAULT 'pishgamrayan',
  base_url          VARCHAR(255) NOT NULL,
  enabled           TINYINT(1) NOT NULL DEFAULT 0,
  status            ENUM('active','archived') NOT NULL DEFAULT 'active',
  price_fa_part     INT UNSIGNED NULL,
  price_en_part     INT UNSIGNED NULL,
  last_test_at      TIMESTAMP NULL,
  last_test_ok      TINYINT(1) NULL,
  last_test_message VARCHAR(500) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_lba_gateway_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Token (and the optional panel username/password) of an LBA gateway: AES-256-GCM with a key derived
-- from SMS_GATEWAY_MASTER_KEY under its own purpose label. Never shown again once saved.
CREATE TABLE IF NOT EXISTS ellsms_lba_gateway_secrets (
  gateway_id      INT UNSIGNED NOT NULL,
  secret_key      VARCHAR(40) NOT NULL,
  ciphertext      VARBINARY(4096) NOT NULL,
  nonce           VARBINARY(32) NOT NULL,
  tag             VARBINARY(32) NOT NULL,
  key_fingerprint VARCHAR(32) NOT NULL DEFAULT '',
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (gateway_id, secret_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gray lists ("campaigns") and clubs live in the provider account, which every customer of the gateway
-- shares, so each one is recorded here with its owner and only its owner sees and uses it.
CREATE TABLE IF NOT EXISTS ellsms_lba_campaigns (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id           INT UNSIGNED NOT NULL,
  organization_id      INT UNSIGNED NULL,
  user_id              BIGINT NOT NULL,
  title                VARCHAR(190) NOT NULL,
  provider_campaign_id BIGINT NULL,
  operators_json       JSON NULL,
  status               ENUM('active','failed','deleted') NOT NULL DEFAULT 'active',
  error                VARCHAR(500) NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lba_campaign_owner (gateway_id, organization_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_lba_clubs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id       INT UNSIGNED NOT NULL,
  organization_id  INT UNSIGNED NULL,
  user_id          BIGINT NOT NULL,
  title            VARCHAR(190) NOT NULL,
  provider_club_id BIGINT NULL,
  operators_json   JSON NULL,
  added_count      INT UNSIGNED NOT NULL DEFAULT 0,
  status           ENUM('active','failed','deleted') NOT NULL DEFAULT 'active',
  error            VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lba_club_owner (gateway_id, organization_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One audience definition ("smart SMS request"). The provider builds the audience asynchronously:
-- provider_status 1 (pending) until 0 (completed), then recipients_json holds its per-operator segments.
CREATE TABLE IF NOT EXISTS ellsms_lba_requests (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gateway_id          INT UNSIGNED NOT NULL,
  organization_id     INT UNSIGNED NULL,
  user_id             BIGINT NOT NULL,
  originator          VARCHAR(20) NOT NULL,
  kind                VARCHAR(30) NOT NULL,
  title               VARCHAR(190) NOT NULL,
  criteria_json       JSON NOT NULL,
  provider_request_id BIGINT NULL,
  provider_status     TINYINT NULL,
  recipients_json     JSON NULL,
  total_count         INT UNSIGNED NULL,
  is_periodic         TINYINT(1) NOT NULL DEFAULT 0,
  status              ENUM('pending','ready','failed','deleted') NOT NULL DEFAULT 'pending',
  error               VARCHAR(1000) NULL,
  last_refreshed_at   TIMESTAMP NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lba_request_owner (organization_id, user_id, created_at),
  KEY idx_lba_request_refresh (status, last_refreshed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One send of a request: a one-off send (kind simple) or the periodic schedule of a periodic request
-- (kind percent / count, edited in place). Money: price_per_part is the gateway tariff in force when
-- the send was made, reserved_credits the most it can cost, charged_credits what has been committed.
CREATE TABLE IF NOT EXISTS ellsms_lba_sends (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id            BIGINT UNSIGNED NOT NULL,
  gateway_id            INT UNSIGNED NOT NULL,
  organization_id       INT UNSIGNED NULL,
  user_id               BIGINT NOT NULL,
  originator            VARCHAR(20) NOT NULL,
  kind                  ENUM('simple','percent','count') NOT NULL,
  body                  TEXT NOT NULL,
  parts                 SMALLINT UNSIGNED NOT NULL,
  is_unicode            TINYINT(1) NOT NULL,
  price_per_part        INT UNSIGNED NOT NULL,
  params_json           JSON NOT NULL,
  recipient_count       INT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_message_id  BIGINT NULL,
  reserved_credits      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  charged_credits       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_parts            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status                ENUM('sending','accepted','unknown','active','inactive','done','failed') NOT NULL DEFAULT 'sending',
  error                 VARCHAR(1000) NULL,
  stats_json            JSON NULL,
  period_end_at         DATETIME NULL,
  last_polled_at        TIMESTAMP NULL,
  finished_at           TIMESTAMP NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_lba_send_request (request_id),
  KEY idx_lba_send_owner (organization_id, user_id, created_at),
  KEY idx_lba_send_poll (status, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The LBA gateway pinned to a number. NULL = the number cannot send LBA.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'lba_gateway_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_numbers
     ADD COLUMN lba_gateway_id INT UNSIGNED NULL,
     ADD KEY idx_number_lba_gateway (lba_gateway_id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- LBA is promotional mass messaging: require an approved KYC by default, like regional bulk.
INSERT INTO ellsms_settings (skey, svalue) VALUES ('kyc_gate.lba', '1')
  ON DUPLICATE KEY UPDATE skey = skey;
