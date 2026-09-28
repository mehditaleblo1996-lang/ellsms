-- #42 — messages sent through the Bale messenger channel (app/BaleChannel.php).
-- One row per recipient attempt. Separate from SMS records on purpose: a Bale message has no sender
-- line, no segments and its own per-message price, and reports show the channel explicitly.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_channel_messages (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel             ENUM('bale') NOT NULL DEFAULT 'bale',
  organization_id     INT UNSIGNED NULL,
  user_id             BIGINT NOT NULL,
  reference_type      VARCHAR(40) NOT NULL,
  reference_id        VARCHAR(190) NOT NULL,
  destination         VARCHAR(20) NOT NULL,
  content             TEXT NOT NULL,
  status              ENUM('accepted','failed') NOT NULL,
  provider_message_id VARCHAR(190) NULL,
  error               VARCHAR(500) NULL,
  cost_credits        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_org_created (organization_id, created_at),
  KEY idx_user_created (user_id, created_at),
  KEY idx_reference (reference_type, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
