-- The reseller panel (پنل نمایندگی), modeled on the Negar platform's reseller features on top of
-- 2026_10_12_customer_hierarchy.sql. See docs/reseller-panel.md and app/Reseller*.php.
--
--   ellsms_reseller_tariffs / _tariff_prices
--       Named tariffs (Negar's Tariff + Price): a reseller's own price list for its customers, one
--       Rial price per segment for each destination column (an operator id, 'other', 'intl', or
--       'all' = every column without a price of its own) and encoding (fa / en). A customer under a
--       reseller is given one with ellsms_customer_accounts.tariff_id. A cell left empty falls back to
--       the customer's tariff_bp on the list price, and no cell is ever charged below what the
--       reseller itself pays for that message.
--   ellsms_sms_price_snapshots.chain_prices
--       For a send by an account with a parent: the unit price every account up the chain pays for
--       that pricing group, so the daily profit run pays each reseller the exact difference.
--   ellsms_reseller_packages / _package_orders, ellsms_payments.reseller_order_id
--       Packages a reseller sells to its customers (Negar's Package): credit, tariff, validity and
--       quota. Assigned by the reseller, bought online by the customer, or redeemed with a voucher.
--   ellsms_reseller_vouchers / _voucher_uses
--       Gift codes (a free package, paid by the reseller) and discount codes (a percentage off an
--       online purchase).
--   ellsms_reseller_settings
--       Per-reseller white-label brand and own host name (Negar's Domain), the sign-up link and who
--       answers its customers' tickets.
--   ellsms_registration_requests.reseller_user_id
--       A sign-up through a reseller's link or host, approved by that reseller.
--   ellsms_tickets.assignee_user_id
--       The reseller that answers a ticket. NULL = platform support, as before.
--   ellsms_reseller_announcements
--       News a reseller publishes to its own customers (Negar's News).
--   ellsms_reseller_staff
--       Operator accounts that work in a reseller's panel with a limited set of permissions
--       (Negar's sub-users with roles).
--
-- ELLSMS-owned tables only. User ids are plain user_.id integers, no FK to the backend-owned user_
-- table (docs/database-audit.md). ADDITIVE AND INERT: nothing changes for an account until its
-- reseller uses these features. Idempotent: every ALTER is guarded by information_schema.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_reseller_tariffs (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id       BIGINT NOT NULL,
  title               VARCHAR(80) NOT NULL,
  description         VARCHAR(255) NOT NULL DEFAULT '',
  is_default          TINYINT(1) NOT NULL DEFAULT 0,
  deleted             TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reseller_tariff_title (owner_user_id, title),
  KEY idx_reseller_tariff_owner (owner_user_id, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_reseller_tariff_prices (
  tariff_id                       INT UNSIGNED NOT NULL,
  column_key                      VARCHAR(20) NOT NULL,          -- operator id as digits, 'other', 'intl' or 'all'
  encoding                        ENUM('fa','en') NOT NULL,
  price_per_segment_millicredits  BIGINT UNSIGNED NOT NULL,      -- thousandths of a Rial
  updated_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tariff_id, column_key, encoding),
  CONSTRAINT fk_reseller_tariff_prices_tariff FOREIGN KEY (tariff_id) REFERENCES ellsms_reseller_tariffs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_customer_accounts' AND column_name = 'tariff_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_customer_accounts ADD COLUMN tariff_id INT UNSIGNED NULL AFTER tariff_bp',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_sms_price_snapshots' AND column_name = 'chain_prices'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_sms_price_snapshots ADD COLUMN chain_prices VARCHAR(1000) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_reseller_packages (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id       BIGINT NOT NULL,
  name                VARCHAR(120) NOT NULL,
  description         VARCHAR(500) NOT NULL DEFAULT '',
  price_rial          BIGINT UNSIGNED NOT NULL,                 -- before discount and VAT
  discount_percent    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  credit_rial         BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- added to the customer's wallet
  tariff_id           INT UNSIGNED NULL,                        -- given to the customer, NULL = unchanged
  validity_days       INT UNSIGNED NULL,                        -- extends the customer's expiry, NULL = unchanged
  max_subset_limit    INT UNSIGNED NULL,                        -- sub-account quota, NULL = unchanged
  customer_types      VARCHAR(40) NOT NULL DEFAULT 'normal,organ,reseller',
  online_sale         TINYINT(1) NOT NULL DEFAULT 1,
  active              TINYINT(1) NOT NULL DEFAULT 1,
  deleted             TINYINT(1) NOT NULL DEFAULT 0,
  sort_order          INT NOT NULL DEFAULT 0,
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reseller_package_owner (owner_user_id, deleted, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per package given to a customer. reseller_amount is what the reseller's wallet moved by
-- (positive = received, negative = paid). An online order is created 'pending' with its payment and
-- applied in the same transaction as the payment claim (payment_claim_and_credit()).
CREATE TABLE IF NOT EXISTS ellsms_reseller_package_orders (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  package_id          INT UNSIGNED NOT NULL,
  reseller_user_id    BIGINT NOT NULL,
  buyer_user_id       BIGINT NOT NULL,
  source              ENUM('manual','online','voucher') NOT NULL,
  price_rial          BIGINT UNSIGNED NOT NULL DEFAULT 0,       -- what the customer paid, before VAT
  credit_rial         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reseller_amount     BIGINT NOT NULL DEFAULT 0,
  voucher_id          INT UNSIGNED NULL,
  payment_id          INT UNSIGNED NULL,
  status              ENUM('pending','applied','failed','cancelled') NOT NULL DEFAULT 'pending',
  failure_reason      VARCHAR(190) NOT NULL DEFAULT '',
  package_snapshot    TEXT NULL,                                -- the package as sold, JSON
  operation_key       VARCHAR(120) NULL,                        -- makes a double-submitted assignment a replay
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at          DATETIME NULL,
  UNIQUE KEY uniq_package_order_operation (operation_key),
  KEY idx_package_order_reseller (reseller_user_id, created_at),
  KEY idx_package_order_buyer (buyer_user_id, created_at),
  KEY idx_package_order_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'reseller_order_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_payments ADD COLUMN reseller_order_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_reseller_vouchers (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id       BIGINT NOT NULL,
  code                VARCHAR(32) NOT NULL,
  kind                ENUM('gift','discount') NOT NULL,
  package_id          INT UNSIGNED NULL,                        -- gift: required; discount: NULL = any package
  percent             TINYINT UNSIGNED NOT NULL DEFAULT 0,      -- discount only
  max_uses            INT UNSIGNED NOT NULL DEFAULT 1,
  used_count          INT UNSIGNED NOT NULL DEFAULT 0,
  expires_on          DATE NULL,                                -- last Tehran-local day it may be used
  active              TINYINT(1) NOT NULL DEFAULT 1,
  note                VARCHAR(190) NOT NULL DEFAULT '',
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reseller_voucher_code (code),
  KEY idx_reseller_voucher_owner (owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A customer uses a code once. For a discount code the row is written when the order is applied.
CREATE TABLE IF NOT EXISTS ellsms_reseller_voucher_uses (
  voucher_id          INT UNSIGNED NOT NULL,
  user_id             BIGINT NOT NULL,
  order_id            BIGINT UNSIGNED NULL,
  used_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (voucher_id, user_id),
  CONSTRAINT fk_reseller_voucher_uses_voucher FOREIGN KEY (voucher_id) REFERENCES ellsms_reseller_vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_reseller_settings (
  reseller_user_id      BIGINT NOT NULL PRIMARY KEY,
  brand_enabled         TINYINT(1) NOT NULL DEFAULT 0,
  host                  VARCHAR(190) NULL,                      -- e.g. sms.example.com, lower case
  brand_name            VARCHAR(120) NOT NULL DEFAULT '',
  brand_tagline         VARCHAR(190) NOT NULL DEFAULT '',
  logo_file             VARCHAR(80) NULL,                       -- under storage/brand-logos
  primary_color         VARCHAR(7) NOT NULL DEFAULT '',
  support_phone         VARCHAR(40) NOT NULL DEFAULT '',
  support_email         VARCHAR(190) NOT NULL DEFAULT '',
  login_note            VARCHAR(500) NOT NULL DEFAULT '',
  host_only_own_tree    TINYINT(1) NOT NULL DEFAULT 1,          -- the host signs in only this reseller's accounts
  signup_enabled        TINYINT(1) NOT NULL DEFAULT 0,
  signup_code           VARCHAR(32) NULL,
  signup_customer_type  ENUM('normal','organ') NOT NULL DEFAULT 'normal',
  signup_tariff_bp      INT UNSIGNED NULL,
  signup_tariff_id      INT UNSIGNED NULL,
  signup_expiry_days    INT UNSIGNED NULL,
  signup_package_id     INT UNSIGNED NULL,                      -- a welcome package, paid by the reseller
  tickets_to_reseller   TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_user_id    BIGINT NULL,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_reseller_host (host),
  UNIQUE KEY uniq_reseller_signup_code (signup_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_registration_requests' AND column_name = 'reseller_user_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_registration_requests ADD COLUMN reseller_user_id BIGINT NULL, ADD KEY idx_registration_reseller (reseller_user_id, state)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_tickets' AND column_name = 'assignee_user_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_tickets ADD COLUMN assignee_user_id BIGINT NULL, ADD KEY idx_ticket_assignee (assignee_user_id, status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS ellsms_reseller_announcements (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id       BIGINT NOT NULL,
  title               VARCHAR(160) NOT NULL,
  body                TEXT NOT NULL,
  severity            ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
  audience            ENUM('direct','tree') NOT NULL DEFAULT 'tree',
  active              TINYINT(1) NOT NULL DEFAULT 1,
  starts_on           DATE NULL,
  ends_on             DATE NULL,
  notified_count      INT UNSIGNED NOT NULL DEFAULT 0,
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reseller_announcement_owner (owner_user_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_reseller_staff (
  staff_user_id       BIGINT NOT NULL PRIMARY KEY,
  reseller_user_id    BIGINT NOT NULL,
  permissions         VARCHAR(500) NOT NULL DEFAULT '',          -- comma-separated reseller_staff_permissions() keys
  active              TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id  BIGINT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reseller_staff_reseller (reseller_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
