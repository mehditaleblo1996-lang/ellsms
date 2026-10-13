-- Contact details (public/contacts.php, app/Contacts.php).
--
-- A contact was only a name, a mobile and a group. The single-contact form and the Excel import
-- now also carry the columns of the usual contact-list export: last name, landline, gender, birth
-- date, occasion date, email and address. Every column is optional, so existing rows and the
-- public API (which never sends them) keep working unchanged.
--
--   gender         NULL = not given, 0 = آقا, 1 = خانم (the 0/1 coding of the Excel template)
--   birth_date,
--   occasion_date  Gregorian DATE; the panel shows and accepts them as Jalali dates
--
-- Guarded by an information_schema check, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @has_details = (SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'ellsms_contacts' AND column_name = 'last_name');
SET @sql = IF(@has_details = 0,
  'ALTER TABLE ellsms_contacts
     ADD COLUMN last_name     VARCHAR(120) NOT NULL DEFAULT '''' AFTER name,
     ADD COLUMN phone         VARCHAR(20)  NOT NULL DEFAULT '''' AFTER mobile,
     ADD COLUMN gender        TINYINT UNSIGNED NULL AFTER phone,
     ADD COLUMN birth_date    DATE NULL AFTER gender,
     ADD COLUMN occasion_date DATE NULL AFTER birth_date,
     ADD COLUMN email         VARCHAR(190) NOT NULL DEFAULT '''' AFTER occasion_date,
     ADD COLUMN address       VARCHAR(500) NOT NULL DEFAULT '''' AFTER email',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
