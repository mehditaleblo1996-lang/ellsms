-- Shared OTP (app/SharedOtp.php, docs/shared-otp.md): one-time codes sent from the platform's own
-- shared lines, so a customer needs no sender line of their own — only an approved template.
--
-- ellsms_otp_templates: a customer's fixed text with {name} placeholders. Only an 'approved' template
--   can send; a platform admin approves or rejects it, and any edit by the customer sends it back to
--   'pending'. `variables` is the JSON list of placeholder names in the order they appear, so a caller
--   may pass the values positionally (as Negar's predefined messages did) or by name.
-- ellsms_otp_messages: one send — which template, to whom, from which shared line, and the outcome.
--   The rendered text (the code itself) is never stored here.
--
-- Settings (ellsms_settings): shared_otp_enabled, shared_otp_lines, shared_otp_min_interval_seconds.
--
-- Times are DATETIME written by PHP in the app's local time, never NOW().
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_otp_templates (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  INT UNSIGNED NULL,
  user_id          BIGINT NOT NULL,
  title            VARCHAR(190) NOT NULL,
  content          VARCHAR(1000) NOT NULL,
  variables        VARCHAR(500) NOT NULL,
  status           ENUM('pending','approved','rejected','disabled','deleted') NOT NULL DEFAULT 'pending',
  review_note      VARCHAR(500) NULL,
  reviewed_by      BIGINT NULL,
  reviewed_at      DATETIME NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NULL,
  KEY idx_user_status (user_id, status, id),
  KEY idx_org_status (organization_id, status, id),
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_otp_messages (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id  INT UNSIGNED NULL,
  user_id          BIGINT NOT NULL,
  template_id      BIGINT UNSIGNED NOT NULL,
  destination      VARCHAR(20) NOT NULL,
  originator       VARCHAR(20) NULL,
  status           ENUM('sending','sent','failed') NOT NULL DEFAULT 'sending',
  parts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_code       VARCHAR(40) NULL,
  error            VARCHAR(500) NULL,
  source           ENUM('panel','api') NOT NULL DEFAULT 'api',
  api_key_id       BIGINT UNSIGNED NULL,
  client_message_id VARCHAR(200) NULL,
  created_at       DATETIME NOT NULL,
  KEY idx_user_destination (user_id, destination, created_at),
  KEY idx_org_created (organization_id, id),
  KEY idx_template (template_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
