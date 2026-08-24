-- FIN-7 (financial-commerce continuation) — distinguish a pure renewal from a new/changed-plan
-- subscription payment.
--
-- WHY THIS EXISTS. payment_claim_and_activate_subscription() (app/zarinpal.php) is the single,
-- already-tested entry point every subscription payment goes through. Its existing behavior for an
-- organization that already has a subscription is "overwrite plan_id and start a fresh full period
-- from now" — correct for a plan change/upgrade, but NOT what a pure renewal at the SAME plan should
-- do (FIN-7's own rule: extend from current_period_end if it is still in the future, never restart
-- the clock early). Rather than risk that already-tested function's existing, proven behavior, this
-- column lets it BRANCH: purchase_type='renewal' routes to the new subscription_renew() (app/Billing.php),
-- everything else keeps the exact existing activate/plan-change behavior unchanged.
--
-- Defaults to 'new' for every EXISTING row and every INSERT that doesn't set it — zero behavior
-- change for anything already in production.
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped, and a second run emits
-- SELECT 1 no-ops.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_billing_records' AND column_name = 'purchase_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_billing_records ADD COLUMN purchase_type ENUM('new','renewal','upgrade') NOT NULL DEFAULT 'new' AFTER plan_id",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
