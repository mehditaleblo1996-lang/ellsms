-- AI secretary phase 5 (docs/ai-outreach.md, docs/ai-secretary.md §Telegram, docs/ai-data.md §Documents,
-- docs/ai-engines.md §Proxy and relay).
--
--   1. Telegram: messages received by a customer's own Telegram account (tg-worker stores them) are
--      answered by the account's assistant (line 'tg:<account id>').
--   2. «پیام اول»: the assistant writes and sends the first message itself — one personalized
--      message per row of a data table (SMS, Bale, or Bale then SMS).
--   3. Long documents become searchable tables (no schema of their own; template 'document').
--   4. AI providers can be reached through a proxy (its URL lives in the AI key vault; no column).
--
-- Requires 2026_11_06_ai_datasets.sql and 2026_11_01_telegram_user_service.sql. Guarded, so
-- re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. Private messages received by a connected Telegram account whose assistant is switched on.
--    tg_peer_id / tg_access_hash: who to answer (a sender's phone is often hidden); sender_phone when
--    Telegram shares it (used for tables restricted to the sender's number).
--    job_id: the ellsms_ai_jobs row made from it (NULL until the ai-worker picks it up).
CREATE TABLE IF NOT EXISTS ellsms_tg_inbound (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT NOT NULL,
  tg_peer_id      BIGINT NOT NULL,
  tg_access_hash  BIGINT NOT NULL,
  tg_message_id   BIGINT NOT NULL,
  sender_phone    VARCHAR(20) NULL,
  sender_name     VARCHAR(190) NULL,
  text            TEXT NOT NULL,
  received_at     DATETIME NOT NULL,
  job_id          BIGINT UNSIGNED NULL,
  UNIQUE KEY uniq_message (account_id, tg_peer_id, tg_message_id),
  KEY idx_pending (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. «پیام اول» campaigns: one AI-written message per row of a table that has a phone column.
--    query: optional search that selects the rows ('' = every row). channel: how it goes out.
--    status: draft (previewed) -> queued -> running -> done | cancelled.
CREATE TABLE IF NOT EXISTS ellsms_ai_outreach (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  assistant_id INT UNSIGNED NOT NULL,
  user_id      BIGINT NOT NULL,
  dataset_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(120) NOT NULL DEFAULT '',
  query        VARCHAR(300) NOT NULL DEFAULT '',
  instruction  TEXT NOT NULL,
  channel      ENUM('sms','bale','bale_sms') NOT NULL DEFAULT 'sms',
  status       ENUM('queued','running','done','cancelled') NOT NULL DEFAULT 'queued',
  total        INT UNSIGNED NOT NULL DEFAULT 0,
  created_by   BIGINT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  TIMESTAMP NULL DEFAULT NULL,
  KEY idx_assistant (assistant_id, id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Jobs: kind 'outreach' (a «پیام اول» message) and 'telegram' (an answer on Telegram);
--    outreach_id + context (the table row the message is written from, JSON).
SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_jobs' AND column_name = 'outreach_id');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_ai_jobs MODIFY COLUMN kind ENUM(''inbound'',''manual'',''outreach'',''telegram'') NOT NULL DEFAULT ''inbound'', ADD COLUMN outreach_id BIGINT UNSIGNED NULL AFTER kind, ADD COLUMN context TEXT NULL AFTER content, ADD KEY idx_outreach (outreach_id, status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_ai_assistants.line holds 'tg:<account id>' for a Telegram assistant: VARCHAR(20) is enough.
