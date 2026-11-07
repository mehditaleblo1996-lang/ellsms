-- ---------------------------------------------------------------------------
-- Store every SMS's part count («تعداد پارت») on its own row.
--
-- Until now the part count of a sent or received message was never stored: the
-- reports, analytics and CSV export derived it from the text each time with
-- sms_parts() / sms_parts_sql(). This adds `parts` to ELLSMS's three message
-- tables (docs/message-parts.md):
--
--   ellsms_bulk_items        one row per bulk / p2p / smart / gradual recipient
--   ellsms_message_attempts  direct, API, scheduled, secretary sends
--   ellsms_inbound_messages  SMS received through ELLSMS's own gateways
--
-- New rows get the value when they are written (sms_parts() of the text — the
-- number the send was priced with). Existing rows are filled afterwards, in small
-- batches, by the `message-parts` scheduler job (cron/message-parts-backfill.php)
-- — never here: a single UPDATE over millions of rows would hold the deploy and
-- the table. The backend's own outbound_message / inbound_message are not ELLSMS's
-- to alter (docs/service-boundaries.md); their parts stay derived from the text.
--
-- NULL = not filled yet. A nullable column added at the end is an instant,
-- metadata-only change on MySQL 8 — no table rebuild. Additive, guarded,
-- rerun-safe.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'parts'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD COLUMN parts SMALLINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'parts'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD COLUMN parts SMALLINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_inbound_messages ships with 2026_09_29_gateway_receive.sql; skip quietly if that has not run.
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_inbound_messages'
);
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_inbound_messages' AND column_name = 'parts'
);
SET @sql = IF(@tbl_exists = 1 AND @col_exists = 0,
  'ALTER TABLE ellsms_inbound_messages ADD COLUMN parts SMALLINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
