-- Signing an account's other sessions out when its password changes (app/PasswordSecurity.php,
-- docs/password-security.md).
--
-- ellsms_meta.password_version counts the password changes of an account. A session remembers the
-- number it signed in under; once the password changes (by its owner, an admin, a reseller or a
-- parent account) every session still holding the older number is signed out on its next request.
--
-- DEFAULT 0, and a session that predates this column counts as 0 too: applying this migration signs
-- nobody out. Until it is applied the code reads the version as 0 and behaves as before.
--
-- Guarded ALTER (information_schema check first), consistent with every prior migration here.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_meta' AND column_name = 'password_version'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_meta ADD COLUMN password_version INT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The verifier's algorithm name grew ("argon2id-sha256"); the column was VARCHAR(20), which fits it,
-- so nothing to alter in ellsms_password_verifiers.
