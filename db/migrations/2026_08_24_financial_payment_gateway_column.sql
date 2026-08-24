-- FIN-2 (financial-commerce continuation) — record which gateway adapter a payment was created
-- through.
--
-- WHY THIS EXISTS. Before this phase, ellsms_payments implicitly meant "a ZarinPal payment" — there
-- was only ever one gateway. FIN-2 introduces a generic gateway abstraction
-- (app/Payment/PaymentGateway.php) with a second real adapter (the fake/sandbox gateway, FIN-3), so a
-- payment row must now record WHICH adapter created it: cron/payments-reconcile.php and any future
-- re-verify path must dispatch a stale/pending payment to the SAME gateway that issued its authority,
-- never assume ZarinPal.
--
-- Defaults to 'zarinpal' for every EXISTING row (and every row created by code that doesn't set it
-- yet) — exactly matching this project's actual prior behavior (there was no other gateway), so this
-- is a zero-behavior-change additive column, not a data migration.
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped, and a second run emits
-- SELECT 1 no-ops.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'gateway'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_payments ADD COLUMN gateway VARCHAR(20) NOT NULL DEFAULT 'zarinpal' AFTER status",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
