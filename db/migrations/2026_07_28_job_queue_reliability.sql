-- Phase 4 — worker reliability, atomic job claiming, lease-based crash recovery, retry policy
-- (docs/technical-debt.md TD-007/TD-008/TD-009; docs/job-queue-architecture.md).
--
-- Adds claim/lease/retry metadata to the three ELLSMS-owned tables background workers already
-- process (ellsms_bulk_items, ellsms_schedule, ellsms_autoreply_log). Nothing here touches a
-- backend-owned table. Every ALTER is guarded (information_schema check first), consistent with
-- every prior migration in this project — safe to re-run.
--
-- claimed_by:        worker_id() string (hostname:pid:random) that currently owns the row, or the
--                     one that owned it last — never cleared to NULL after a terminal outcome, so
--                     "who last touched this" stays visible for debugging even after completion.
-- lease_expires_at:   NULL when not actively claimed; while an owner holds the row, this is when
--                     that ownership expires and the row becomes reclaimable by anyone (crash
--                     recovery — Invariant D). Cleared back to NULL on every terminal outcome.
-- attempt_count:      incremented on every claim (not every retry decision) — the definitive count
--                     of "how many times has a worker picked this up," used against JOB_MAX_ATTEMPTS.
-- next_attempt_at:    NULL = immediately claimable (subject to normal due-time rules). Set on a
--                     retryable failure to the backoff-computed next eligible time — the row stays
--                     in its normal non-terminal status throughout (see job-queue-architecture.md's
--                     "retry-wait is not a status" note), so existing status-based queries/UI that
--                     already treat that status as "still outstanding" keep working unmodified.
SET NAMES utf8mb4;

-- ellsms_bulk_items: widen status to add 'processing' (the missing claim state — previously items
-- went pending -> sent/failed with NO in-flight marker at all, so two workers could both SELECT and
-- both send the same pending row) and 'cancelled' (STEP 21 — explicit item-level visibility when a
-- parent job is cancelled, instead of leaving cancelled jobs' unprocessed rows looking like they're
-- still queued forever).
SET @col_type = (
  SELECT COLUMN_TYPE FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'status'
);
SET @sql = IF(@col_type NOT LIKE '%cancelled%',
  "ALTER TABLE ellsms_bulk_items MODIFY status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND column_name = 'claimed_by'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_bulk_items
     ADD COLUMN claimed_by VARCHAR(80) NULL AFTER status,
     ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by,
     ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_at,
     ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_expires_at,
     ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count,
     ADD INDEX idx_claim (job_id, status, next_attempt_at),
     ADD INDEX idx_lease (status, lease_expires_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_schedule: same claim/lease/retry metadata. Status enum is unchanged — a retryable failure
-- keeps status='active' (see next_attempt_at note above); a schedule's own finalize UPDATE already
-- computes its next occurrence in one statement (recurring-safety was already correct, see
-- docs/job-queue-architecture.md), this migration only adds what Phase 4 needed on top: lease-based
-- stuck-row recovery and a cancellation-race guard.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_schedule' AND column_name = 'claimed_by'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_schedule
     ADD COLUMN claimed_by VARCHAR(80) NULL AFTER status,
     ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by,
     ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_at,
     ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER lease_expires_at,
     ADD COLUMN next_attempt_at DATETIME NULL AFTER attempt_count,
     ADD INDEX idx_due (status, next_attempt_at, run_at),
     ADD INDEX idx_lease (status, lease_expires_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ellsms_autoreply_log: the UNIQUE(inbound_message_id) claim (an INSERT) already prevents two
-- workers from both replying to the same inbound row — that part was already correct. What was
-- missing: a crash between the claim-INSERT and the actual send left `ok=0` permanently, with no
-- way to distinguish "still being processed by a live worker" from "processed and failed," so a
-- stuck row could never be retried (the UNIQUE key blocks a second INSERT forever). `status` makes
-- that distinction explicit; claim metadata lets a UPDATE-based reclaim replace the blocked
-- second-INSERT path once the lease expires.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_autoreply_log' AND column_name = 'status'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_autoreply_log
     ADD COLUMN status ENUM('processing','sent','failed_retryable','failed_permanent') NOT NULL DEFAULT 'processing' AFTER ok,
     ADD COLUMN claimed_by VARCHAR(80) NULL AFTER status,
     ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_by,
     ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 1 AFTER lease_expires_at,
     ADD INDEX idx_lease (status, lease_expires_at)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
