-- Daily metadata aggregation for reporting, dimensioned by tenant/message-type/provider/sender
-- number/destination-operator/status (issue #12).
--
-- SCOPE: bulk sends only (ellsms_bulk_items/ellsms_bulk_jobs), which is the one place ELLSMS
-- already records every one of these six dimensions per message: organization_id and originator on
-- the job, message_class (bulk_campaign/advertising) on the job, and route_id/operator_id (what was
-- ACTUALLY used, per 2026_08_15_delivery_reporting.sql) plus status on the item. Direct/scheduled/
-- auto-reply single sends are a known gap, documented in docs/daily-metadata-aggregation.md: a
-- successful legacy-backend (no gateway configured) send is recorded ONLY in outbound_message, a
-- backend-owned table Invariant E forbids attaching ELLSMS dimensions to (see
-- docs/service-boundaries.md) -- there is no route/operator/sender-number-at-send-time to aggregate
-- for that path today. The existing ellsms_report_daily_summary (2026_08_25) already covers
-- undimensioned totals for every send, direct included; this table adds dimensions where they
-- genuinely exist without fabricating them where they don't.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_report_daily_dimension_summary (
  period_date     DATE NOT NULL,
  organization_id INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = no tenant (pre-tenant-backfill/legacy job)
  message_type    VARCHAR(40) NOT NULL,               -- ellsms_bulk_jobs.message_class
  sender_number   VARCHAR(20) NOT NULL DEFAULT '',    -- ellsms_bulk_jobs.originator
  route_id        INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = legacy backend / not resolved via a gateway route
  operator_id     INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = not resolved
  status          VARCHAR(20) NOT NULL,               -- ellsms_bulk_items.status: sent/failed/cancelled
  message_count   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (period_date, organization_id, message_type, sender_number, route_id, operator_id, status),
  KEY idx_dimension_summary_period (period_date),
  KEY idx_dimension_summary_org_period (organization_id, period_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_report_dimension_summary_state (
  id                   TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_bulk_item_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_incremental_at  DATETIME NULL,
  last_full_rebuild_at DATETIME NULL,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ellsms_report_dimension_summary_state (id, last_bulk_item_id)
VALUES (1, 0);
