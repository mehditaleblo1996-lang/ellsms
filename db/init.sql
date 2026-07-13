-- ELLSMS — Smart SMS Panel — schema
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(60)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  full_name      VARCHAR(120) NOT NULL DEFAULT '',
  mobile         VARCHAR(20)  NOT NULL DEFAULT '',
  email          VARCHAR(120) NOT NULL DEFAULT '',
  role           ENUM('admin','user') NOT NULL DEFAULT 'user',
  originator     VARCHAR(20)  NOT NULL DEFAULT '',      -- default sender line for this user
  api_sender_id  INT UNSIGNED NULL,                      -- sender_user_id on the gateway
  credit         INT NOT NULL DEFAULT 0,                 -- SMS parts balance
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS batches (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  schedule_id       INT UNSIGNED NULL,
  originator        VARCHAR(20) NOT NULL,
  content           TEXT NOT NULL,
  parts             INT NOT NULL DEFAULT 1,
  destination_count INT NOT NULL DEFAULT 0,
  http_code         INT NOT NULL DEFAULT 0,
  api_response      MEDIUMTEXT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_id       INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  originator     VARCHAR(20) NOT NULL,
  destination    VARCHAR(20) NOT NULL,
  content        TEXT NOT NULL,
  parts          INT NOT NULL DEFAULT 1,
  status         ENUM('pending','sent','failed','delivered','undelivered') NOT NULL DEFAULT 'pending',
  api_message_id VARCHAR(80) NULL,
  error          TEXT NULL,
  sent_at        DATETIME NULL,
  delivered_at   DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (destination), KEY (status), KEY (created_at), KEY (api_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS schedules (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
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

CREATE TABLE IF NOT EXISTS incoming_messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender      VARCHAR(20) NOT NULL,        -- the mobile that sent to us
  recipient   VARCHAR(20) NOT NULL,        -- our line / shortcode
  content     TEXT NOT NULL,
  raw_payload MEDIUMTEXT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (sender), KEY (recipient), KEY (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  mobile     VARCHAR(20) NOT NULL,
  group_name VARCHAR(80) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey   VARCHAR(80) PRIMARY KEY,
  svalue TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(80) NOT NULL,
  details    TEXT NULL,
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (user_id), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin (admin / admin123) is created automatically by the app on
-- first visit to the login page when the users table is empty. Change the
-- password right after your first login (Profile → Change password).

INSERT INTO settings (skey, svalue) VALUES
  ('api_base_url',       'https://rest.ravixops.com'),
  ('default_sender_id',  '1'),
  ('default_originator', '5000435800'),
  ('webhook_token',      SUBSTRING(MD5(RAND()), 1, 24))
ON DUPLICATE KEY UPDATE skey = skey;
