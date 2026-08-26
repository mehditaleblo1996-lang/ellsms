-- Phase 3: admin review/notification metadata for public registrations.
ALTER TABLE ellsms_registration_requests
  ADD COLUMN admin_notified_at DATETIME NULL AFTER mobile_verified_at,
  ADD COLUMN decision_note VARCHAR(500) NOT NULL DEFAULT '' AFTER rejection_reason;

CREATE INDEX idx_registration_admin_review
  ON ellsms_registration_requests (state, admin_notified_at, id);
