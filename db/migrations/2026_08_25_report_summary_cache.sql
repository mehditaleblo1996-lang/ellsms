-- Materialized daily report summary.
--
-- The reporting UI must never aggregate hundreds of thousands of outbound rows on page load.
-- A persistent worker maintains this ELLSMS-owned cache from backend-owned outbound_message.
-- No foreign key to user_: outbound_message/user_ are backend-owned and the existing service
-- boundary deliberately avoids coupling ELLSMS migrations to backend schema ownership.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_report_daily_summary (
  sender_user_id      BIGINT NOT NULL,
  period_date         DATE NOT NULL,
  total_count         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_count          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  delivered_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  send_failed_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_count        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pending_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  unknown_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (sender_user_id, period_date),
  KEY idx_report_daily_period (period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_report_summary_state (
  id                   TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_outbound_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_incremental_at  DATETIME NULL,
  last_full_rebuild_at DATETIME NULL,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ellsms_report_summary_state (id, last_outbound_id)
VALUES (1, 0);
