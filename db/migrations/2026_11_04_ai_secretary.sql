-- AI SMS secretary (منشی هوشمند), phase 2 of the AI engines feature (docs/ai-secretary.md,
-- app/Ai/AiSecretary.php, cron/ai-worker.php, public/ai-secretary.php).
--
-- A line's owner switches an assistant on for the line; every message RECEIVED on it that no keyword
-- auto-reply rule answered (and that is not an opt-out keyword) becomes a job. The ai-worker answers
-- it with the account's AI model and sends the answer from the same line, like an auto-reply.
--
-- Requires 2026_11_03_ai_engines.sql (models). Nothing here touches a backend-owned table. Guarded by
-- IF NOT EXISTS, so re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. One assistant per line. Keyed by the line STRING, like auto-reply rules and the opt-out list.
--    model_id NULL = the owner's default model (app/Ai/AiEngines.php access rules); a model the owner
--    may no longer use falls back to that default at answer time.
--    max_parts: hard cap on the answer's SMS parts (the answer is cut to fit).
--    memory_turns / memory_hours: how many earlier exchanges with the same number the model sees.
--    sender_hourly_limit / line_daily_limit: flood and cost guards (0 = no limit).
--    fallback_text: sent when the model cannot answer after its retries ('' = stay silent).
CREATE TABLE IF NOT EXISTS ellsms_ai_assistants (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  line                VARCHAR(20) NOT NULL,
  user_id             BIGINT NOT NULL,                -- = user_.id, the line's owner who pays for the replies
  organization_id     BIGINT NULL,
  enabled             TINYINT(1) NOT NULL DEFAULT 0,
  mode                ENUM('business','general') NOT NULL DEFAULT 'business',
  template            VARCHAR(30) NOT NULL DEFAULT 'custom',
  business_name       VARCHAR(120) NOT NULL DEFAULT '',
  instructions        TEXT NULL,
  knowledge           MEDIUMTEXT NULL,
  model_id            INT UNSIGNED NULL,
  max_parts           TINYINT UNSIGNED NOT NULL DEFAULT 2,
  memory_turns        TINYINT UNSIGNED NOT NULL DEFAULT 6,
  memory_hours        SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  sender_hourly_limit SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  line_daily_limit    INT UNSIGNED NOT NULL DEFAULT 500,
  fallback_text       VARCHAR(500) NOT NULL DEFAULT '',
  created_by          BIGINT NULL,
  updated_by          BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_line (line),
  KEY idx_user (user_id),
  KEY idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. One job per inbound message the secretary handles — the queue, the conversation memory and the
--    log the customer reads, in one table.
--    inbound_key: 'b:<id>' (backend inbound_message) or 'g:<id>' (ellsms_inbound_messages); UNIQUE,
--    so the at-least-once inbound scan enqueues each message exactly once.
--    status: pending -> processing -> sent | skipped (a guard said no; skip_reason says which) |
--            failed (the model or the send failed for good)
--    reply_text is kept as soon as the model answers, so a send retry never asks the model again.
--    input/output tokens and cost_estimate (in the model's purchase currency) are RECORDED for the
--    phase-3 tariff; nothing is charged for the AI part yet. The SMS itself is charged like any
--    auto-reply (reference type 'ai_secretary').
CREATE TABLE IF NOT EXISTS ellsms_ai_jobs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inbound_key      VARCHAR(40) NOT NULL,
  assistant_id     INT UNSIGNED NOT NULL,
  user_id          BIGINT NOT NULL,
  line             VARCHAR(20) NOT NULL,
  sender           VARCHAR(20) NOT NULL,
  content          TEXT NOT NULL,
  received_at      DATETIME NULL,
  status           ENUM('pending','processing','sent','skipped','failed') NOT NULL DEFAULT 'pending',
  skip_reason      VARCHAR(40) NULL,
  attempt_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at  TIMESTAMP NULL DEFAULT NULL,
  claimed_by       VARCHAR(120) NULL,
  lease_expires_at TIMESTAMP NULL DEFAULT NULL,
  model_id         INT UNSIGNED NULL,
  reply_text       TEXT NULL,
  reply_parts      TINYINT UNSIGNED NULL,
  is_fallback      TINYINT(1) NOT NULL DEFAULT 0,
  input_tokens     INT UNSIGNED NULL,
  output_tokens    INT UNSIGNED NULL,
  cost_estimate    DECIMAL(20,8) NULL,
  ai_latency_ms    INT UNSIGNED NULL,
  error_code       VARCHAR(80) NULL,
  info             VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at     TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_inbound (inbound_key),
  KEY idx_status_next (status, next_attempt_at),
  KEY idx_conversation (line, sender, created_at),
  KEY idx_assistant_created (assistant_id, created_at),
  KEY idx_user_created (user_id, created_at),
  KEY idx_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
