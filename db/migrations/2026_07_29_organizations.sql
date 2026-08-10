-- Phase 6 — Organization/Tenant foundation (docs/multi-tenancy-architecture.md).
--
-- Pure schema: two new ELLSMS-owned tables (ellsms_organizations,
-- ellsms_organization_memberships) plus a nullable organization_id column added to every
-- tenant-owned table. NO data is mutated here — the actual "create a default organization for
-- every existing user, backfill organization_id everywhere" work is a separate, explicit,
-- operator-run step (cron/tenant-backfill.php, `make tenant-backfill`), never bundled into a
-- schema migration, per this project's standing convention (Phase 3's wallet-backfill,
-- Phase 5's own migration-vs-backfill split) and this phase's own explicit instruction not to
-- auto-mutate production on deploy.
--
-- organization_id is added NULLABLE everywhere, not NOT NULL — a freshly-migrated table has every
-- existing row's organization_id unset until tenant-backfill.php runs; making it NOT NULL here
-- would either fail outright on any table with existing rows or require a default value that
-- would be a silent, wrong ownership guess. Application code must treat NULL organization_id as
-- "not yet migrated / no tenant" and fail closed for tenant-scoped access, exactly the same
-- fail-closed convention app/authorization.php already established in Phase 2.
--
-- Every FK here is ELLSMS-owned-to-ELLSMS-owned (organization_id -> ellsms_organizations.id),
-- matching Phase 5's constraint discipline exactly. Nothing here adds a hard FK to any
-- backend-owned table (user_/domain/outbound_message/inbound_message) — created_by_user_id /
-- user_id columns remain plain integers, same as every other ELLSMS-owned table's reference to
-- user_.id (see docs/database-audit.md's "Do NOT blindly add foreign keys to legacy/backend
-- tables" section, unchanged policy).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_organizations (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(160) NOT NULL,
  slug               VARCHAR(160) NOT NULL,
  status             ENUM('active','suspended','disabled') NOT NULL DEFAULT 'active',
  created_by_user_id BIGINT NOT NULL,  -- = user_.id; no FK, same rationale as every other ellsms_* -> user_ reference
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_slug (slug),
  KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coarse role only (owner/admin/member) -- full permission-matrix RBAC is explicitly Phase 7's
-- job, not this phase's. `status` lets a membership be revoked (fail-closed everywhere tenant
-- context is resolved) without deleting the historical row -- matches every other
-- revoke-don't-delete pattern already in this schema (ellsms_meta.panel_access).
CREATE TABLE IF NOT EXISTS ellsms_organization_memberships (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id INT UNSIGNED NOT NULL,
  user_id         BIGINT NOT NULL, -- = user_.id; no FK, same rationale as above
  role            ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  status          ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_org_user (organization_id, user_id),
  KEY (user_id, status),
  CONSTRAINT fk_membership_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adds organization_id (nullable, indexed, FK'd to ellsms_organizations, ON DELETE RESTRICT --
-- organizations are never hard-deleted, see docs/multi-tenancy-architecture.md's lifecycle
-- section, so this FK is a pure safety net, never expected to actually block anything) to every
-- tenant-owned table. One guarded ALTER per table, same idempotency convention as every prior
-- migration in this project.

SET @t = 'ellsms_wallet_accounts';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_wallet_accounts_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_wallet_transactions';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_created (organization_id, created_at), ADD CONSTRAINT fk_wallet_tx_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_wallet_reservations';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_status (organization_id, status), ADD CONSTRAINT fk_wallet_res_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_payments';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_status (organization_id, status), ADD CONSTRAINT fk_payments_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_numbers';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER assigned_user_id, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_numbers_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_contacts';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_contacts_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Nullable and deliberately NOT joining any new uniqueness constraint to it in this migration --
-- see docs/multi-tenancy-architecture.md for why existing (NULL-organization, i.e. legacy-global)
-- categories keep their current visible-to-everyone behavior and Phase 5's global UNIQUE(name)
-- is left untouched rather than naively widened to (organization_id, name), which would silently
-- defeat that uniqueness for every legacy row under standard SQL NULL semantics.
SET @t = 'ellsms_number_categories';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER created_by, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_categories_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_bulk_jobs';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_status (organization_id, status), ADD CONSTRAINT fk_bulk_jobs_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_schedule';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_run_at (organization_id, run_at), ADD CONSTRAINT fk_schedule_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_autoreply_rules';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_autoreply_rules_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_campaigns';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org (organization_id), ADD CONSTRAINT fk_campaigns_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @t = 'ellsms_tickets';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_status (organization_id, status), ADD CONSTRAINT fk_tickets_org FOREIGN KEY (organization_id) REFERENCES ellsms_organizations(id) ON DELETE RESTRICT'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- audit_log: organization_id kept nullable permanently, not just during migration -- some audit
-- events (e.g. a failed login for a username that never had panel access) have no resolvable
-- organization at all. No FK is added here on purpose: this table is a security append-only log
-- (docs/database-audit.md) and must never fail to INSERT because of an organization lifecycle
-- edge case -- an audit event must always be recordable.
SET @t = 'ellsms_audit_log';
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = @t AND column_name = 'organization_id');
SET @sql = IF(@col_exists = 0, CONCAT('ALTER TABLE ', @t, ' ADD COLUMN organization_id INT UNSIGNED NULL AFTER user_id, ADD KEY idx_org_created (organization_id, created_at)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
