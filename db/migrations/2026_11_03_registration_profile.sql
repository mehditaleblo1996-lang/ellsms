-- Self-registration asks for the same «مشخصات مشتری» as the admin's «ساخت حساب تازه» form
-- (app/views/customer_identity_fields.php): person, account type, company and address. The whole
-- form is kept on the request as JSON (the shape profile_identity_from_request() returns) until the
-- account is activated, when registration_activate_account() writes it to the user / organization
-- profile with profile_identity_save(). Older requests have NULL and activate as before.
--
-- Guarded like 2026_08_26_registration_activation.sql so a repeated schema application is harmless.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'profile_json'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests ADD COLUMN profile_json MEDIUMTEXT NULL AFTER company_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
