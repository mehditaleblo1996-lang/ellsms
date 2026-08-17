-- Profile + KYC UI completion — a handful of fields the reference profile screens display that had
-- no column yet (docs/profile-kyc.md). Purely additive, same guarded-ALTER pattern as every migration
-- since 2026_08_13; nothing existing is dropped, renamed, rewritten, or made a GENERATED column
-- (TD-070 — see db/migrations/2026_08_12_customer_profile.sql's own note).
--
-- Every new column defaults to '' / NULL, so every existing row reads exactly as it did before this
-- migration — no backfill computation is needed or attempted.
SET NAMES utf8mb4;

-- Individual identity: the national card's own printed expiry date ("تاریخ انقضا" on the top account-
-- info summary card). Distinct from birth_date, which already existed.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_user_profiles' AND column_name = 'national_id_expiry_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_user_profiles ADD COLUMN national_id_expiry_at DATE NULL AFTER birth_date',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Legal/company profile: company landline, fax, an internal customer code, the representative's
-- birth-certificate number, and the representative's last name — the five company-screen fields the
-- reference layout expects that had no column anywhere yet. Company landline/fax are company-level
-- contact details (distinct from the representative's own ceo_mobile/ceo_email, which already
-- existed); customer_code is a free-form internal reference, not a second identifier scheme.
-- ceo_last_name is additive next to the pre-existing ceo_name (kept meaning exactly what it already
-- meant — the representative's given/full name) rather than renaming or repurposing it, so every
-- existing reader of ceo_name (kyc-review.php, docs, tests) keeps working unchanged.
-- "حداکثر تعداد زیرمجموعه" (a sub-organization hierarchy) is deliberately NOT added here — no such
-- hierarchy exists anywhere in ELLSMS today, and inventing one would be a different, much larger
-- feature, not a display field.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_organization_profiles' AND column_name = 'landline_phone'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_organization_profiles
     ADD COLUMN landline_phone VARCHAR(20) NOT NULL DEFAULT '' AFTER ceo_email,
     ADD COLUMN fax_number VARCHAR(20) NOT NULL DEFAULT '' AFTER landline_phone,
     ADD COLUMN customer_code VARCHAR(40) NOT NULL DEFAULT '' AFTER fax_number,
     ADD COLUMN ceo_birth_certificate_no VARCHAR(30) NOT NULL DEFAULT '' AFTER ceo_national_code,
     ADD COLUMN ceo_last_name VARCHAR(120) NOT NULL DEFAULT '' AFTER ceo_name",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
