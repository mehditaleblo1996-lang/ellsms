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
  KEY (rule_id), KEY (inbound_message_id), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default settings — EDIT THESE in Settings after first login, or
-- override via env vars (see .env.example) which win if the row is
-- still empty.
INSERT INTO ellsms_settings (skey, svalue) VALUES
  ('api_base_url',               ''),
  ('default_originator',         ''),
  ('autoreply_last_inbound_id',  '0')
ON DUPLICATE KEY UPDATE skey = skey;
