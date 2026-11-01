-- Telegram user-account service (app/TelegramUser.php, tg-worker/, docs/telegram-user-service.md).
--
-- A customer connects one or more of THEIR OWN Telegram accounts (phone + login code + optional 2FA
-- password); the tg-worker container then uses those accounts to check which numbers have Telegram,
-- send OTP / notification / promotional messages, and follow each message to "read". The panel (PHP)
-- owns money, limits and webhooks; the worker (Python/Telethon) owns everything that talks to
-- Telegram. They meet only in these tables.
--
-- ellsms_tg_user_settings   per-customer switch and limits, set by a platform admin (NULL = default)
-- ellsms_tg_login_requests  one login attempt: requested -> code_sent -> code_submitted
--                           [-> password_needed -> password_submitted] -> done | failed | expired
-- ellsms_tg_accounts        a connected account; session_enc is the Telethon session, encrypted
--                           (AES-256-GCM) by the worker — never readable by the panel
-- ellsms_tg_campaigns       one send: a panel campaign or one API/OTP message (always a campaign)
-- ellsms_tg_messages        one recipient of a campaign, with its status and charge
-- ellsms_tg_lookup_jobs     one "who has Telegram" check of a list
-- ellsms_tg_lookup_items    one number of a check
-- ellsms_tg_worker_status   the worker's heartbeat (single row, id = 1)
--
-- Settings (ellsms_settings): tg_enabled, tg_price_text, tg_price_media, tg_price_check,
--   tg_default_max_accounts, tg_default_daily_send, tg_default_daily_check, tg_default_interval,
--   tg_promo_warn_threshold.
--
-- Times are DATETIME in the app's local time (Asia/Tehran), written by PHP or by the worker (which
-- runs with the same zone), never NOW().
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_user_settings (
  user_id               BIGINT NOT NULL PRIMARY KEY,
  enabled               TINYINT(1) NOT NULL DEFAULT 0,
  max_accounts          INT UNSIGNED NULL,
  daily_send_limit      INT UNSIGNED NULL,
  daily_check_limit     INT UNSIGNED NULL,
  min_interval_seconds  INT UNSIGNED NULL,
  price_text            INT UNSIGNED NULL,
  price_media           INT UNSIGNED NULL,
  price_check           INT UNSIGNED NULL,
  note                  VARCHAR(255) NULL,
  updated_by            BIGINT NULL,
  updated_at            DATETIME NULL,
  KEY idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_accounts (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT NOT NULL,
  organization_id  INT UNSIGNED NULL,
  phone            VARCHAR(20) NOT NULL,
  tg_user_id       BIGINT NULL,
  tg_username      VARCHAR(64) NULL,
  display_name     VARCHAR(190) NULL,
  status           ENUM('active','limited','disconnected','error') NOT NULL DEFAULT 'active',
  session_enc      TEXT NULL,
  limited_until    DATETIME NULL,
  last_error       VARCHAR(500) NULL,
  counter_date     DATE NULL,
  sent_today       INT UNSIGNED NOT NULL DEFAULT 0,
  checked_today    INT UNSIGNED NOT NULL DEFAULT 0,
  last_action_at   DATETIME NULL,
  logout_requested TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NULL,
  disconnected_at  DATETIME NULL,
  UNIQUE KEY uq_user_phone (user_id, phone),
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_login_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT NOT NULL,
  phone             VARCHAR(20) NOT NULL,
  state             ENUM('requested','code_sent','code_submitted','password_needed','password_submitted','done','failed','expired') NOT NULL DEFAULT 'requested',
  phone_code_hash   VARCHAR(128) NULL,
  temp_session_enc  TEXT NULL,
  code              VARCHAR(16) NULL,
  password_enc      TEXT NULL,
  password_hint     VARCHAR(128) NULL,
  error_code        VARCHAR(60) NULL,
  error             VARCHAR(500) NULL,
  account_id        BIGINT UNSIGNED NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NULL,
  expires_at        DATETIME NOT NULL,
  KEY idx_state (state, id),
  KEY idx_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_campaigns (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT NOT NULL,
  organization_id   INT UNSIGNED NULL,
  title             VARCHAR(190) NULL,
  kind              ENUM('otp','notify','promo') NOT NULL DEFAULT 'notify',
  source            ENUM('panel','api') NOT NULL DEFAULT 'panel',
  account_id        BIGINT UNSIGNED NULL,
  content           TEXT NULL,
  media_path        VARCHAR(255) NULL,
  media_name        VARCHAR(190) NULL,
  media_kind        ENUM('photo','document') NULL,
  price_each        INT UNSIGNED NOT NULL DEFAULT 0,
  total             INT UNSIGNED NOT NULL DEFAULT 0,
  status            ENUM('queued','running','paused','completed','cancelled') NOT NULL DEFAULT 'queued',
  promo_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
  api_key_id        BIGINT UNSIGNED NULL,
  client_message_id VARCHAR(200) NULL,
  settled_at        DATETIME NULL,
  created_at        DATETIME NOT NULL,
  finished_at       DATETIME NULL,
  KEY idx_user (user_id, id),
  KEY idx_status (status, id),
  KEY idx_unsettled (settled_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_messages (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id      BIGINT UNSIGNED NOT NULL,
  user_id          BIGINT NOT NULL,
  organization_id  INT UNSIGNED NULL,
  account_id       BIGINT UNSIGNED NULL,
  phone            VARCHAR(20) NOT NULL,
  status           ENUM('queued','sending','sent','read','failed','cancelled') NOT NULL DEFAULT 'queued',
  error_code       VARCHAR(60) NULL,
  error            VARCHAR(500) NULL,
  tg_peer_id       BIGINT NULL,
  tg_access_hash   BIGINT NULL,
  tg_message_id    BIGINT NULL,
  cost             INT UNSIGNED NOT NULL DEFAULT 0,
  charged          TINYINT(1) NOT NULL DEFAULT 0,
  notified_status  VARCHAR(20) NULL,
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  claimed_at       DATETIME NULL,
  sent_at          DATETIME NULL,
  read_at          DATETIME NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NULL,
  KEY idx_campaign_status (campaign_id, status, id),
  KEY idx_status (status, id),
  KEY idx_account_sent (account_id, status, sent_at),
  KEY idx_user_created (user_id, id),
  KEY idx_user_status (user_id, status, id),
  KEY idx_account_phone (account_id, phone),
  KEY idx_settle (charged, status, id),
  KEY idx_notify (notified_status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_lookup_jobs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT NOT NULL,
  organization_id  INT UNSIGNED NULL,
  title            VARCHAR(190) NULL,
  account_id       BIGINT UNSIGNED NULL,
  price_each       INT UNSIGNED NOT NULL DEFAULT 0,
  total            INT UNSIGNED NOT NULL DEFAULT 0,
  status           ENUM('queued','running','completed','cancelled') NOT NULL DEFAULT 'queued',
  settled_at       DATETIME NULL,
  created_at       DATETIME NOT NULL,
  finished_at      DATETIME NULL,
  KEY idx_user (user_id, id),
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_lookup_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id          BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT NOT NULL,
  phone           VARCHAR(20) NOT NULL,
  status          ENUM('queued','checking','found','not_found','failed','cancelled') NOT NULL DEFAULT 'queued',
  tg_user_id      BIGINT NULL,
  tg_username     VARCHAR(64) NULL,
  tg_name         VARCHAR(190) NULL,
  error           VARCHAR(255) NULL,
  account_id      BIGINT UNSIGNED NULL,
  charged         TINYINT(1) NOT NULL DEFAULT 0,
  checked_at      DATETIME NULL,
  KEY idx_job_status (job_id, status, id),
  KEY idx_status (status, id),
  KEY idx_user_status (user_id, status, id),
  KEY idx_user_phone (user_id, phone, checked_at),
  KEY idx_settle (charged, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_tg_worker_status (
  id              TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_seen_at    DATETIME NOT NULL,
  api_configured  TINYINT(1) NOT NULL DEFAULT 0,
  crypto_ready    TINYINT(1) NOT NULL DEFAULT 0,
  version         VARCHAR(40) NULL,
  connected_accounts INT UNSIGNED NOT NULL DEFAULT 0,
  note            VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
