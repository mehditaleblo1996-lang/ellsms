-- Rial-denominated wallet (docs/rial-currency.md).
--
-- One wallet unit ("credit") is now one Rial, the same unit the Negar backend reads from
-- user_.currentcredit. This file only prepares the SCHEMA and the new setting; it changes no
-- balance, price or historical amount. Converting existing data from the old credit unit to Rial is
-- a separate, explicit operator step (`make rial-conversion-dry-run`, then `make rial-conversion`),
-- because it multiplies money and must be reviewed before it runs.
--
-- 1. Amount columns that were INT UNSIGNED (max ~4.29 billion) are widened to BIGINT UNSIGNED.
--    In Rial a large purchase, a regional send to a whole province or a reseller's alert threshold
--    can pass that limit; the old credit unit never came close. ellsms_bulk_items.price_cost_credits
--    and ellsms_channel_messages.cost_credits stay INT: they hold the cost of ONE message, and
--    rebuilding those (very large) tables buys nothing.
-- 2. The currency columns default to 'IRR' so rows inserted without an explicit currency are
--    labelled correctly. Existing rows keep 'credit' until the conversion rewrites them, which is
--    how the conversion knows what it has already converted.
-- 3. `sms_default_price_rial` (the legacy fallback's per-segment price, 1200 Rial) is seeded if
--    absent. INSERT IGNORE: an admin's value is never overwritten.
SET NAMES utf8mb4;

ALTER TABLE ellsms_payments MODIFY COLUMN credits BIGINT UNSIGNED NOT NULL;
ALTER TABLE ellsms_pricing_packages MODIFY COLUMN credit_amount BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ellsms_organization_notification_preferences MODIFY COLUMN low_credit_threshold BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE ellsms_regional_bulk_requests
  MODIFY COLUMN charged_credits BIGINT UNSIGNED NULL,
  MODIFY COLUMN settled_credits BIGINT UNSIGNED NULL;

ALTER TABLE ellsms_sms_route_prices ALTER COLUMN currency SET DEFAULT 'IRR';
ALTER TABLE ellsms_sms_price_snapshots ALTER COLUMN currency SET DEFAULT 'IRR';
ALTER TABLE ellsms_import_jobs ALTER COLUMN currency SET DEFAULT 'IRR';

INSERT IGNORE INTO ellsms_settings (skey, svalue) VALUES ('sms_default_price_rial', '1200');
