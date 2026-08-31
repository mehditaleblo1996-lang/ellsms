-- Phase 3: admin review/notification metadata for public registrations.
--
-- Guarded (information_schema check + PREPARE/EXECUTE) — see 2026_08_26_registration_otp.sql for
-- why: the original unguarded ALTER/CREATE INDEX broke under a repeated schema application against
-- the same database (subprocess-spawning integration tests each rerun the full migration set).
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'admin_notified_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests
     ADD COLUMN admin_notified_at DATETIME NULL AFTER mobile_verified_at,
     ADD COLUMN decision_note VARCHAR(500) NOT NULL DEFAULT '''' AFTER rejection_reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND index_name = 'idx_registration_admin_review'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_registration_admin_review ON ellsms_registration_requests (state, admin_notified_at, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
