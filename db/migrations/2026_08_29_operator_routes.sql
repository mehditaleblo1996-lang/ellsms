-- Issue #8: destination-operator routing precedence, the missing middle step between
-- sender-number routing and the default route. sms_pricing_route_for_sender() (app/Sms/Pricing.php)
-- already resolves the destination's operator via prefix detection (sms_resolve_operator()) for
-- PRICING purposes (ellsms_sms_route_prices.operator_id); this is the same concept applied to
-- PROVIDER SELECTION instead, mirroring ellsms_sender_routes' exact shape/uniqueness pattern.
--
-- Guarded (information_schema check + PREPARE/EXECUTE) — matches every other migration.
SET @table_exists = (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_operator_routes'
);
SET @sql = IF(@table_exists = 0,
  'CREATE TABLE ellsms_operator_routes (
     id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
     operator_id  INT UNSIGNED NOT NULL,
     message_type ENUM(''promotional'',''transactional'',''otp'',''default'') NOT NULL DEFAULT ''default'',
     route_id     INT UNSIGNED NOT NULL,
     status       ENUM(''active'',''archived'') NOT NULL DEFAULT ''active'',
     priority     INT NOT NULL DEFAULT 0,
     created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     active_slot  VARCHAR(48) NULL,
     UNIQUE KEY uniq_active_operator_route (active_slot),
     KEY idx_operator_route (operator_id, status),
     CONSTRAINT fk_operator_route_operator FOREIGN KEY (operator_id) REFERENCES ellsms_sms_operators(id) ON DELETE RESTRICT,
     CONSTRAINT fk_operator_route_route FOREIGN KEY (route_id) REFERENCES ellsms_sms_routes(id) ON DELETE RESTRICT
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
