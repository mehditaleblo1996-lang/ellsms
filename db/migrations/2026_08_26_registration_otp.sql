-- Phase 2: public registration mobile OTP verification.
ALTER TABLE ellsms_registration_requests
  ADD COLUMN otp_hash CHAR(64) NULL AFTER password_verifier,
  ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_hash,
  ADD COLUMN otp_sent_at DATETIME NULL AFTER otp_expires_at,
  ADD COLUMN otp_send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_sent_at,
  ADD COLUMN otp_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_send_count;

CREATE INDEX idx_registration_otp_due
  ON ellsms_registration_requests (state, otp_expires_at, id);
