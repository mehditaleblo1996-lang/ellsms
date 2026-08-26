-- Phase 4 registration activation: fields required to create a real backend account.
-- Password handling: backend_password_hash stores only the backend platform's legacy 32-byte
-- verifier, never plaintext. It is nulled immediately after account activation. The modern
-- password_verifier remains temporary onboarding evidence and is cleared on activation as well.
ALTER TABLE ellsms_registration_requests
  ADD COLUMN national_id VARCHAR(20) NOT NULL DEFAULT '' AFTER company_name,
  ADD COLUMN gender ENUM('MALE','FEMALE') NOT NULL DEFAULT 'MALE' AFTER national_id,
  ADD COLUMN backend_password_hash VARBINARY(32) NULL AFTER password_verifier,
  ADD COLUMN domain_id BIGINT NULL AFTER gender,
  ADD COLUMN account_created_at DATETIME NULL AFTER created_user_id,
  ADD COLUMN activation_error VARCHAR(500) NOT NULL DEFAULT '' AFTER account_created_at;

CREATE INDEX idx_registration_created_user ON ellsms_registration_requests (created_user_id);
