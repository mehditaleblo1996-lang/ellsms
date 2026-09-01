-- Issue #16: upgrades issue #10's minimal binary healthy/outage seam into the full
-- UP/DEGRADED/DOWN/UNKNOWN model with hysteresis, in place -- the SAME ellsms_provider_health_state
-- table and provider_key vocabulary, never a second/parallel health system.
--
-- Existing data is migrated forward, not discarded: 'healthy' -> 'up' (a provider already observed
-- succeeding), 'outage' -> 'down' (a provider already confirmed failing past the old threshold).
-- Any provider with no rows yet naturally starts 'unknown' once created by the first passive/active
-- check, matching the required "UNKNOWN until sufficient evidence" starting state.
SET NAMES utf8mb4;

SET @needs_status_migration = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_provider_health_state'
    AND column_name = 'status' AND column_type LIKE '%outage%'
);
SET @sql = IF(@needs_status_migration = 1,
  "ALTER TABLE ellsms_provider_health_state
     MODIFY COLUMN status ENUM('unknown','up','degraded','down','healthy','outage') NOT NULL DEFAULT 'unknown'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE ellsms_provider_health_state SET status = 'up' WHERE status = 'healthy';
UPDATE ellsms_provider_health_state SET status = 'down' WHERE status = 'outage';

SET @needs_status_migration = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_provider_health_state'
    AND column_name = 'status' AND column_type LIKE '%healthy%'
);
SET @sql = IF(@needs_status_migration = 1,
  "ALTER TABLE ellsms_provider_health_state
     MODIFY COLUMN status ENUM('unknown','up','degraded','down') NOT NULL DEFAULT 'unknown'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Hysteresis counters (consecutive_failures already existed) and a cheap exponential moving
-- average of successful-request latency -- no per-request row storage, so this stays O(1) per
-- provider regardless of traffic volume.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_provider_health_state' AND column_name = 'consecutive_successes'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_provider_health_state
     ADD COLUMN consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0 AFTER consecutive_failures,
     ADD COLUMN avg_latency_ms DOUBLE NULL AFTER consecutive_successes,
     ADD COLUMN consecutive_timeouts INT UNSIGNED NOT NULL DEFAULT 0 AFTER avg_latency_ms,
     ADD COLUMN last_timeout_at DATETIME NULL AFTER last_failure_at,
     ADD COLUMN last_transition_at DATETIME NULL AFTER updated_at,
     ADD COLUMN last_check_source ENUM('passive','active') NULL AFTER last_transition_at",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
