SET NAMES utf8mb4;

-- import_cancel_job() marks active chunks as cancelled. Older schemas only
-- allowed pending/processing/completed/failed, which made cancellation fail
-- with MySQL warning 1265 (data truncated for column status).
SET @needs_cancelled = (
  SELECT COUNT(*) = 0
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'ellsms_import_chunks'
    AND column_name = 'status'
    AND column_type LIKE "%\'cancelled\'%"
);
SET @sql = IF(@needs_cancelled,
  "ALTER TABLE ellsms_import_chunks MODIFY COLUMN status ENUM('pending','processing','completed','failed','cancelled') NOT NULL DEFAULT 'pending'",
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
