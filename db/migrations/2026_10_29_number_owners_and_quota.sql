-- Numbers, part 2 (ideas from the Vesal portal's customer_number_list / number_owner pages):
--   status_reason / status_changed_at   «علت و تاریخ مسدودی / غیرفعال‌سازی»: why and when the line last
--                                        left 'active'. Shown on the list and the edit page only.
--   bypass_optouts                      «عبور از لیست سیاه داخلی» (Vesal internalBlackList): sends from
--                                        this line skip the per-line opt-out list ("11",
--                                        ellsms_line_optouts) — line_optout_filter() returns nothing for it.
--   ellsms_user_line_quota              a user's bandwidth ceiling: the bandwidth of the lines assigned to
--                                        them (except reserved / transferred / deleted ones) may not exceed
--                                        it. No row = no ceiling, as before. Replaces the per-number
--                                        bandwidth_max column added the day before, which only ever held its
--                                        default and is dropped here.
--   ellsms_number_owners                the line's registered owner (real or legal person) for the
--                                        regulator's ownership register. Recorded and shown only; nothing is
--                                        sent anywhere.
-- Every statement is guarded, so re-running this file changes nothing.
SET NAMES utf8mb4;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'status_reason'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN status_reason VARCHAR(255) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'status_changed_at'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN status_changed_at TIMESTAMP NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'bypass_optouts'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE ellsms_numbers ADD COLUMN bypass_optouts TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_numbers' AND column_name = 'bandwidth_max'
);
SET @sql = IF(@col_exists = 1, 'ALTER TABLE ellsms_numbers DROP COLUMN bandwidth_max', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_user_line_quota (
  user_id       BIGINT NOT NULL PRIMARY KEY,
  bandwidth_max INT UNSIGNED NOT NULL,
  updated_by    BIGINT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_number_owners (
  number_id               INT UNSIGNED NOT NULL PRIMARY KEY,
  owner_type              ENUM('real','legal') NOT NULL DEFAULT 'real',
  first_name              VARCHAR(80)  NOT NULL DEFAULT '',
  last_name               VARCHAR(80)  NOT NULL DEFAULT '',
  father_name             VARCHAR(80)  NOT NULL DEFAULT '',
  national_id             VARCHAR(10)  NOT NULL DEFAULT '',
  birth_date              DATE NULL,
  gender                  ENUM('','male','female') NOT NULL DEFAULT '',
  company_name            VARCHAR(160) NOT NULL DEFAULT '',
  company_national_id     VARCHAR(11)  NOT NULL DEFAULT '',
  company_registration_no VARCHAR(30)  NOT NULL DEFAULT '',
  mobile                  VARCHAR(20)  NOT NULL DEFAULT '',
  tel                     VARCHAR(20)  NOT NULL DEFAULT '',
  email                   VARCHAR(120) NOT NULL DEFAULT '',
  postal_code             VARCHAR(10)  NOT NULL DEFAULT '',
  province                VARCHAR(60)  NOT NULL DEFAULT '',
  city                    VARCHAR(60)  NOT NULL DEFAULT '',
  address                 VARCHAR(500) NOT NULL DEFAULT '',
  start_date              DATE NULL,
  expire_date             DATE NULL,
  updated_by              BIGINT NULL,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_owner_national (national_id),
  KEY idx_owner_company (company_national_id),
  CONSTRAINT fk_number_owner_number FOREIGN KEY (number_id) REFERENCES ellsms_numbers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
