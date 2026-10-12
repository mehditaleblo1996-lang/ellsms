-- SMS price matrix: sending gateway x destination operator x encoding (app/GatewayPrices.php,
-- docs/gateway-price-matrix.md).
--
-- One cell = the Rial price of one segment sent through a gateway (عامل ارسال) to one destination
-- column, for Persian (fa, UCS-2) or Latin (en, GSM-7) text. Columns are each operator in
-- ellsms_sms_operators, 'other' (an Iranian number with no known operator) and 'intl' (a number
-- outside Iran). Rows with user_id NULL are the global price list; rows with a user_id are that
-- account's own prices, which win over the global list and are charged exactly (no tariff
-- percentage). An empty cell falls back: account -> global -> the route price.
--
-- Effective-dated like ellsms_sms_route_prices: a change closes the cell's current period and opens
-- a new one at the same instant, and pricing reads the period in effect at the pricing instant.
--
-- user_slot = COALESCE(user_id, 0), because a unique index ignores NULLs and the global rows need a
-- key too. user_.id is never 0.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_gateway_prices (
  id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id                         BIGINT NULL,                  -- NULL = global price list
  user_slot                       BIGINT NOT NULL DEFAULT 0,
  gateway_id                      INT UNSIGNED NOT NULL,
  column_key                      VARCHAR(20) NOT NULL,         -- operator id as digits, 'other' or 'intl'
  encoding                        ENUM('fa','en') NOT NULL,
  price_per_segment_millicredits  BIGINT UNSIGNED NOT NULL,     -- thousandths of a Rial
  effective_from                  DATETIME NOT NULL,            -- UTC
  effective_to                    DATETIME NULL,                -- UTC, exclusive; NULL = in effect
  created_by                      BIGINT NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gateway_price_period (user_slot, gateway_id, column_key, encoding, effective_from),
  KEY idx_gateway_price_lookup (user_slot, effective_to, effective_from),
  CONSTRAINT fk_gateway_price_gateway FOREIGN KEY (gateway_id) REFERENCES ellsms_sms_gateways(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry over the per-operator account prices of 2026_10_13_customer_operator_prices.sql: each one
-- becomes the same price on every active gateway, for both encodings. Its "every other operator"
-- price covered unknown and foreign numbers alike, so it fills both 'other' and 'intl'. Runs only
-- for accounts that have no matrix price yet, so re-running copies nothing twice.
INSERT INTO ellsms_gateway_prices (user_id, user_slot, gateway_id, column_key, encoding, price_per_segment_millicredits, effective_from, created_by)
SELECT p.user_id, p.user_id, g.id,
       IF(p.operator_id IS NULL, k.kind, CAST(p.operator_id AS CHAR)),
       e.encoding, p.price_per_segment_millicredits, UTC_TIMESTAMP(), p.created_by
FROM ellsms_customer_operator_prices p
JOIN ellsms_sms_gateways g ON g.status = 'active'
JOIN (SELECT 'fa' AS encoding UNION ALL SELECT 'en') e
JOIN (SELECT 'op' AS kind UNION ALL SELECT 'other' UNION ALL SELECT 'intl') k
  ON (p.operator_id IS NOT NULL AND k.kind = 'op') OR (p.operator_id IS NULL AND k.kind <> 'op')
WHERE p.effective_to IS NULL
  AND NOT EXISTS (SELECT 1 FROM ellsms_gateway_prices x WHERE x.user_slot = p.user_id);
