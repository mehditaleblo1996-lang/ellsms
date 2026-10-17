-- Aimed bulk (app/AimedBulk.php, docs/aimed-bulk.md): send to the subscriber numbers ELLSMS holds
-- itself, picked by postal code, province / city, number prefix and SIM type. The numbers come from
-- Vesal's TBL_MOBILE (with TBL_PROVINCE and TBL_CITY for the names), loaded by
-- cron/aimed-mobiles-import.php. Unlike regional bulk (#43, through Vesal's API), these sends go out
-- through ELLSMS's own bulk queue and wallet.
--
--   ellsms_aimed_mobiles        one row per subscriber number (mobile normalized to 98XXXXXXXXXX)
--   ellsms_aimed_provinces      TBL_PROVINCE: code -> name (source_id = TBL_PROVINCE.ID, which is
--                               what TBL_CITY.PROVINCE_ID points at)
--   ellsms_aimed_cities         TBL_CITY: code -> name, province
--   ellsms_aimed_bulk_requests  one row per customer request; the worker stages its numbers into a
--                               'staged' bulk job, the customer confirms the price, the job sends
--   ellsms_aimed_bulk_targets   numbers picked for a request, only while it is being staged
--   ellsms_bulk_jobs.recipients_masked
--                               1 = customers see this job's numbers masked (0912***4567), never
--                               the number itself
--
-- Guarded by information_schema checks, so re-running this file changes nothing.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_aimed_provinces (
  code        INT UNSIGNED NOT NULL PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  source_id   BIGINT NULL,
  UNIQUE KEY uniq_source (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_aimed_cities (
  code           INT UNSIGNED NOT NULL PRIMARY KEY,
  province_code  INT UNSIGNED NULL,
  name           VARCHAR(150) NOT NULL,
  KEY idx_province (province_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_aimed_mobiles (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mobile         VARCHAR(15) NOT NULL,
  postal_code    VARCHAR(10) NULL,
  province_code  INT UNSIGNED NULL,
  city_code      INT UNSIGNED NULL,
  sim_type       ENUM('prepaid','postpaid') NULL,
  gender         TINYINT UNSIGNED NULL,
  blocklisted    TINYINT(1) NOT NULL DEFAULT 0,
  imported_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_mobile (mobile),
  KEY idx_postal (blocklisted, postal_code),
  KEY idx_province (blocklisted, province_code),
  KEY idx_city (blocklisted, city_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_aimed_bulk_requests (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NULL,
  user_id            BIGINT NOT NULL,
  criteria_json      JSON NOT NULL,
  originator         VARCHAR(20) NOT NULL,
  content            TEXT NOT NULL,
  title              VARCHAR(160) NOT NULL DEFAULT '',
  max_count          INT UNSIGNED NULL,
  surcharge_percent  DECIMAL(6,2) NOT NULL DEFAULT 0,
  pricing_at         DATETIME NOT NULL,
  status             ENUM('staging','priced','confirmed','failed','cancelled') NOT NULL DEFAULT 'staging',
  bulk_job_id        INT UNSIGNED NULL,
  targets_ready      TINYINT(1) NOT NULL DEFAULT 0,
  cursor_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  matched_rows       INT UNSIGNED NOT NULL DEFAULT 0,
  staged_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  blacklisted_rows   INT UNSIGNED NOT NULL DEFAULT 0,
  unpriced_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  total_cost         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error              VARCHAR(500) NULL,
  claimed_by         VARCHAR(80) NULL,
  lease_expires_at   DATETIME NULL,
  priced_at          TIMESTAMP NULL,
  confirmed_at       TIMESTAMP NULL,
  finished_at        TIMESTAMP NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_org_created (organization_id, created_at),
  KEY idx_user_created (user_id, created_at),
  KEY idx_status_lease (status, lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_aimed_bulk_targets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id  BIGINT UNSIGNED NOT NULL,
  mobile      VARCHAR(15) NOT NULL,
  KEY idx_request (request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_mask = (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND column_name = 'recipients_masked');
SET @sql = IF(@has_mask = 0,
  'ALTER TABLE ellsms_bulk_jobs ADD COLUMN recipients_masked TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mass promotional messaging, like regional bulk: an approved KYC is required by default. Platform
-- admins can change it on the KYC gates page (an existing value is kept).
INSERT INTO ellsms_settings (skey, svalue) VALUES ('kyc_gate.aimed_bulk', '1')
  ON DUPLICATE KEY UPDATE skey = skey;
