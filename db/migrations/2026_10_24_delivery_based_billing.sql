-- Delivery-based billing (ارسال بر اساس تحویل) — app/DeliveryRefunds.php, docs/delivery-based-billing.md.
--
-- An admin can switch it on for one user at a time. While it is on, every message that user sends
-- through the gateway transport and whose FINAL delivery report is failed / rejected / expired gets
-- a configurable percentage of what it was charged credited back to the wallet. Messages that never
-- get a final report (unknown, polling window ran out) are not refunded: there is no proof they were
-- not delivered.
--
-- ellsms_delivery_billing_policies: the current switch per user, and the row the save path locks
-- so two admins saving at once cannot both open a period.
--
-- ellsms_delivery_billing_periods: every stretch of time the service was on, with the percentage
-- that applied. A message is refunded under the period that covered the moment it was SENT, so
-- switching the service off (or changing the percentage) never changes the outcome for a message
-- already in flight, and a message sent while it was off is never refunded later.
--
-- ellsms_delivery_refunds: one row per decided message. UNIQUE(source, source_id) is the
-- exactly-once guarantee; the wallet credit it pairs with uses its own idempotency key on top.
--
-- ellsms_message_attempts.charged_credits: what one direct/scheduled/API destination actually
-- cost. A bulk item's charge is already one ledger row of its own (commit:bulk_item:{id}); a direct
-- send commits one ledger row for every destination together, so the per-destination share has to
-- be recorded on the attempt row at settlement.
--
-- Additive, guarded and rerun-safe: nothing is backfilled, nothing is dropped. Messages sent before
-- the migration have no charged_credits and are never refunded automatically.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_delivery_billing_policies (
  user_id            BIGINT NOT NULL PRIMARY KEY,
  enabled            TINYINT(1) NOT NULL DEFAULT 0,
  refund_percent     TINYINT UNSIGNED NOT NULL DEFAULT 100,
  updated_by_user_id BIGINT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_delivery_billing_periods (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id            BIGINT NOT NULL,
  refund_percent     TINYINT UNSIGNED NOT NULL,
  started_at         DATETIME NOT NULL,
  ended_at           DATETIME NULL,
  started_by_user_id BIGINT NULL,
  ended_by_user_id   BIGINT NULL,
  KEY idx_delivery_period_user (user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_delivery_refunds (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  organization_id INT UNSIGNED NULL,
  source          VARCHAR(20) NOT NULL,        -- 'bulk_item' | 'attempt' (same names as gateway_status_record())
  source_id       BIGINT UNSIGNED NOT NULL,
  period_id       BIGINT UNSIGNED NOT NULL,
  delivery_status VARCHAR(20) NOT NULL,
  charged_amount  BIGINT UNSIGNED NOT NULL,
  refund_percent  TINYINT UNSIGNED NOT NULL,
  refund_amount   BIGINT UNSIGNED NOT NULL,    -- 0 when the percentage rounds a tiny charge down to nothing
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_delivery_refund_source (source, source_id),
  KEY idx_delivery_refund_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_message_attempts' AND column_name = 'charged_credits'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_message_attempts ADD COLUMN charged_credits BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
