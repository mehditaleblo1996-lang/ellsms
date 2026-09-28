-- A sending gateway pinned to a number (the numbers page). NULL keeps today's behaviour: the gateway of
-- the route that sender resolves to, else the default gateway. When set, everything this number sends
-- goes through that gateway only - never silently through another one - while the price still comes
-- from the route, unchanged. See docs/number-gateway.md.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'gateway_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_numbers
     ADD COLUMN gateway_id INT UNSIGNED NULL,
     ADD KEY idx_number_gateway (gateway_id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
