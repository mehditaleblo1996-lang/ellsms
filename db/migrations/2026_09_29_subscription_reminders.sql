-- #41 — reminders before a subscription, trial or grace period ends (app/SubscriptionReminders.php).
-- One row per reminder actually sent: UNIQUE (subscription, end moment, offset) makes each reminder
-- go out once per period even with concurrent runs, and a renewal (new end moment) re-arms them.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_subscription_reminders (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id BIGINT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NOT NULL,
  kind            ENUM('period','trial','grace') NOT NULL,
  ends_at         DATETIME NOT NULL,
  offset_days     SMALLINT UNSIGNED NOT NULL,
  sms_sent        TINYINT(1) NOT NULL DEFAULT 0,
  email_sent      TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reminder (subscription_id, ends_at, offset_days),
  KEY idx_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
