-- «ارسال خودکار نظیر به نظیر» — P2P auto-send (app/P2pAutoSend.php, docs/p2p-auto-send.md).
--
-- A platform admin ticks «ارسال خودکار نظیر به نظیر» on a user (public/users.php). A large P2P file
-- of that user then starts sending as soon as it has been analyzed and loaded, without waiting for
-- the user's «ادامه و تأیید ارسال». The flag is on the user's own ellsms_meta row, with when (UTC)
-- and by which admin it was set.
--
-- Additive, guarded and rerun-safe.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_meta' AND column_name = 'p2p_auto_send'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_meta
     ADD COLUMN p2p_auto_send TINYINT(1) NOT NULL DEFAULT 0,
     ADD COLUMN p2p_auto_send_set_at DATETIME NULL,
     ADD COLUMN p2p_auto_send_set_by BIGINT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
