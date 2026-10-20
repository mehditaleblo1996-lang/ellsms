-- Voice messages through ZarinCall (app/VoiceMessages.php, docs/voice-messages.md).
--
-- ellsms_voice_files: the audio a customer uploaded or generated from text, mirrored from ZarinCall's
--   own file store (provider_voice_id). duration_seconds prices a send: pulses = ceil(duration / pulse).
-- ellsms_voice_messages: one send (voice, interactive, OTP or OTP with an announcement) with its
--   recipients count, price and money: reserved up front, charged for answered calls, rest released.
-- ellsms_voice_message_batches: the provider calls one message was split into, each with its own
--   ZarinCall MsgID and delivery counts (RecSuccess / RecFailed), settled independently.
--
-- Times are DATETIME written by PHP in the app's local time (Asia/Tehran), never NOW(), so the
-- scheduled time a customer picked and the worker's due check use the same clock.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_voice_files (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NULL,
  user_id            BIGINT NOT NULL,
  title              VARCHAR(190) NOT NULL,
  source             ENUM('upload','tts') NOT NULL,
  file_name          VARCHAR(190) NOT NULL DEFAULT '',
  size_bytes         INT UNSIGNED NOT NULL DEFAULT 0,
  provider_voice_id  VARCHAR(64) NOT NULL,
  provider_status    INT NULL,
  duration_seconds   INT UNSIGNED NULL,
  tts_text           TEXT NULL,
  tts_speaker        TINYINT UNSIGNED NULL,
  status             ENUM('active','deleted') NOT NULL DEFAULT 'active',
  created_at         DATETIME NOT NULL,
  refreshed_at       DATETIME NULL,
  deleted_at         DATETIME NULL,
  UNIQUE KEY uniq_provider_voice (provider_voice_id),
  KEY idx_user_status (user_id, status, id),
  KEY idx_org_status (organization_id, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_voice_messages (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NULL,
  user_id            BIGINT NOT NULL,
  kind               ENUM('voice','ivc','otp','otp2') NOT NULL,
  voice_file_id      BIGINT UNSIGNED NULL,
  provider_voice_id  VARCHAR(64) NULL,
  title              VARCHAR(190) NOT NULL DEFAULT '',
  ivc_digit          TINYINT UNSIGNED NULL,
  ivc_sms            VARCHAR(500) NULL,
  recipients_count   INT UNSIGNED NOT NULL,
  blocked_count      INT UNSIGNED NOT NULL DEFAULT 0,
  pulses             INT UNSIGNED NOT NULL DEFAULT 0,
  unit_cost          INT UNSIGNED NOT NULL DEFAULT 0,
  reserved_cost      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  charged_cost       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status             ENUM('scheduled','sending','sent','completed','failed','cancelled') NOT NULL,
  success_count      INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count       INT UNSIGNED NOT NULL DEFAULT 0,
  error              VARCHAR(500) NULL,
  source             ENUM('panel','api') NOT NULL DEFAULT 'panel',
  api_key_id         BIGINT UNSIGNED NULL,
  scheduled_at       DATETIME NOT NULL,
  sending_started_at DATETIME NULL,
  sent_at            DATETIME NULL,
  completed_at       DATETIME NULL,
  created_at         DATETIME NOT NULL,
  KEY idx_due (status, scheduled_at),
  KEY idx_user_created (user_id, id),
  KEY idx_org_created (organization_id, id),
  KEY idx_voice_file (voice_file_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_voice_message_batches (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id           BIGINT UNSIGNED NOT NULL,
  batch_no             INT UNSIGNED NOT NULL,
  recipients           MEDIUMTEXT NOT NULL,
  recipients_count     INT UNSIGNED NOT NULL,
  status               ENUM('pending','sending','sent','failed','settled') NOT NULL DEFAULT 'pending',
  provider_msg_id      VARCHAR(64) NULL,
  provider_send_status INT NULL,
  provider_send_des    VARCHAR(500) NULL,
  rec_count            INT UNSIGNED NULL,
  rec_success          INT UNSIGNED NOT NULL DEFAULT 0,
  rec_failed           INT UNSIGNED NOT NULL DEFAULT 0,
  charged_cost         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error                VARCHAR(500) NULL,
  poll_count           INT UNSIGNED NOT NULL DEFAULT 0,
  sent_at              DATETIME NULL,
  last_polled_at       DATETIME NULL,
  settled_at           DATETIME NULL,
  UNIQUE KEY uniq_message_batch (message_id, batch_no),
  KEY idx_status_polled (status, last_polled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
