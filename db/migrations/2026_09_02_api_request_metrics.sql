-- ELLSMS — bounded API request metrics for Prometheus (issue #14 final audit).
--
-- One row per (route, method, status bucket) -- a small, fixed-size table (route handler names and
-- HTTP methods are both bounded enums, status bucket is 2xx/4xx/5xx/unknown), incremented in place
-- rather than growing one row per request. A single indexed UPSERT per API request, not a log table.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_api_request_metrics (
  route             VARCHAR(80) NOT NULL,
  method            VARCHAR(10) NOT NULL,
  status_bucket     VARCHAR(10) NOT NULL,
  request_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (route, method, status_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
