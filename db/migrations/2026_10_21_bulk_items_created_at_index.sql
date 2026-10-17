-- Bulk send report (public/reports-bulk.php): its summary cards, row count and message list all
-- filter bulk items by created_at, which had no index, so every open of the page scanned the whole
-- table and long histories timed out. Covering (created_at, job_id, status, delivery_status), the
-- same shape as idx_bulk_items_claimed, so the summary counts are answered from the index alone.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_items' AND index_name = 'idx_bulk_items_created'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_bulk_items ADD KEY idx_bulk_items_created (created_at, job_id, status, delivery_status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
