-- AI secretary data tables, lookup and hand-off to a human (phase 4 of the AI engines feature;
-- docs/ai-data.md, app/Ai/AiData.php, public/ai-data.php).
--
-- A customer keeps its own tables (products, orders, appointments, ...) with columns it defines,
-- imports them from Excel/CSV, and lets a line's assistant look records up — through the model's tool
-- calls when the model supports them, otherwise by an automatic search whose results go into the
-- prompt. A table can be tied to a phone column, so a sender only ever sees its own rows.
--
-- The assistant can hand a conversation to a human: the conversation pauses, the line's owner is
-- notified, and an operator answers from the panel until the AI is resumed.
--
-- Requires 2026_11_04_ai_secretary.sql. Guarded (IF NOT EXISTS / information_schema checks), so
-- re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. A customer's table. columns_json: [{"key":"c1","label":"کد سفارش","type":"text|number|date|phone","default":""}, ...]
--    key_column: the column whose value identifies a row (an import updates rows by it).
--    phone_column: when set, a sender only sees rows whose value in that column is its own number.
CREATE TABLE IF NOT EXISTS ellsms_ai_datasets (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT NOT NULL,                      -- = user_.id, the owner
  name          VARCHAR(80) NOT NULL,
  description   VARCHAR(500) NOT NULL DEFAULT '',     -- what the table holds, shown to the model
  template      VARCHAR(30) NOT NULL DEFAULT 'custom',
  columns_json  JSON NOT NULL,
  key_column    VARCHAR(20) NULL,
  phone_column  VARCHAR(20) NULL,
  row_count     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_name (user_id, name),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Rows. search_text: every value, normalized (Persian/Arabic letters and digits unified,
--    lower case), for the lookup. row_key: the key column's value (UNIQUE per table when set);
--    phone: the phone column's value, normalized to 98…, for the sender restriction.
CREATE TABLE IF NOT EXISTS ellsms_ai_dataset_rows (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dataset_id   INT UNSIGNED NOT NULL,
  row_key      VARCHAR(191) NULL,
  phone        VARCHAR(20) NULL,
  data_json    JSON NOT NULL,
  search_text  TEXT NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_dataset_key (dataset_id, row_key),
  KEY idx_dataset_phone (dataset_id, phone),
  CONSTRAINT fk_ai_row_dataset FOREIGN KEY (dataset_id) REFERENCES ellsms_ai_datasets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Which tables a line's assistant may look into.
CREATE TABLE IF NOT EXISTS ellsms_ai_assistant_datasets (
  assistant_id INT UNSIGNED NOT NULL,
  dataset_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (assistant_id, dataset_id),
  KEY idx_dataset (dataset_id),
  CONSTRAINT fk_ai_ad_assistant FOREIGN KEY (assistant_id) REFERENCES ellsms_ai_assistants (id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_ad_dataset FOREIGN KEY (dataset_id) REFERENCES ellsms_ai_datasets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Conversation state: a conversation handed to a human is paused until paused_until (or until an
--    operator resumes the AI). One row per (line, sender) that was ever handed over.
CREATE TABLE IF NOT EXISTS ellsms_ai_conversations (
  line           VARCHAR(20) NOT NULL,
  sender         VARCHAR(20) NOT NULL,
  paused_until   DATETIME NULL,
  handoff_at     DATETIME NULL,
  handoff_reason VARCHAR(300) NULL,
  resolved_at    DATETIME NULL,
  resolved_by    BIGINT NULL,
  PRIMARY KEY (line, sender),
  KEY idx_paused (paused_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. New columns, each guarded.
--    ellsms_ai_assistants.handoff_enabled / handoff_pause_hours: hand-off to a human, and how long
--      the AI stays out of that conversation afterwards.
--    ellsms_ai_assistants.lookup_mode: 'auto' (tools when the model supports them, else search),
--      'search' (always the automatic search), 'tools' (always tool calls).
--    ellsms_ai_models.supports_tools: the model answers tool calls (set per model by the admin).
--    ellsms_ai_jobs.kind: 'inbound' (an answer to a received SMS) or 'manual' (an operator's reply
--      from the panel, kept so the AI sees it in the conversation memory).
--    ellsms_ai_jobs.handoff / records_used: the answer handed the conversation over / how many data
--      rows the model was shown.
SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_assistants' AND column_name = 'handoff_enabled');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_ai_assistants ADD COLUMN handoff_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN handoff_pause_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24, ADD COLUMN lookup_mode ENUM(''auto'',''search'',''tools'') NOT NULL DEFAULT ''auto''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_models' AND column_name = 'supports_tools');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_ai_models ADD COLUMN supports_tools TINYINT(1) NOT NULL DEFAULT 0 AFTER max_output_tokens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_jobs' AND column_name = 'kind');
SET @sql = IF(@c = 0, 'ALTER TABLE ellsms_ai_jobs ADD COLUMN kind ENUM(''inbound'',''manual'') NOT NULL DEFAULT ''inbound'' AFTER inbound_key, ADD COLUMN handoff TINYINT(1) NOT NULL DEFAULT 0 AFTER is_fallback, ADD COLUMN records_used SMALLINT UNSIGNED NULL AFTER handoff', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
