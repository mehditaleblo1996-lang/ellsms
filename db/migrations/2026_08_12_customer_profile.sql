-- Customer / organization profile — personal profile, company legal profile, address, notification
-- preferences and private profile documents (docs/customer-profile.md).
--
-- Five new ELLSMS-owned tables. Nothing here touches a backend-owned table
-- (user_/domain/outbound_message/inbound_message), and nothing existing is dropped, renamed or
-- rewritten — `ellsms_user_kyc` keeps every column and every row it has today. Moving its data into
-- the new model is a SEPARATE, explicit, idempotent operator command (cron/profile-backfill.php,
-- `make profile-backfill`), the same migration-vs-backfill split Phase 3, 6, 11 and 13 established.
--
-- OWNERSHIP, which is the whole point of this schema (docs/customer-profile.md §Ownership):
--
--   personal identity  -> the USER          (ellsms_user_profiles, keyed by user_id)
--   company / legal    -> the ORGANIZATION  (ellsms_organization_profiles, keyed by organization_id)
--   address            -> the ORGANIZATION  (ellsms_organization_addresses)
--   credit alerts      -> the ORGANIZATION  (ellsms_organization_notification_preferences)
--   documents          -> exactly ONE of the two (ellsms_profile_documents)
--
-- Company data is deliberately NOT a property of a user: two members of one organization must see
-- the same company profile, and one user in two organizations must see two different ones. Keying
-- company data by user_id would make both of those impossible.
--
-- SOURCE OF TRUTH. The backend platform owns identity — username, firstname, lastname, email,
-- mobile, currentcredit — and remains authoritative for all of it. None of those is copied here.
-- These tables hold only what the backend does NOT own. See docs/customer-profile.md §Source of
-- truth for the field-by-field mapping.
--
-- NO GENERATED COLUMNS. "Unique among ACTIVE rows only" is expressed with an ordinary NULLable slot
-- column maintained by app/Profile.php, not a GENERATED column — see TD-070
-- (docs/td-070-restore-safety-closure.md): the mysqldump this project ships emits generated columns
-- as ordinary data and MySQL then refuses to reload the dump, which broke restore for any table
-- carrying rows. The database still enforces the uniqueness; only the computation moved.
SET NAMES utf8mb4;

-- Personal extended profile. One row per ELLSMS-managed user, holding the identity fields the
-- backend platform does not own. `father_name` and `personal_address` already exist in
-- ellsms_user_kyc; the backfill moves them here and this table becomes authoritative for both.
CREATE TABLE IF NOT EXISTS ellsms_user_profiles (
  user_id              BIGINT NOT NULL PRIMARY KEY,  -- = user_.id; no FK, same policy as every other ellsms_* -> user_ reference
  father_name          VARCHAR(120) NOT NULL DEFAULT '',
  national_code        VARCHAR(20) NOT NULL DEFAULT '',   -- normalized to ASCII digits on write; never validated against a government registry
  birth_certificate_no VARCHAR(30) NOT NULL DEFAULT '',
  birth_date           DATE NULL,                          -- Gregorian, like every other date column here; rendered as Jalali by the UI
  gender               ENUM('male','female','unspecified') NOT NULL DEFAULT 'unspecified', -- never inferred, only ever stated
  personal_address     TEXT NULL,                          -- the individual's own address; the COMPANY address lives in ellsms_organization_addresses
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Company / legal profile. `legal_name` is NOT a duplicate of ellsms_organizations.name: the
-- organization's name is its display name inside ELLSMS, while legal_name is the registered legal
-- entity name, which is frequently different and is what appears on an invoice. The UI shows the
-- organization name when legal_name is empty, so nothing has to be filled in twice.
CREATE TABLE IF NOT EXISTS ellsms_organization_profiles (
  organization_id            INT UNSIGNED NOT NULL PRIMARY KEY,
  legal_name                 VARCHAR(190) NOT NULL DEFAULT '',
  company_type               ENUM('legal_entity','individual_business','government','other','unspecified') NOT NULL DEFAULT 'unspecified',
  registration_number        VARCHAR(40) NOT NULL DEFAULT '',   -- شماره ثبت
  national_id                VARCHAR(20) NOT NULL DEFAULT '',   -- شناسه ملی شرکت (company national id, NOT a person's national code)
  economic_code              VARCHAR(30) NOT NULL DEFAULT '',   -- کد اقتصادی
  ceo_name                   VARCHAR(160) NOT NULL DEFAULT '',
  ceo_father_name            VARCHAR(120) NOT NULL DEFAULT '',
  ceo_national_code          VARCHAR(20) NOT NULL DEFAULT '',
  ceo_birth_date             DATE NULL,
  company_start_date         DATE NULL,
  company_expiry_date        DATE NULL,
  -- Optional link to an ELLSMS login account. Nullable on purpose: a CEO or legal representative is
  -- very often not a panel user at all, and forcing the relation would make the field unusable for
  -- exactly the organizations most likely to need it. The textual ceo_name above always applies.
  legal_representative_user_id BIGINT NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_legal_representative (legal_representative_user_id),
  CONSTRAINT fk_org_profile_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ONE primary address per organization, deliberately: every screen in this product represents a
-- single company address, and a multi-address model would add a "which one is this?" question to
-- every read for no current benefit. If billing/shipping addresses are ever needed, this table gains
-- a `kind` column and a wider key — a change this shape does not obstruct.
CREATE TABLE IF NOT EXISTS ellsms_organization_addresses (
  organization_id INT UNSIGNED NOT NULL PRIMARY KEY,
  country         VARCHAR(60) NOT NULL DEFAULT 'ایران',
  province        VARCHAR(60) NOT NULL DEFAULT '',
  city            VARCHAR(60) NOT NULL DEFAULT '',
  district        VARCHAR(60) NOT NULL DEFAULT '',
  street          VARCHAR(190) NOT NULL DEFAULT '',
  alley           VARCHAR(120) NOT NULL DEFAULT '',
  building_no     VARCHAR(20) NOT NULL DEFAULT '',
  unit_no         VARCHAR(20) NOT NULL DEFAULT '',
  postal_code     VARCHAR(20) NOT NULL DEFAULT '',   -- normalized to 10 ASCII digits on write, or empty
  address_text    TEXT NULL,                          -- free-form fallback: legacy data and addresses the structured fields cannot express
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_address_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Low-credit alert configuration. CONFIGURATION ONLY — this table never holds a balance, and nothing
-- here participates in wallet arithmetic. The threshold is expressed in the same unit the wallet is
-- denominated in (whole CREDITS), so it can be compared to wallet_balance()['available'] directly.
--
-- This phase stores and surfaces the preference; it deliberately does not implement the notification
-- SENDER (that needs a scheduled job with its own idempotency/dedup design, and inventing one here
-- would be a second feature). docs/customer-profile.md states that limitation plainly.
CREATE TABLE IF NOT EXISTS ellsms_organization_notification_preferences (
  organization_id          INT UNSIGNED NOT NULL PRIMARY KEY,
  low_credit_alert_enabled TINYINT(1) NOT NULL DEFAULT 0,
  low_credit_threshold     INT UNSIGNED NOT NULL DEFAULT 0,   -- in CREDITS, the wallet's own unit
  email_alert_enabled      TINYINT(1) NOT NULL DEFAULT 0,
  sms_alert_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  alert_email              VARCHAR(190) NOT NULL DEFAULT '',  -- empty = use the owner's backend-owned email
  alert_mobile             VARCHAR(20) NOT NULL DEFAULT '',   -- empty = use the owner's backend-owned mobile
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_org_notify_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Private profile documents (identity cards, incorporation notices, ...).
--
-- EXACTLY ONE OWNER, enforced by the database rather than by convention: a row belongs to a user OR
-- to an organization, never both and never neither. An ambiguously-owned document is the direct road
-- to a cross-tenant read, so the CHECK constraint makes the ambiguous state unrepresentable.
--
-- Files themselves live OUTSIDE the web root under storage/profile-documents/, named from
-- `storage_key` (opaque random bytes, never anything user-supplied), and are reachable only through
-- public/profile-document.php, which authorizes every read. `original_filename` is retained for
-- display only and never touches the filesystem.
--
-- Replacement ARCHIVES rather than overwrites (STEP 29/30): the previous version keeps its row and
-- its file, so history survives. `active_slot` is what makes "one ACTIVE document per owner per
-- type" a database guarantee; it is maintained by app/Profile.php (see the no-generated-columns note
-- at the top of this file).
CREATE TABLE IF NOT EXISTS ellsms_profile_documents (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id    INT UNSIGNED NULL,
  user_id            BIGINT NULL,                      -- = user_.id; no FK, same policy as elsewhere
  document_type      VARCHAR(40) NOT NULL,             -- validated against ProfileCatalog, never free-form at write time
  storage_key        VARCHAR(120) NOT NULL,            -- opaque filename under storage/profile-documents/
  original_filename  VARCHAR(255) NOT NULL DEFAULT '', -- display only; NEVER used to build a path
  mime_type          VARCHAR(80) NOT NULL DEFAULT '',
  size_bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256             CHAR(64) NOT NULL DEFAULT '',     -- integrity check compares this against the file on disk
  status             ENUM('active','archived') NOT NULL DEFAULT 'active',
  uploaded_by_user_id BIGINT NULL,                     -- who actually uploaded it (an admin, or the user themselves)
  legacy_source      VARCHAR(190) NULL,                -- set by the backfill to the ellsms_user_kyc column it came from; makes the backfill idempotent
  archived_at        TIMESTAMP NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_slot        VARCHAR(120) NULL,                -- 'u:<id>:<type>' / 'o:<id>:<type>' while active, NULL once archived
  UNIQUE KEY uniq_storage_key (storage_key),
  UNIQUE KEY uniq_active_document (active_slot),
  KEY idx_org_documents (organization_id, status),
  KEY idx_user_documents (user_id, status),
  KEY idx_legacy_source (legacy_source),
  CONSTRAINT chk_profile_document_single_owner CHECK ((organization_id IS NULL) <> (user_id IS NULL)),
  CONSTRAINT fk_profile_document_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
