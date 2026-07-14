-- ELLSMS — supplementary tables added ALONGSIDE the existing `negar`
-- database. Nothing here touches or renames negar-python's own tables
-- (user_, outbound_message, inbound_message, domain, customer, role,
-- access) — everything ELLSMS-specific is prefixed `ellsms_` so it can
-- never collide with theirs, now or after a future negar-python migration.
SET NAMES utf8mb4;

-- Which negar user_ accounts may sign into the ELLSMS panel, and with
-- what role. A negar account with no row here (or panel_access=0)
-- cannot log into ELLSMS, even though it's a valid negar login —
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

-- Panel-only settings (Vesal gateway credentials + default sender line).
-- negar-python's rest_api reads its own OPERATOR_LINK__VESAL_* from its
-- own .env file. ELLSMS keeps an editable copy here since it now calls
-- Vesal directly and an admin should be able to update it without redeploying.
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

-- Seed default Vesal settings — EDIT THESE in Settings after first login,
-- or override via env vars (see .env.example) which win if the row is
-- still empty.
INSERT INTO ellsms_settings (skey, svalue) VALUES
  ('vesal_rest_url',     ''),
  ('vesal_username',     'negar'),
  ('vesal_password',     ''),
  ('default_originator', '')
ON DUPLICATE KEY UPDATE skey = skey;
