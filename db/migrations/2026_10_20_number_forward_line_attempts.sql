-- Per-line forwarding (2026_10_18_number_forwarding.sql): how many times a failed forward of THIS
-- line is tried. 0 (the default) = the service-wide setting (number_forward_max_attempts on
-- /number-forwards.php); 1..20 overrides it for this line only. See docs/number-forwarding.md.
-- Guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_forwards' AND column_name = 'max_attempts'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_number_forwards ADD COLUMN max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tls_verify',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
