-- AI pricing and charging (تعرفه‌ی هوش مصنوعی), phase 3 of the AI engines feature
-- (docs/ai-billing.md, app/Ai/AiBilling.php).
--
-- An AI answer is priced like an SMS segment, through the same chain: a platform list price per
-- model, an account's own exact price or the list price times its tariff percentage, and a reseller's
-- named tariffs (never below what the reseller itself pays), with the reseller's profit paid by the
-- existing daily profit run.
--
-- One price = three numbers: the price of one answer, the tokens one answer includes, and the price of
-- every 1,000 tokens beyond that. Prices are in millicredits (thousandths of a Rial), like
-- ellsms_gateway_prices and ellsms_reseller_tariff_prices.
--
-- Requires 2026_11_03_ai_engines.sql. No table outside this file is altered. Guarded by IF NOT EXISTS,
-- so re-running this file changes nothing.
SET NAMES utf8mb4;

-- 1. Prices. scope/owner_id: 'global'/0 = the platform list price, 'account'/user id = that account's
--    own exact price (admin), 'tariff'/tariff id = a reseller's named tariff (ellsms_reseller_tariffs).
--    model_id 0 = the row for every model without a row of its own.
--    included_tokens is read from the global rows only (what one answer includes is a property of the
--    product, not of who sells it); NULL elsewhere.
CREATE TABLE IF NOT EXISTS ellsms_ai_prices (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope                  ENUM('global','account','tariff') NOT NULL,
  owner_id               BIGINT NOT NULL DEFAULT 0,
  model_id               INT UNSIGNED NOT NULL DEFAULT 0,
  answer_price_millis    BIGINT UNSIGNED NULL,
  overage_per_1k_millis  BIGINT UNSIGNED NULL,
  included_tokens        INT UNSIGNED NULL,
  updated_by             BIGINT NULL,
  updated_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ai_price (scope, owner_id, model_id),
  KEY idx_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. One row per charged (or deliberately free / unpaid) AI answer — the snapshot of how it was
--    priced. reference_id is 'job:<ellsms_ai_jobs.id>' for a secretary answer or 'test:<token>' for a
--    customer's «آزمایش منشی»; it is also the wallet reference (reference_type 'ai_usage'), so the
--    profit run finds chain_prices here: [[user_id, type, millicredits], ...] from the charged
--    account up its reseller chain.
--    status: charged (wallet debited) | free (price 0, or billing off) | unpaid (the debit failed —
--    the balance ran out between the check and the charge; the answer had already been sent).
CREATE TABLE IF NOT EXISTS ellsms_ai_charges (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_id           VARCHAR(64) NOT NULL,
  user_id                BIGINT NOT NULL,
  model_id               INT UNSIGNED NULL,
  input_tokens           INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens          INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_estimated       TINYINT(1) NOT NULL DEFAULT 0,
  included_tokens        INT UNSIGNED NOT NULL DEFAULT 0,
  answer_price_millis    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  overage_per_1k_millis  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount_millis          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount                 BIGINT UNSIGNED NOT NULL DEFAULT 0,      -- Rial actually debited (rounded up)
  purchase_cost          DECIMAL(20,8) NULL,                     -- what the provider charges us, in the model's currency
  chain_prices           JSON NULL,
  status                 ENUM('charged','free','unpaid') NOT NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reference (reference_id),
  KEY idx_user_created (user_id, created_at),
  KEY idx_model_created (model_id, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. On each secretary job (2026_11_04_ai_secretary.sql): whether its token counts are an estimate
--    (the provider reported no usage), and the Rial charged for its AI part, for the customer's log.
--    Guarded so re-running is a no-op.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_jobs' AND column_name = 'tokens_estimated'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_ai_jobs ADD COLUMN tokens_estimated TINYINT(1) NOT NULL DEFAULT 0 AFTER output_tokens',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_ai_jobs' AND column_name = 'ai_charge'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_ai_jobs ADD COLUMN ai_charge BIGINT UNSIGNED NULL AFTER cost_estimate',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
