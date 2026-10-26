-- Manual payment methods for buying credit (app/ManualPayments.php, docs/manual-payments.md):
-- card-to-card (کارت به کارت), bank deposit (واریز به حساب), cash (نقدی) and cheque (پرداخت چک), next
-- to the existing online gateway.
--
-- ellsms_payment_destinations     the cards and bank accounts customers pay into. owner_user_id 0 =
--                                 the platform (set by a platform admin), otherwise a reseller (set in
--                                 its panel; its own customers pay into them).
-- ellsms_payment_method_settings  which manual methods an owner accepts.
-- ellsms_manual_payments          one row per manual payment a customer reports: the details it
--                                 entered (document number, date, receipt, cheque fields) and the
--                                 review (pending → approved | rejected). Each one has its own
--                                 ellsms_payments row (gateway = the method, status pending until
--                                 the review) and its invoice, so invoices and the payment history
--                                 show it like any other purchase.
--
-- Additive only: no existing table or column is changed. Guarded and rerun-safe (CREATE TABLE IF
-- NOT EXISTS). Until it is applied the extra payment methods are simply not offered.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_payment_destinations (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_user_id  BIGINT NOT NULL DEFAULT 0,          -- 0 = the platform, else the reseller's user id
  kind           ENUM('card','account') NOT NULL,
  bank           VARCHAR(40) NOT NULL DEFAULT '',     -- a key of MANUAL_PAYMENT_BANKS
  holder_name    VARCHAR(120) NOT NULL,
  card_number    VARCHAR(16) NULL,                    -- kind = card: 16 digits
  account_number VARCHAR(30) NULL,                    -- kind = account
  sheba          VARCHAR(26) NULL,                    -- kind = account: IR + 24 digits (optional)
  active         TINYINT(1) NOT NULL DEFAULT 1,
  deleted        TINYINT(1) NOT NULL DEFAULT 0,       -- soft delete: old payments still show where they were paid
  sort_order     INT NOT NULL DEFAULT 0,
  updated_by     BIGINT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_payment_destinations_owner (owner_user_id, kind, deleted, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_payment_method_settings (
  owner_user_id BIGINT NOT NULL,                      -- 0 = the platform, else the reseller's user id
  method        VARCHAR(10) NOT NULL,                 -- card | account | cash | cheque
  enabled       TINYINT(1) NOT NULL DEFAULT 0,
  updated_by    BIGINT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (owner_user_id, method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_manual_payments (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id               INT UNSIGNED NOT NULL,          -- -> ellsms_payments.id (one manual row per payment)
  user_id                  BIGINT NOT NULL,
  organization_id          INT UNSIGNED NULL,
  method                   ENUM('card','account','cash','cheque') NOT NULL,
  collector_user_id        BIGINT NOT NULL DEFAULT 0,      -- who received the money and reviews it: 0 = platform admins, else the reseller
  destination_id           INT UNSIGNED NULL,              -- -> ellsms_payment_destinations.id
  destination_label        VARCHAR(255) NULL,              -- snapshot of the card/account as the customer saw it
  credits                  BIGINT UNSIGNED NOT NULL,       -- Rial credited to the wallet on approval
  amount_rial              BIGINT UNSIGNED NOT NULL,       -- the amount paid (= the invoice total, VAT included)
  document_number          VARCHAR(64) NOT NULL,           -- شماره سند / پیگیری
  paid_at                  DATETIME NOT NULL,              -- the payment's date and time as the customer entered it (local time)
  description              VARCHAR(500) NULL,
  attachment_file          VARCHAR(80) NULL,               -- receipt or cheque image under storage/payment-receipts/
  cheque_amount            BIGINT UNSIGNED NULL,
  cheque_bank              VARCHAR(40) NULL,
  cheque_branch_code       VARCHAR(10) NULL,
  cheque_owner_name        VARCHAR(120) NULL,
  cheque_owner_sheba       VARCHAR(26) NULL,
  cheque_owner_national_id VARCHAR(11) NULL,
  cheque_sayad_number      VARCHAR(16) NULL,
  cheque_serial            VARCHAR(20) NULL,
  cheque_series            VARCHAR(20) NULL,
  cheque_due_on            DATE NULL,
  review_state             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_note              VARCHAR(500) NULL,
  reviewed_by              BIGINT NULL,
  reviewed_at              TIMESTAMP NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_manual_payments_payment (payment_id),
  KEY idx_manual_payments_collector (collector_user_id, review_state, id),
  KEY idx_manual_payments_user (user_id, id),
  KEY idx_manual_payments_document (collector_user_id, method, document_number),
  CONSTRAINT fk_manual_payments_payment FOREIGN KEY (payment_id) REFERENCES ellsms_payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
