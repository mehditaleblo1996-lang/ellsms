-- ELLSMS — supplementary tables added ALONGSIDE the existing backend
-- platform database. Nothing here touches or renames the platform's own
-- tables (user_, outbound_message, inbound_message, domain, customer,
-- role, access) — everything ELLSMS-specific is prefixed `ellsms_` so it
-- can never collide with theirs, now or after a future migration on
-- their side.
SET NAMES utf8mb4;

-- Which accounts may sign into the ELLSMS panel, and with what role.
-- An account with no row here (or panel_access=0) cannot log into
-- ELLSMS, even though it's a valid login on the connected backend —
-- access must be granted explicitly from Users → Grant access.
CREATE TABLE IF NOT EXISTS ellsms_meta (
  user_id      BIGINT NOT NULL PRIMARY KEY,   -- = user_.id (no FK constraint: we don't own that table)
  panel_access TINYINT(1) NOT NULL DEFAULT 0,
  is_admin     TINYINT(1) NOT NULL DEFAULT 0,
  originator   VARCHAR(20) NOT NULL DEFAULT '', -- override default sender line for this user
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Scheduled sends. Destinations reuse outbound_message's row shape once
-- dispatched — this table only holds the pending/repeating definition.
CREATE TABLE IF NOT EXISTS ellsms_schedule (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT NOT NULL,
  originator   VARCHAR(20) NOT NULL,
  destinations JSON NOT NULL,
  content      TEXT NOT NULL,
  run_at       DATETIME NOT NULL,
  repeat_type  ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
  status       ENUM('active','processing','done','cancelled') NOT NULL DEFAULT 'active',
  run_count    INT NOT NULL DEFAULT 0,
  last_run_at  DATETIME NULL,
  last_result  TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (status, run_at), KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Panel-only settings (backend API base URL + default sender line).
-- The connected backend's own REST service reads its config from its
-- own .env file — ELLSMS keeps a separate editable copy here so an
-- admin can update it from the panel without redeploying anything.
CREATE TABLE IF NOT EXISTS ellsms_settings (
  skey   VARCHAR(80) PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_contacts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  name       VARCHAR(120) NOT NULL,
  mobile     VARCHAR(20) NOT NULL,
  group_name VARCHAR(80) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  action     VARCHAR(80) NOT NULL,
  details    TEXT NULL,
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- منشی پیامک (SMS auto-responder). A rule watches one sender line
-- (originator) for a keyword in incoming messages and sends back a
-- templated reply. The worker scans inbound_message for new rows past
-- a saved cursor (ellsms_settings.autoreply_last_inbound_id) and
-- matches them against active rules for that line.
CREATE TABLE IF NOT EXISTS ellsms_autoreply_rules (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT NOT NULL,               -- rule owner: whose credit pays for the reply
  originator    VARCHAR(20) NOT NULL,           -- the line being watched (matches inbound_message.destination)
  keyword       VARCHAR(160) NOT NULL,
  match_type    ENUM('exact','starts_with','contains') NOT NULL DEFAULT 'exact',
  reply_content TEXT NOT NULL,                  -- may contain {sender} {originator} {name} {date} {time} {keyword} + custom {var}
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  hit_count     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (originator, is_active), KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom named variables a user can use inside reply_content as {name}.
CREATE TABLE IF NOT EXISTS ellsms_autoreply_variables (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  var_name   VARCHAR(60) NOT NULL,
  var_value  VARCHAR(300) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (user_id, var_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- History of what the auto-responder actually sent, for review/debugging.
-- inbound_message_id is UNIQUE on purpose: it's how run_autoreply_pass()
-- atomically "claims" a specific inbound row before sending, so the same
-- physical inbound row can never be replied to twice even if two worker
-- passes ever raced on it.
CREATE TABLE IF NOT EXISTS ellsms_autoreply_log (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id            INT UNSIGNED NOT NULL,
  inbound_message_id BIGINT UNSIGNED NOT NULL,
  sender             VARCHAR(20) NOT NULL,
  originator         VARCHAR(20) NOT NULL,
  reply_content      TEXT NOT NULL,
  ok                 TINYINT(1) NOT NULL DEFAULT 0,
  info               TEXT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inbound (inbound_message_id),
  KEY (rule_id), KEY (sender), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration for installs that already had ellsms_autoreply_log without
-- the UNIQUE key (created before this safeguard existed) — adds it if
-- missing, harmless/no-op if already present. Safe to re-run every deploy.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_autoreply_log' AND index_name = 'uniq_inbound'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_autoreply_log ADD UNIQUE KEY uniq_inbound (inbound_message_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migration: add ellsms_meta.twofa_enabled to installs that predate SMS
-- 2FA. Guarded the same way as the unique-key migration above — safe to
-- re-run every deploy.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_meta' AND column_name = 'twofa_enabled'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_meta ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Numbers pool. Admin creates lines here and assigns each to at most one
-- panel user; Send / منشی پیامک then offer that user a dropdown of only
-- their assigned numbers instead of free-text entry. A user with no
-- assigned numbers falls back to the legacy ellsms_meta.originator field
-- for backward compatibility with installs from before this existed.
CREATE TABLE IF NOT EXISTS ellsms_numbers (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number           VARCHAR(20) NOT NULL UNIQUE,
  label            VARCHAR(120) NOT NULL DEFAULT '',
  assigned_user_id BIGINT NULL,                    -- NULL = unassigned / in the pool
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (assigned_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- KYC profile layer on top of a granted-access account. ELLSMS does not
-- create or edit the backend's own user_ row (firstname/lastname/mobile
-- already live there) — this only holds the fields the backend doesn't
-- have: father's name, address, and paths to uploaded ID document
-- photos. Files themselves are stored outside the web root and served
-- through public/kyc-photo.php with an access check, never linked directly.
CREATE TABLE IF NOT EXISTS ellsms_user_kyc (
  user_id           BIGINT NOT NULL PRIMARY KEY,
  father_name       VARCHAR(120) NOT NULL DEFAULT '',
  address           TEXT NULL,
  id_card_photo     VARCHAR(255) NULL,             -- stored filename under storage/kyc/
  second_doc_photo  VARCHAR(255) NULL,             -- e.g. passport or the back of the ID card
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bulk number categories — admin uploads a newline-separated .txt file of
-- numbers under a name; every panel user can then see and pick that
-- category from Send to fan a message out to the whole list. Unlike
-- ellsms_contacts (private, per-user), these are visible to everyone.
CREATE TABLE IF NOT EXISTS ellsms_number_categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  created_by BIGINT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_number_category_items (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  mobile      VARCHAR(20) NOT NULL,
  UNIQUE KEY uniq_cat_mobile (category_id, mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SMS-based two-factor login codes. A fresh 6-digit code per login
-- attempt, short-lived, single-use.
CREATE TABLE IF NOT EXISTS ellsms_2fa_codes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  code       VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed   TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bulk personalized sending — shared engine behind both ارسال نظیر به
-- نظیر (peer-to-peer: each row's message is typed out in full in the
-- spreadsheet) and پیامک هوشمند (smart SMS: one shared template with
-- {column_name} placeholders filled in per row). Both upload flows
-- resolve to the SAME final per-row text at upload time and land here
-- as plain rows — run_bulk_send_pass() (the worker) doesn't know or
-- care which type produced them, it just sends what's in `content`.
-- This exists because sending thousands of rows synchronously inside
-- one HTTP request risks a PHP timeout; queuing lets the worker send
-- a batch every tick instead, with live progress on the page.
CREATE TABLE IF NOT EXISTS ellsms_bulk_jobs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT NOT NULL,
  type        ENUM('p2p','smart') NOT NULL,
  title       VARCHAR(160) NOT NULL DEFAULT '',
  originator  VARCHAR(20) NOT NULL,
  template    TEXT NULL,                 -- only set for type='smart', kept for reference/display
  status      ENUM('pending','processing','done','cancelled') NOT NULL DEFAULT 'pending',
  total_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  sent_rows   INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (status), KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_bulk_items (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id     INT UNSIGNED NOT NULL,
  mobile     VARCHAR(20) NOT NULL,
  content    TEXT NOT NULL,              -- fully rendered, ready to send as-is
  status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  error      TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (job_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings — EDIT THESE in Settings after first login, or
-- override via env vars (see .env.example) which win if the row is
-- still empty.
INSERT INTO ellsms_settings (skey, svalue) VALUES
  ('api_base_url',               ''),
  ('default_originator',         ''),
  ('autoreply_last_inbound_id',  '0')
ON DUPLICATE KEY UPDATE skey = skey;
