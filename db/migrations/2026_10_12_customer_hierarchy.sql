-- Resellers (نمایندگی) and organizational customers (مشتری سازمانی), modeled on the Negar platform.
--
-- Every ELLSMS account gets a customer type:
--   normal   - an ordinary customer, no sub-accounts
--   reseller - may create sub-accounts. A level-0 reseller may also create sub-resellers, a
--              sub-reseller only normal and organizational customers
--   organ    - an organization. It may create sub-organizations only, and a sub-organization
--              (an organ whose parent is an organ) may not have sub-accounts of its own
--
-- Each parent has a sub-account quota (max_subset_limit). A child uses its own quota + 1 of the
-- parent's, exactly as Negar's maxSubsetLimit/usedSubsetLimit do. The used amount is summed from
-- the children, never stored, so it cannot drift.
--
-- expires_on is the last Tehran-local day the account may log in (NULL = never expires).
--
-- Credit moves from parent to child (and back) through the wallet ledger. tariff_bp is the price
-- coefficient in basis points applied to the system SMS price list (10000 = 100%): the admin sets a
-- reseller's own coefficient, the reseller sets each child's (never below its own), and the
-- difference is paid to the reseller once a day as profit (ellsms_reseller_profits).
--
-- ELLSMS-owned tables only. user_id / parent_user_id are plain user_.id integers, no FK to the
-- backend-owned user_ table (docs/database-audit.md). ADDITIVE AND INERT: an account without a row
-- here behaves exactly as before (normal customer, 100% tariff, no expiry). Idempotent.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_customer_accounts (
  user_id             BIGINT NOT NULL PRIMARY KEY,
  customer_type       ENUM('normal','reseller','organ') NOT NULL DEFAULT 'normal',
  parent_user_id      BIGINT NULL,
  level               TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_subset_limit    INT UNSIGNED NOT NULL DEFAULT 0,
  tariff_bp           INT UNSIGNED NOT NULL DEFAULT 10000,
  expires_on          DATE NULL,
  suspended           TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_customer_parent (parent_user_id, customer_type),
  KEY idx_customer_type (customer_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every tariff change, so the daily profit run prices a send at the coefficients in force when it
-- was charged, not at whatever they are when the run happens.
CREATE TABLE IF NOT EXISTS ellsms_customer_tariff_history (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT NOT NULL,
  tariff_bp           INT UNSIGNED NOT NULL,
  effective_from      DATETIME NOT NULL,
  changed_by_user_id  BIGINT NULL,
  KEY idx_tariff_user_from (user_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (day, reseller, spending account): what the account spent that day and the profit
-- it earned the reseller. The wallet credit for a reseller's day is a single ledger entry with the
-- idempotency key reseller_profit:<day>:<reseller>, written in the same transaction as these rows.
CREATE TABLE IF NOT EXISTS ellsms_reseller_profits (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profit_day          DATE NOT NULL,
  reseller_user_id    BIGINT NOT NULL,
  source_user_id      BIGINT NOT NULL,
  spent               BIGINT NOT NULL DEFAULT 0,
  profit              BIGINT NOT NULL DEFAULT 0,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_profit_day_reseller_source (profit_day, reseller_user_id, source_user_id),
  KEY idx_profit_reseller_day (reseller_user_id, profit_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which days the profit run has completed. A day is processed once, after it has ended.
CREATE TABLE IF NOT EXISTS ellsms_reseller_profit_days (
  profit_day          DATE NOT NULL PRIMARY KEY,
  resellers_paid      INT UNSIGNED NOT NULL DEFAULT 0,
  total_profit        BIGINT NOT NULL DEFAULT 0,
  processed_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menu access. A role is a named set of menu keys (app/MenuAccess.php has the catalog). Roles with
-- owner_user_id NULL are defined by a platform admin; a reseller or organization may define its own
-- roles for its sub-accounts. A user with no row in ellsms_user_menu_access and no overrides sees
-- every menu, exactly as before this migration.
CREATE TABLE IF NOT EXISTS ellsms_menu_roles (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                VARCHAR(120) NOT NULL,
  owner_user_id       BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_menu_role_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_menu_role_items (
  role_id             INT UNSIGNED NOT NULL,
  menu_key            VARCHAR(40) NOT NULL,
  PRIMARY KEY (role_id, menu_key),
  CONSTRAINT fk_menu_role_items_role FOREIGN KEY (role_id) REFERENCES ellsms_menu_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_user_menu_access (
  user_id             BIGINT NOT NULL PRIMARY KEY,
  role_id             INT UNSIGNED NULL,
  updated_by_user_id  BIGINT NULL,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user_menu_role (role_id),
  CONSTRAINT fk_user_menu_access_role FOREIGN KEY (role_id) REFERENCES ellsms_menu_roles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user exceptions on top of the role: 'allow' adds a menu the role lacks, 'deny' removes one.
CREATE TABLE IF NOT EXISTS ellsms_user_menu_overrides (
  user_id             BIGINT NOT NULL,
  menu_key            VARCHAR(40) NOT NULL,
  mode                ENUM('allow','deny') NOT NULL,
  PRIMARY KEY (user_id, menu_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
