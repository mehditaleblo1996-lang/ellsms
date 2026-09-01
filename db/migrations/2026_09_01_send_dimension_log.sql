-- ELLSMS-owned sidecar dimension log for non-bulk sends (issue #12 re-audit).
--
-- app/Backend/report_dimension_summary.php (issue #12) already aggregates bulk campaigns fully
-- from ellsms_bulk_items/ellsms_bulk_jobs, which carry every required dimension per item. Direct
-- sends, scheduled sends, and auto-replies have no equivalent per-message row: a legacy-backend
-- (no gateway) success is recorded ONLY in outbound_message, a backend-owned table Invariant E
-- (docs/service-boundaries.md) forbids attaching ELLSMS dimensions to, and even a gateway-path
-- success previously had no daily aggregation at all despite ellsms_message_attempts recording its
-- route/operator.
--
-- This table is that missing ELLSMS-owned record: one row per (dispatch call, resolved route,
-- resolved destination operator, outcome) tuple, written at dispatch time by
-- app/Reports/SendDimensionLog.php, independent of whether the send went through the gateway or
-- the legacy transport. It never duplicates authoritative message storage (no content, no
-- destination numbers, no provider identifiers) -- purely the six reporting dimensions issue #12
-- requires, aggregated the same restart-safe/idempotent way as the bulk table.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_send_dimension_log (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  organization_id INT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = no tenant
  message_type    VARCHAR(40) NOT NULL,               -- MessageClass.php canonical class
  sender_number   VARCHAR(20) NOT NULL DEFAULT '',
  reference_type  VARCHAR(40) NOT NULL,               -- direct_send / schedule / autoreply
  route_id        INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = legacy backend
  operator_id     INT UNSIGNED NOT NULL DEFAULT 0,    -- 0 = unresolved
  status          VARCHAR(20) NOT NULL,               -- sent / failed
  message_count   INT UNSIGNED NOT NULL DEFAULT 1,
  KEY idx_send_dimension_log_created (created_at),
  KEY idx_send_dimension_log_org_created (organization_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_send_dimension_summary_state (
  id                   TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_log_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_incremental_at  DATETIME NULL,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ellsms_send_dimension_summary_state (id, last_log_id) VALUES (1, 0);
