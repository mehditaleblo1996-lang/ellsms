-- Per-line forwarding (2026_10_18_number_forwarding.sql): whether the customer's https certificate
-- is verified. 0 (the default) = like Vesal, which trusted every certificate: a self-signed, expired
-- or wrong-host certificate does not stop the forward. 1 = strict verification, set per line on
-- /number-forwards.php. See docs/number-forwarding.md.
-- Guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_number_forwards' AND column_name = 'tls_verify'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_number_forwards ADD COLUMN tls_verify TINYINT(1) NOT NULL DEFAULT 0 AFTER post_format',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
