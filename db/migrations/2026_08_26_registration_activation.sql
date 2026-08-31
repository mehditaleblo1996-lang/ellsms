-- Phase 4 registration activation: fields required to create a real backend account.
-- Password handling: backend_password_hash stores only the backend platform's legacy 32-byte
-- verifier, never plaintext. It is nulled immediately after account activation. The modern
-- password_verifier remains temporary onboarding evidence and is cleared on activation as well.
--
-- Guarded (information_schema check + PREPARE/EXECUTE) — see 2026_08_26_registration_otp.sql for
-- why: the original unguarded ALTER/CREATE INDEX broke under a repeated schema application against
-- the same database (subprocess-spawning integration tests each rerun the full migration set).
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'national_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests
     ADD COLUMN national_id VARCHAR(20) NOT NULL DEFAULT '''' AFTER company_name,
     ADD COLUMN gender ENUM(''MALE'',''FEMALE'') NOT NULL DEFAULT ''MALE'' AFTER national_id,
     ADD COLUMN backend_password_hash VARBINARY(32) NULL AFTER password_verifier,
     ADD COLUMN domain_id BIGINT NULL AFTER gender,
     ADD COLUMN account_created_at DATETIME NULL AFTER created_user_id,
     ADD COLUMN activation_error VARCHAR(500) NOT NULL DEFAULT '''' AFTER account_created_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND index_name = 'idx_registration_created_user'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_registration_created_user ON ellsms_registration_requests (created_user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
