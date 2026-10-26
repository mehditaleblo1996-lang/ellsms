-- Operator reports (public/operator-reports.php) — a daily pre-aggregated table.
--
-- The page used to GROUP BY the raw message tables on every load: over a month of bulk sends that is
-- millions of rows (and their TEXT content, for the Persian/English split) per request. Instead
-- report-summary-worker (app/Reports/OperatorReport.php, operator_report_worker_pass()) folds each
-- Tehran day into a few hundred rows here, and the page sums those. A day not built yet is still
-- counted live, so a fresh install or a backlog never shows missing numbers.
--
-- Days are rebuilt whole (DELETE + INSERT in one transaction), never incremented: message statuses
-- keep changing after the send (delivery reports, gradual sends leaving the queue), so recent days are
-- rebuilt often and older ones rarely — see operator_report_days_due().
--
-- Additive, rerun-safe, no generated columns, no foreign keys (same rationale as every ellsms_* table).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_operator_report_daily (
  period_date     DATE NOT NULL,                 -- Tehran calendar day the message was sent/queued on
  source          ENUM('bulk','gateway','legacy') NOT NULL,
  user_id         BIGINT NOT NULL DEFAULT 0,
  organization_id INT UNSIGNED NOT NULL DEFAULT 0,
  sender          VARCHAR(32) NOT NULL DEFAULT '',
  route_id        INT UNSIGNED NOT NULL DEFAULT 0, -- 0 = no route (legacy backend sends)
  operator        VARCHAR(120) NOT NULL,           -- display name, resolved when the day was built
  encoding        ENUM('fa','en','na') NOT NULL,
  status          VARCHAR(16) NOT NULL,
  hidden          TINYINT(1) NOT NULL DEFAULT 0,   -- removed from the customer's panel (app/HiddenMessages.php)
  message_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (period_date, source, user_id, organization_id, sender, route_id, operator, encoding, status, hidden),
  KEY idx_operator_report_org (organization_id, period_date),
  KEY idx_operator_report_user (user_id, period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which days are built, and when: the page counts unbuilt days live; the worker picks what to rebuild.
CREATE TABLE IF NOT EXISTS ellsms_operator_report_days (
  period_date  DATE NOT NULL PRIMARY KEY,
  built_at     DATETIME NOT NULL,                -- UTC
  queued_count BIGINT UNSIGNED NOT NULL DEFAULT 0, -- messages still in queue when built: rebuilt sooner
  build_ms     INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
