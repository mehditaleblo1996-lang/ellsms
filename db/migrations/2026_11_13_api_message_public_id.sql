-- Random public ids for POST /api/v1/messages sends, and the link from a send to its transport rows
-- (app/ApiMessageIds.php, docs/public-api.md «Message ids»).
--
-- 1. ellsms_api_messages.public_id — a random 16-digit id the API returns instead of the table's
--    auto-increment row id, which was shared by every customer and so told one customer how many
--    API sends the whole platform made between two of theirs. NULL on every row written before
--    this migration: those keep answering to their row id, so an id a customer already stored
--    keeps working. Nothing is backfilled.
--
-- 2. ellsms_api_message_attempts — which ellsms_message_attempts rows one API send wrote, one row
--    per recipient. Lets the panel show «شناسه‌ی API» next to a report row and lets the delivery
--    state routes read exactly a send's own rows instead of matching by number, line, text and time.
--    No foreign keys: ellsms_message_attempts is the busiest ELLSMS table and must not gain a lock
--    dependency; a link whose attempt row is gone is simply never read.
--
-- Additive, guarded and rerun-safe. The application checks for both before using them, so the code
-- may be deployed before or after this file is applied. ellsms_message_attempts is NOT altered.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_api_messages' AND column_name = 'public_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_api_messages ADD COLUMN public_id BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_api_messages' AND index_name = 'uq_api_messages_public_id'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_api_messages ADD UNIQUE KEY uq_api_messages_public_id (public_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_api_message_attempts (
  attempt_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,  -- = ellsms_message_attempts.id
  api_message_id  BIGINT UNSIGNED NOT NULL,              -- = ellsms_api_messages.id (the row id, not public_id)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_api_message (api_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
