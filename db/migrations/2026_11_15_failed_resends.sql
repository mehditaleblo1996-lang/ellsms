-- «ارسال مجدد ناموفق‌ها» — resend a bulk job's failed rows as a new p2p job
-- (app/FailedResend.php, docs/failed-resend.md).
--
-- One row per resend: which job's failures were resent, the new job they became, who clicked
-- (actor — a platform admin may resend a customer's job) and whose account the new job is on
-- (owner). The job page lists a job's resends from here, and a resent job links back to its source.
--
-- Additive, guarded and rerun-safe. Without it the feature still works; only the history is missing.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_failed_resends (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_job_id  BIGINT UNSIGNED NOT NULL,
  new_job_id     BIGINT UNSIGNED NOT NULL,
  actor_user_id  BIGINT NOT NULL,
  owner_user_id  BIGINT NOT NULL,
  item_count     INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_failed_resends_source (source_job_id, id),
  KEY idx_failed_resends_new (new_job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
