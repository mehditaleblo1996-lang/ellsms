-- ELLSMS-owned bulk worker hot path.
-- Slow log on 2026-08-25 showed the post-claim fetch examining ~100k rows:
--   WHERE i.claimed_by = ? ORDER BY i.id
-- Keep this migration additive and rerun-safe.

SET NAMES utf8mb4;

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_bulk_items'
    AND index_name = 'idx_bulk_items_claimed_by_id'
);

SET @sql = IF(
  @idx_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD INDEX idx_bulk_items_claimed_by_id (claimed_by, id)',
  'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
