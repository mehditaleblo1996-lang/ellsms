-- Dashboard: "sent today", the 7-day chart and "latest messages" read bulk items by send time.
-- claimed_at is set when a worker takes the row for sending (seconds before the provider call) and
-- is the only per-row send time a bulk item has. Covering (claimed_at, job_id, status,
-- delivery_status) so those counts are answered from the index alone, without reading the rows.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND index_name = 'idx_bulk_items_claimed'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD KEY idx_bulk_items_claimed (claimed_at, job_id, status, delivery_status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
