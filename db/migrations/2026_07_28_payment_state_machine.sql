-- Phase 3 — payment state machine (STEP 14, docs/security-review.md finding 6).
--
-- Splits the old single 'failed' status into two distinct outcomes:
--   - 'failed'               — the USER explicitly cancelled/declined at the ZarinPal checkout
--                              screen (Status != OK on redirect back). A real, final outcome.
--   - 'verification_failed'  — the verify() API call itself didn't succeed (network error,
--                              ZarinPal API error, or a non-100/101 code) — this is NOT the same
--                              as the user cancelling, and may be transient. Payments in this
--                              state are retried by `make payments-reconcile`
--                              (cron/payments-reconcile.php), which calls zarinpal_verify() again
--                              rather than treating the payment as permanently dead.
--
-- Guarded/idempotent like every other migration in this project.
SET NAMES utf8mb4;

SET @col_type = (
  SELECT COLUMN_TYPE FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'status'
);
SET @sql = IF(@col_type NOT LIKE '%verification_failed%',
  "ALTER TABLE ellsms_payments MODIFY status ENUM('pending','verification_failed','paid','failed') NOT NULL DEFAULT 'pending'",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
