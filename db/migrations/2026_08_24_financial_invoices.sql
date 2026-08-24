-- FIN-1 (financial-commerce continuation) — immutable invoice layer + minimal coupons.
--
-- DECISION: extend the existing financial model, do not replace it. ellsms_payments (base schema:
-- db/ellsms_extra.sql; purpose/billing_record_id: 2026_08_06_billing.sql) remains the payment/
-- purchase-intent record and the authoritative source of truth for payment state
-- (pending/paid/failed). payment_claim_and_credit() and payment_claim_and_activate_subscription()
-- (app/zarinpal.php) remain the sole atomic claim-then-fulfill code paths. ellsms_wallet_transactions
-- remains the sole SMS-credit ledger. ellsms_subscriptions/app/Billing.php remains the sole
-- subscription state machine. Nothing here duplicates any of those.
--
-- What was genuinely missing: an IMMUTABLE commercial document a customer/admin can view, print, and
-- reason about independent of whatever a plan/credit price happens to be today. ellsms_billing_records
-- already does this for subscription charges but has no line items, no tax/discount breakdown, and no
-- counterpart for credit purchases. ellsms_invoices/ellsms_invoice_items close that gap for BOTH
-- purchase types, one invoice per payment (UNIQUE(payment_id) below), never editable after issuance.
--
-- ellsms_invoices:      one row per ellsms_payments row. subtotal/discount/tax/total are snapshotted
--                       at issuance and never recomputed — if a plan price or the tax rate changes
--                       tomorrow, every already-issued invoice keeps reading exactly what the
--                       customer was actually charged.
-- ellsms_invoice_items: one or more line items per invoice. Every current purchase type is single-line
--                       today (one credit package, one plan period) but the schema is line-item based
--                       from the start rather than a single flat amount, so a future multi-line
--                       purchase (e.g. credit + subscription in one checkout) does not require a
--                       schema change.
-- ellsms_coupons / ellsms_coupon_redemptions: minimal fixed/percent discount support (FIN-10). A
--                       redemption row per (coupon, organization) with a UNIQUE constraint is what
--                       makes "apply this coupon" idempotent and race-safe under concurrent use,
--                       exactly the same UNIQUE-constraint-as-guarantee pattern already established by
--                       ellsms_wallet_reservations and ellsms_usage_reservations.
--
-- Additive only. No existing table is altered except ellsms_payments, which gains one nullable FK-like
-- reference column (invoice_id) purely for convenience lookups — ellsms_payments.status remains the
-- authoritative payment state; ellsms_invoices.status is a SEPARATE, invoice-specific state that
-- mirrors payment outcomes but is never read as the payment source of truth.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_invoices (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id   INT UNSIGNED NULL,      -- nullable exactly like ellsms_payments.organization_id (pre-tenant-backfill payments)
  user_id           BIGINT NOT NULL,
  payment_id        INT UNSIGNED NOT NULL,  -- -> ellsms_payments.id, one invoice per payment
  invoice_number    VARCHAR(40) NOT NULL,   -- opaque, sequential-looking but not the payment's raw numeric id alone (IDOR — see docs/financial-commerce.md)
  purpose           ENUM('credit','subscription') NOT NULL, -- denormalized from the payment at issuance, for display without a join
  status            ENUM('issued','paid','cancelled','expired','refunded') NOT NULL DEFAULT 'issued',
  currency          VARCHAR(8) NOT NULL DEFAULT 'IRR',
  subtotal_amount   BIGINT UNSIGNED NOT NULL,
  discount_amount   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_amount        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_amount      BIGINT UNSIGNED NOT NULL, -- subtotal - discount + tax, enforced in code (billing_invoice_create()), never trusted from input
  coupon_id         BIGINT UNSIGNED NULL,
  coupon_code       VARCHAR(40) NULL,        -- snapshotted (a coupon row could be edited/disabled later; the invoice must still show what was actually applied)
  issued_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_at            TIMESTAMP NULL,          -- expiry deadline for an unpaid invoice (FIN's "expired invoices" — invoice.php enforces this, not a cron)
  paid_at           TIMESTAMP NULL,
  cancelled_at      TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_invoice_payment (payment_id),
  UNIQUE KEY uniq_invoice_number (invoice_number),
  KEY idx_org_status_created (organization_id, status, created_at),
  KEY idx_user_created (user_id, created_at),
  CONSTRAINT fk_invoices_payment FOREIGN KEY (payment_id) REFERENCES ellsms_payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_invoice_items (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id          BIGINT UNSIGNED NOT NULL,
  item_type           ENUM('sms_credit','subscription_plan','subscription_renewal','subscription_upgrade') NOT NULL,
  reference_code      VARCHAR(60) NULL,       -- e.g. plan code, denormalized/snapshotted like ellsms_billing_records.plan_code
  description_snapshot VARCHAR(255) NOT NULL, -- human-readable line description AT ISSUANCE TIME, never re-rendered from current product state
  quantity            INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price          BIGINT UNSIGNED NOT NULL,
  discount_amount     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_amount          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  line_total          BIGINT UNSIGNED NOT NULL, -- (unit_price * quantity) - discount_amount + tax_amount, enforced in code
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invoice (invoice_id),
  CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES ellsms_invoices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_coupons (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(40) NOT NULL,
  type            ENUM('fixed','percent') NOT NULL,
  value           BIGINT UNSIGNED NOT NULL,   -- fixed: minor-unit Rial amount; percent: 1-100 whole-percent integer (no fractional percent, matching integer-only money rule)
  enabled         TINYINT(1) NOT NULL DEFAULT 1,
  valid_from      TIMESTAMP NULL,
  valid_until     TIMESTAMP NULL,
  usage_limit     INT UNSIGNED NULL,          -- NULL = unlimited, same convention as ellsms_plan_limits.limit_value
  used_count      INT UNSIGNED NOT NULL DEFAULT 0,
  minimum_amount  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  organization_id INT UNSIGNED NULL,          -- NULL = usable by any organization; set = restricted to exactly one (minimal eligibility per FIN-10)
  created_by_user_id BIGINT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_coupon_code (code),
  KEY idx_enabled_validity (enabled, valid_from, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_coupon_redemptions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id       BIGINT UNSIGNED NOT NULL,
  invoice_id      BIGINT UNSIGNED NOT NULL,
  organization_id INT UNSIGNED NULL,
  user_id         BIGINT NOT NULL,
  discount_amount BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- One redemption per invoice, ever — this is what makes "apply this coupon" idempotent under a
  -- retried request AND what makes used_count increment exactly once no matter how many times a
  -- checkout submission races itself (FIN-10's "protect against usage race").
  UNIQUE KEY uniq_redemption_invoice (invoice_id),
  KEY idx_coupon (coupon_id, created_at),
  CONSTRAINT fk_redemptions_coupon FOREIGN KEY (coupon_id) REFERENCES ellsms_coupons(id) ON DELETE RESTRICT,
  CONSTRAINT fk_redemptions_invoice FOREIGN KEY (invoice_id) REFERENCES ellsms_invoices(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Convenience back-reference only — ellsms_payments.status remains authoritative for payment state;
-- this column exists so payment-centric code (the existing callback/reconcile flow) can find its
-- invoice in one indexed lookup without a reverse join. Guarded ALTER, rerun-safe.
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'ellsms_payments' AND column_name = 'invoice_id'
);
SET @sql = IF(@col_exists = 0,
  "ALTER TABLE ellsms_payments ADD COLUMN invoice_id BIGINT UNSIGNED NULL AFTER billing_record_id, ADD KEY idx_invoice (invoice_id)",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
