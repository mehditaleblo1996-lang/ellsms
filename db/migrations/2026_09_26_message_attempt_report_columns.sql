-- ---------------------------------------------------------------------------
-- Make gateway direct sends visible in the send report.
--
-- With SMS_GATEWAY_TRANSPORT=1, direct / API / scheduled sends go straight to
-- the configured gateway and are recorded ONLY in ellsms_message_attempts
-- (backend_record_gateway_send()) -- never in the backend's outbound_message,
-- which is all the report's "direct / API" section read. Those sends therefore
-- never appeared in the report. The attempt row also had no sender line or
-- message text, so the report could not show them even once it read the table.
--
-- Adds the two columns the report needs, and an index for the report's
-- all-users date-range scan (the existing (user_id, attempted_at) key covers
-- the per-user case).
--
-- Additive, guarded and rerun-safe. Rows written before this migration keep
-- NULL in both columns.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'originator'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_message_attempts
     ADD COLUMN originator VARCHAR(20) NULL,
     ADD COLUMN content TEXT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND index_name = 'idx_attempt_status_time'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD KEY idx_attempt_status_time (status, attempted_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
