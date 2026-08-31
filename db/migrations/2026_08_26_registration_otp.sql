-- Phase 2: public registration mobile OTP verification.
--
-- Guarded (information_schema check + PREPARE/EXECUTE), matching every other migration in this
-- codebase (see db/ellsms_extra.sql) — the original unguarded ALTER/CREATE INDEX broke the moment
-- schema was applied twice against the same database, which subprocess-spawning integration tests
-- (WebhookDeliveryTest, WalletConcurrencyTest, etc. — each a fresh PHP process that reruns
-- IntegrationTestCase::ensureSchemaLoaded()) do routinely against the one shared test database.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'otp_hash'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests
     ADD COLUMN otp_hash CHAR(64) NULL AFTER password_verifier,
     ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_hash,
     ADD COLUMN otp_sent_at DATETIME NULL AFTER otp_expires_at,
     ADD COLUMN otp_send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_sent_at,
     ADD COLUMN otp_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_send_count',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND index_name = 'idx_registration_otp_due'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_registration_otp_due ON ellsms_registration_requests (state, otp_expires_at, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
