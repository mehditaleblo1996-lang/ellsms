-- Periodic job scheduler (cron/scheduler.php, app/Scheduler.php, docs/scheduler.md).
--
-- One row per job: when it last started and finished, how it ended and the tail of its output.
-- The scheduler decides from last_started_at whether a job is due, so a restart neither re-runs
-- every job at once nor skips the day's backup; `make scheduler-status` reads the same rows.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_scheduler_runs (
  job                   VARCHAR(64) NOT NULL PRIMARY KEY,
  last_started_at       DATETIME NULL,              -- PHP's local time (Asia/Tehran), like the daily slots
  last_finished_at      DATETIME NULL,
  last_exit_code        INT NULL,                   -- NULL while running; non-zero = failed
  last_duration_ms      INT UNSIGNED NULL,
  last_output           TEXT NULL,                  -- the last few KB of its stdout+stderr
  last_success_at       DATETIME NULL,
  consecutive_failures  INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
