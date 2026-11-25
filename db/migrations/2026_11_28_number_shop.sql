-- Number shop («خرید شماره», docs/number-shop.md, app/NumberShop.php).
--
-- An admin puts an unassigned line from ellsms_numbers up for sale with a purchase price, a yearly
-- renewal price, a category, a description and a list of features. A customer buys it on
-- /my-numbers.php, paying from the wallet or online; either way an invoice is issued. A purchase
-- waits for an admin's approval: approval assigns the line with a one-year expiry, rejection returns
-- the money to the customer's wallet. Renewals extend the expiry by one Jalali year straight away.
--
--   ellsms_number_listings  one row per line on (or once on) sale. state: on_sale → reserved (an order
--                           holds it: unpaid online for up to 30 minutes, or paid and waiting for the
--                           admin) → sold; withdrawn = taken off sale by an admin.
--   ellsms_number_orders    one row per purchase or renewal, with what was charged (snapshotted).
--   ellsms_payments.number_order_id  links an online payment to its order, like reseller_order_id.
--   ellsms_invoices.purpose 'number' and ellsms_invoice_items.item_type 'number_purchase' /
--                           'number_renewal' for the invoices of these orders.
--
-- Additive and rerun-safe: CREATE TABLE IF NOT EXISTS, guarded ALTERs, and ENUM widenings that only
-- add values.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_number_listings (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number_id        INT UNSIGNED NOT NULL,
  category         VARCHAR(60)  NOT NULL DEFAULT '',
  title            VARCHAR(120) NOT NULL DEFAULT '',
  description      TEXT NOT NULL,
  features         TEXT NOT NULL,                -- one feature per line
  price            BIGINT UNSIGNED NOT NULL,     -- purchase price, rial, before VAT
  renewal_price    BIGINT UNSIGNED NOT NULL,     -- yearly renewal price, rial, before VAT
  featured         TINYINT(1) NOT NULL DEFAULT 0,
  state            ENUM('on_sale','reserved','sold','withdrawn') NOT NULL DEFAULT 'on_sale',
  order_id         BIGINT UNSIGNED NULL,         -- the order holding or holding-then-buying it
  reserved_until   TIMESTAMP NULL,               -- UTC; set only while an online payment is pending
  sold_to_user_id  BIGINT NULL,
  sold_at          TIMESTAMP NULL,
  created_by       BIGINT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_number_listing_number (number_id),
  KEY idx_number_listing_state (state, featured),
  KEY idx_number_listing_buyer (sold_to_user_id),
  CONSTRAINT fk_number_listing_number FOREIGN KEY (number_id) REFERENCES ellsms_numbers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ellsms_number_orders (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  listing_id       INT UNSIGNED NOT NULL,
  number_id        INT UNSIGNED NOT NULL,
  number           VARCHAR(20) NOT NULL,         -- snapshot, kept if the line is later deleted
  user_id          BIGINT NOT NULL,
  organization_id  INT UNSIGNED NULL,
  kind             ENUM('purchase','renewal') NOT NULL,
  pay_method       ENUM('wallet','online') NOT NULL,
  price            BIGINT UNSIGNED NOT NULL,     -- before VAT, as charged
  total_amount     BIGINT UNSIGNED NOT NULL,     -- invoice total (with VAT), as charged
  listing_snapshot TEXT NOT NULL,                -- JSON: category, title, description, features, prices at checkout
  status           ENUM('pending_payment','awaiting_approval','completed','rejected','failed','expired','refunded') NOT NULL,
  payment_id       INT UNSIGNED NULL,
  invoice_id       BIGINT UNSIGNED NULL,
  admin_note       VARCHAR(500) NOT NULL DEFAULT '',
  decided_by       BIGINT NULL,
  decided_at       TIMESTAMP NULL,
  paid_at          TIMESTAMP NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_number_order_user (user_id, created_at),
  KEY idx_number_order_status (status, created_at),
  KEY idx_number_order_listing (listing_id),
  KEY idx_number_order_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'number_order_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE ellsms_payments ADD COLUMN number_order_id BIGINT UNSIGNED NULL, ADD KEY idx_payment_number_order (number_order_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Widen the invoice ENUMs only when 'number' is not there yet (a MODIFY that drops nothing).
SET @has_value = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_invoices' AND column_name = 'purpose' AND column_type LIKE '%''number''%'
);
SET @sql = IF(@has_value = 0,
  "ALTER TABLE ellsms_invoices MODIFY purpose ENUM('credit','subscription','number') NOT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_value = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_invoice_items' AND column_name = 'item_type' AND column_type LIKE '%''number_purchase''%'
);
SET @sql = IF(@has_value = 0,
  "ALTER TABLE ellsms_invoice_items MODIFY item_type ENUM('sms_credit','subscription_plan','subscription_renewal','subscription_upgrade','number_purchase','number_renewal') NOT NULL",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
