-- #38 — per-line opt-out: a recipient who texts "11" to a line stops receiving from THAT line;
-- "12" re-subscribes (the Iranian operator convention, as Vesal's MOPreparationJob implements it).
-- app/LineOptouts.php records it from inbound messages and every send path skips opted-out numbers.
SET NAMES utf8mb4;

-- Who opted out of which line. mobile is normalized (98912…), originator is the line.
CREATE TABLE IF NOT EXISTS ellsms_line_optouts (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  originator         VARCHAR(20) NOT NULL,
  mobile             VARCHAR(20) NOT NULL,
  source             ENUM('sms','admin') NOT NULL DEFAULT 'sms',
  source_inbound_id  BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_line_mobile (originator, mobile),
  KEY idx_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per inbound message that was an opt-out/opt-in keyword: makes processing idempotent (the
-- same message seen twice changes nothing and confirms once) and keeps the history of both actions.
CREATE TABLE IF NOT EXISTS ellsms_line_optout_events (
  inbound_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  originator         VARCHAR(20) NOT NULL,
  mobile             VARCHAR(20) NOT NULL,
  action             ENUM('stop','start') NOT NULL,
  confirmed          TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_line_mobile (originator, mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
