-- «مشتری ویژه» — VIP customers (app/VipCustomers.php, docs/vip-customers.md).
--
-- A platform admin ticks a user as a VIP customer on public/users.php; the admin customer list
-- highlights them and the admin reports can show VIP or regular customers only. The flag is on the
-- user's own ellsms_meta row, with when (UTC) and by which admin it was set.
--
-- Additive, guarded and rerun-safe.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_meta' AND column_name = 'is_vip'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_meta
     ADD COLUMN is_vip TINYINT(1) NOT NULL DEFAULT 0,
     ADD COLUMN vip_marked_at DATETIME NULL,
     ADD COLUMN vip_marked_by BIGINT NULL,
     ADD KEY idx_meta_vip (is_vip)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
