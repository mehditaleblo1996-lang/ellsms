-- ELLSMS public registration/onboarding requests.
-- Applicants live here until mobile verification and admin approval complete.
-- No backend-platform account is created by the public form.
CREATE TABLE IF NOT EXISTS ellsms_registration_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL DEFAULT '',
  username VARCHAR(120) NOT NULL,
  password_verifier VARCHAR(255) NOT NULL,
  account_type ENUM('individual','legal') NOT NULL DEFAULT 'individual',
  company_name VARCHAR(190) NOT NULL DEFAULT '',
  state ENUM(
    'pending_mobile_verification',
    'pending_admin_approval',
    'approved',
    'rejected',
    'account_created',
    'expired',
    'cancelled',
    'blocked'
  ) NOT NULL DEFAULT 'pending_mobile_verification',
  mobile_verified_at DATETIME NULL,
  approved_at DATETIME NULL,
  approved_by BIGINT NULL,
  rejected_at DATETIME NULL,
  rejected_by BIGINT NULL,
  rejection_reason VARCHAR(500) NOT NULL DEFAULT '',
  created_user_id BIGINT NULL,
  signup_ip VARCHAR(45) NOT NULL DEFAULT '',
  signup_user_agent VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_registration_state_created (state, created_at, id),
  KEY idx_registration_mobile_state (mobile, state),
  KEY idx_registration_username_state (username, state),
  KEY idx_registration_email_state (email, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
