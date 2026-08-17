-- KYC review workflow — durable account type, KYC request state machine, per-document review,
-- allowed-IP management (docs/profile-kyc.md).
--
-- This continues docs/customer-profile.md's §11 "Limitations — No KYC approval workflow" note:
-- that phase deliberately stored/replaced/archived documents without reviewing or approving them.
-- This migration adds exactly the missing piece — nothing here touches a backend-owned table
-- (user_/domain/outbound_message/inbound_message), and nothing existing is dropped, renamed or
-- rewritten. `ellsms_profile_documents` and `ellsms_organization_profiles` gain ADDITIVE columns
-- only; every existing row keeps every value it already has.
--
-- ACCOUNT TYPE lives on `ellsms_organization_profiles` (organization_id primary key) — the account/
-- tenant boundary — never scattered onto `ellsms_organizations` or `ellsms_meta` as an unrelated
-- flag. This matches the existing ownership rule in docs/customer-profile.md: company/legal-shaped
-- data belongs to the organization, not to an individual user row.
--
-- BACKFILL SAFETY: every existing organization gets `account_type = 'individual'` UNLESS it already
-- has legal/company data on file (a non-'unspecified' company_type or a non-empty legal_name), in
-- which case it backfills to 'legal'. No organization is forced into an incomplete KYC flow by this
-- migration — `ellsms_kyc_requests` rows are created lazily, on first read (app/Kyc.php), so an
-- organization that never touches KYC never gets a row at all and is completely unaffected.
--
-- NO GENERATED COLUMNS (TD-070) — see db/migrations/2026_08_12_customer_profile.sql's own note; the
-- same restore-safety reasoning applies to every table below.
SET NAMES utf8mb4;

-- 0) Widen company_type to the controlled Iranian legal-entity set (§5 of the KYC phase brief),
-- ADDITIVELY: every value this ENUM already accepted stays valid, so no existing row is rewritten or
-- coerced. app/Profile.php's PROFILE_COMPANY_TYPES is the single source of truth for the full list.
SET @needs_type = (
  SELECT COUNT(*) = 0 FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_profiles'
    AND column_name = 'company_type' AND column_type LIKE '%private_joint_stock%'
);
SET @sql = IF(@needs_type = 1,
  "ALTER TABLE ellsms_organization_profiles
     MODIFY COLUMN company_type ENUM(
       'legal_entity','individual_business','government',
       'private_joint_stock','public_joint_stock','limited_liability','cooperative','institution','governmental',
       'other','unspecified'
     ) NOT NULL DEFAULT 'unspecified'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1) Durable account type on the organization profile.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_profiles'
    AND column_name = 'account_type'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_organization_profiles
     ADD COLUMN account_type ENUM('individual','legal') NOT NULL DEFAULT 'individual' AFTER organization_id",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_profiles' AND index_name = 'idx_account_type'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_organization_profiles ADD INDEX idx_account_type (account_type)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Representative contact fields, additive to the existing `ceo_*` columns (docs/customer-profile.md's
-- ceo_name/ceo_father_name/ceo_national_code/ceo_birth_date already model the legal representative —
-- these three close the gap against §4's requested field set without inventing a second, parallel
-- `representative_*` naming scheme for the same person).
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_profiles' AND column_name = 'ceo_birth_city'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_organization_profiles
     ADD COLUMN ceo_birth_city VARCHAR(60) NOT NULL DEFAULT '' AFTER ceo_birth_date,
     ADD COLUMN ceo_mobile VARCHAR(20) NOT NULL DEFAULT '' AFTER ceo_birth_city,
     ADD COLUMN ceo_email VARCHAR(190) NOT NULL DEFAULT '' AFTER ceo_mobile",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Safe backfill: only touches rows that already exist (nothing forces a *new* row into existence),
-- and only sets account_type = 'legal' where the existing data already looks like a company. Every
-- other organization keeps the safe 'individual' default a bare INSERT already gave it above.
UPDATE ellsms_organization_profiles
   SET account_type = 'legal'
 WHERE account_type = 'individual'
   AND (company_type <> 'unspecified' OR legal_name <> '');

-- 2) Per-document review state — additive columns on the existing documents table. Every existing
-- document row (all of them uploaded before a review workflow existed) backfills to 'pending', which
-- is correct: nothing has been reviewed yet, and 'pending' is not "rejected."
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_profile_documents' AND column_name = 'review_status'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_profile_documents
     ADD COLUMN review_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER status,
     ADD COLUMN reviewed_at TIMESTAMP NULL AFTER review_status,
     ADD COLUMN reviewed_by_user_id BIGINT NULL AFTER reviewed_at,
     ADD COLUMN review_note VARCHAR(500) NOT NULL DEFAULT '' AFTER reviewed_by_user_id",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_profile_documents' AND index_name = 'idx_review_status'
);
SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE ellsms_profile_documents ADD INDEX idx_review_status (review_status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) The KYC request — ONE row per organization (the account/tenant boundary), the same "one row,
-- lazily created" shape as ellsms_organization_notification_preferences. A row is created only when
-- an organization actually touches the KYC flow (app/Kyc.php's kyc_request_get()); an organization
-- that never does has NO row here and is not affected by this migration in any way — this is what
-- makes the migration safe for existing production customers (STEP 22's "do not unexpectedly block
-- current customers").
CREATE TABLE IF NOT EXISTS ellsms_kyc_requests (
  organization_id     INT UNSIGNED NOT NULL PRIMARY KEY,
  status               ENUM('draft','submitted','under_review','needs_correction','approved','rejected')
                          NOT NULL DEFAULT 'draft',
  submitted_at         TIMESTAMP NULL,
  review_started_at    TIMESTAMP NULL,
  reviewed_at          TIMESTAMP NULL,
  reviewed_by_user_id  BIGINT NULL,          -- = user_.id; no FK, same policy as every other ellsms_* -> user_ reference
  review_note          VARCHAR(1000) NOT NULL DEFAULT '',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kyc_status (status),
  KEY idx_kyc_submitted (submitted_at),
  CONSTRAINT fk_kyc_request_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) Allowed-IP management (§20 of the phase brief). MANAGEMENT ONLY — see docs/profile-kyc.md for
-- exactly which enforcement hook (if any) reads this table today. Multiple entries per organization,
-- each independently enable/disable-able so a mistake never requires deleting history.
CREATE TABLE IF NOT EXISTS ellsms_organization_allowed_ips (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NOT NULL,
  ip_or_cidr         VARCHAR(64) NOT NULL,   -- validated IPv4/IPv6, optionally with a /prefix, before insert
  label              VARCHAR(120) NOT NULL DEFAULT '',
  status             ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_org_ip (organization_id, ip_or_cidr),
  KEY idx_org_status (organization_id, status),
  CONSTRAINT fk_allowed_ip_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
