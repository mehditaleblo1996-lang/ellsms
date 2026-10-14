-- Registration: proof that the applicant accepted the terms and privacy policy.
--
-- register.php now requires ticking «قوانین و حریم خصوصی را خوانده‌ام و می‌پذیرم». The request row
-- records which version of /terms + /privacy was accepted (LEGAL_TERMS_VERSION in app/Legal.php)
-- and when. Rows created before this change keep terms_version = '' and terms_accepted_at = NULL.
--
-- Guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'terms_version'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests
     ADD COLUMN terms_version     VARCHAR(20) NOT NULL DEFAULT '''' AFTER signup_user_agent,
     ADD COLUMN terms_accepted_at DATETIME NULL AFTER terms_version',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
