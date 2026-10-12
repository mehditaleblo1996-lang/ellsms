-- Per-customer SMS price per operator (app/CustomerHierarchy.php, docs/customer-operator-prices.md).
--
-- A platform admin can give one account a fixed Rial price per segment for each operator, plus an
-- optional "every other operator" price (operator_id NULL) that also covers numbers whose operator
-- is unknown. Such a price replaces the route price AND the account's tariff percentage for that
-- operator; operators without one are priced as before (route price x tariff percentage).
--
-- Effective-dated like ellsms_sms_route_prices: a change closes the current row and opens a new one,
-- and pricing reads the row in effect at the send's pricing instant. That is what lets an import
-- that was costed at analysis time be queued at the same price, and a retried send re-use its
-- accepted price. Historical sends keep the price recorded in their snapshot either way.
--
-- operator_slot = COALESCE(operator_id, 0), for the same reason as on ellsms_sms_route_prices: a
-- unique index ignores NULLs, so the "every other operator" rows need a non-NULL key.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_customer_operator_prices (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                         BIGINT NOT NULL,              -- the account the price belongs to (user_.id)
  operator_id                     INT UNSIGNED NULL,            -- NULL = every other operator, and unknown numbers
  operator_slot                   INT UNSIGNED NOT NULL DEFAULT 0,
  price_per_segment_millicredits  BIGINT UNSIGNED NOT NULL,     -- thousandths of a Rial, never a float
  effective_from                  DATETIME NOT NULL,            -- UTC
  effective_to                    DATETIME NULL,                -- UTC, exclusive; NULL = still in effect
  note                            VARCHAR(255) NOT NULL DEFAULT '',
  created_by                      BIGINT NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_customer_price_period (user_id, operator_slot, effective_from),
  KEY idx_customer_price_lookup (user_id, effective_from, effective_to),
  CONSTRAINT fk_customer_price_operator FOREIGN KEY (operator_id) REFERENCES ellsms_sms_operators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
