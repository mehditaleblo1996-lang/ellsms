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

-- Migration: پنل جدید ارسال needs a 'gradual' job type (same rows/table
-- as p2p and smart, just every row shares identical content — it's a
-- broadcast, spread out over time rather than personalized per row) and
-- three columns to control the pacing. A NULL throttle_count means "no
-- throttle" — run_bulk_send_pass() falls back to its original
-- unthrottled batch-of-20-per-tick behavior for those jobs, so p2p and
-- smart jobs are completely unaffected by this. Guarded so it's safe to
-- re-run on installs that already have ellsms_bulk_jobs.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND column_name = 'throttle_count'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_jobs
     ADD COLUMN throttle_count INT UNSIGNED NULL AFTER template,
     ADD COLUMN throttle_minutes INT UNSIGNED NULL AFTER throttle_count,
     ADD COLUMN last_throttle_at DATETIME NULL AFTER throttle_minutes,
     MODIFY COLUMN type ENUM(''p2p'',''smart'',''gradual'') NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migration: a notes/title field on schedules, for پنل جدید ارسال's
-- توضیحات field on recurring (ارسال دوره‌ای) sends. Guarded the same way.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_schedule' AND column_name = 'title'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_schedule ADD COLUMN title VARCHAR(160) NOT NULL DEFAULT '''' AFTER user_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Do-not-contact list. Per-user scope: each account maintains its own —
-- a number blacklisted by one user has no effect on anyone else's sends.
-- Checked only when a send explicitly opts in via "فقط ارسال به لیست
-- سفید" (the toggle name in the reference UI is literally "whitelist",
-- but its own description says it filters out blacklisted numbers —
-- this table is the do-not-contact/suppression list that toggle checks).
CREATE TABLE IF NOT EXISTS ellsms_blacklist (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  mobile     VARCHAR(20) NOT NULL,
  note       VARCHAR(160) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_mobile (user_id, mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Saved campaigns (ذخیره به عنوان کمپین) — a reusable sender+message
-- template a user can reload next time instead of retyping.
CREATE TABLE IF NOT EXISTS ellsms_campaigns (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  name       VARCHAR(160) NOT NULL,
  originator VARCHAR(20) NOT NULL DEFAULT '',
  content    TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ZarinPal credit purchases. amount_rial is always Rial (not Toman) —
-- ZarinPal's v4 API defaults to Rial unless a request explicitly opts
-- into Toman via a "currency" field, which ELLSMS deliberately never
-- sends, to keep the unit unambiguous everywhere in this table.
-- status transitions pending -> paid|failed exactly once — the
-- callback handler only credits the account on a pending->paid update
-- that actually matched a row, so a duplicate/retried callback from
-- ZarinPal can never double-credit an account.
CREATE TABLE IF NOT EXISTS ellsms_payments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT NOT NULL,
  credits     INT UNSIGNED NOT NULL,
  amount_rial BIGINT UNSIGNED NOT NULL,
  authority   VARCHAR(64) NULL,
  ref_id      VARCHAR(64) NULL,
  status      ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (user_id), KEY (status), KEY (authority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Landing-page hero slider — managed from Settings → اسلایدر صفحه‌ی
-- اصلی (public/slides.php). image is a filename under
-- public/assets/img/slides/ (served directly by Apache, not gated like
-- KYC documents — these are public marketing banners).
CREATE TABLE IF NOT EXISTS ellsms_slides (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(190) NOT NULL,
  body       TEXT NULL,
  image      VARCHAR(190) NOT NULL,
  link_url   VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketing pricing cards shown on the landing page (public/pricing.php
-- manages them). Deliberately separate from the real credit-purchase
-- rate in ellsms_settings (rial_per_credit) — this table is only for
-- how packages are presented publicly, not what buy-credit.php actually
-- charges.
CREATE TABLE IF NOT EXISTS ellsms_pricing_packages (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  credit_amount INT UNSIGNED NOT NULL DEFAULT 0,
  price_rial    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  features      TEXT NULL,
  is_featured   TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    INT NOT NULL DEFAULT 0,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Public "راهنمای استفاده" articles (public/guide-admin.php manages
-- them, public/guide.php lists them as an accordion). Plain text body,
-- rendered with nl2br() — no markdown parser, consistent with this
-- project having no vendor/ dependencies anywhere else.
CREATE TABLE IF NOT EXISTS ellsms_guide_articles (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(190) NOT NULL,
  body       MEDIUMTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings — EDIT THESE in Settings after first login, or
-- override via env vars (see .env.example) which win if the row is
-- still empty.
INSERT INTO ellsms_settings (skey, svalue) VALUES
  ('api_base_url',               ''),
  ('default_originator',         ''),
  ('autoreply_last_inbound_id',  '0'),
  ('rial_per_credit',            '1000'),
  ('min_credit_purchase',        '100'),
  ('credit_packages',            '500,1000,5000,20000')
ON DUPLICATE KEY UPDATE skey = skey;
