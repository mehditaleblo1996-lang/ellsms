-- Phase 2 — 2FA hardening (docs/security-review.md finding 7).
--
-- code_hash replaces the plaintext `code` column — a database leak no
-- longer immediately reveals active OTP values (SHA-256 over a 6-digit
-- code isn't meant to resist offline brute force by itself; the actual
-- protections are the short TTL, the per-challenge attempts cap below,
-- and the rate limiter — this column exists so the raw value is never
-- sitting in the table regardless).
--
-- attempts is a durable, per-challenge wrong-guess counter that lives on
-- the row itself — restarting the login flow, refreshing the session, or
-- requesting a new code cannot reset it back to zero for an
-- already-issued challenge (a NEW challenge naturally starts at zero,
-- which is why this is paired with a per-USER rate limit on the verify
-- endpoint — see app/rate_limit.php and public/verify-2fa.php — for the
-- cross-challenge ceiling).
--
-- superseded_at lets send_2fa_code() invalidate every prior unconsumed
-- code for a user the moment a new one is issued, so at most one code is
-- ever valid at a time instead of every past "resend" staying live until
-- its own 5-minute expiry.
--
-- Guarded/idempotent like every other migration in this project.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_2fa_codes' AND column_name = 'code_hash'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_2fa_codes
     ADD COLUMN code_hash VARCHAR(64) NULL AFTER code,
     ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash,
     ADD COLUMN superseded_at DATETIME NULL AFTER consumed',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop the legacy plaintext column now that code_hash exists. Safe: rows
-- here are single-use, 5-minute-lived login challenges, never data worth
-- preserving — any code mid-flight at the moment this migration runs
-- simply becomes unverifiable and the user requests a new one.
SET @code_col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_2fa_codes' AND column_name = 'code'
);
SET @sql2 = IF(@code_col_exists > 0,
  'ALTER TABLE ellsms_2fa_codes DROP COLUMN code',
  'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
