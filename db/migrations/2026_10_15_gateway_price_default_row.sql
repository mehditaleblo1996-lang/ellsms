-- A "default for every gateway" row in the price matrix (app/GatewayPrices.php,
-- docs/gateway-price-matrix.md).
--
-- A row with gateway_id NULL holds, per destination column and encoding, the price for every gateway
-- that has no price of its own in that cell — on the global matrix and on an account's matrix. It is
-- what an admin sets as "the default tariff", and a new gateway follows it without being filled in.
--
-- gateway_slot = COALESCE(gateway_id, 0) carries the uniqueness, because a unique index ignores
-- NULLs. ellsms_sms_gateways.id is AUTO_INCREMENT and never 0. Every step is guarded, so re-running
-- this file changes nothing.
SET NAMES utf8mb4;

SET @has_slot = (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'ellsms_gateway_prices' AND column_name = 'gateway_slot');
SET @sql = IF(@has_slot = 0,
  'ALTER TABLE ellsms_gateway_prices
     MODIFY COLUMN gateway_id INT UNSIGNED NULL,
     ADD COLUMN gateway_slot INT UNSIGNED NOT NULL DEFAULT 0 AFTER gateway_id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE ellsms_gateway_prices SET gateway_slot = COALESCE(gateway_id, 0) WHERE gateway_slot <> COALESCE(gateway_id, 0);

SET @has_old_key = (SELECT COUNT(*) FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = 'ellsms_gateway_prices'
                      AND index_name = 'uniq_gateway_price_period' AND column_name = 'gateway_id');
SET @sql = IF(@has_old_key > 0,
  'ALTER TABLE ellsms_gateway_prices
     DROP INDEX uniq_gateway_price_period,
     ADD UNIQUE KEY uniq_gateway_price_period (user_slot, gateway_slot, column_key, encoding, effective_from)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
