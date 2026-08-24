-- FIN-13 (financial-commerce continuation) — refund FRAMEWORK, not an automatic refund policy.
--
-- DECISION: this product has NO automatic refund policy (app/Billing.php's own existing comment:
-- "never auto-refunds — no refund policy exists in this product", docs/wallet-architecture.md's
-- "Refund / compensation" section). This migration does not change that. It adds only the minimal
-- structure an explicit, admin-authorized, REASON-REQUIRED refund action needs:
--
-- ellsms_refund_events: append-only audit of every refund actually performed. Never a "refund
-- request queue" or a state machine of its own — a refund either happened (one row, with a reason
-- and the actor who authorized it) or it didn't. Mirrors the append-only-ledger-as-audit-trail
-- pattern already established by ellsms_subscription_events and ellsms_payment* audit logging.
--
-- WHAT THIS DOES NOT DO, on purpose (FIN-13's own explicit constraints):
--   - does NOT add a 'refunded' status to ellsms_payments.status (FIN-14: "do not widen [payment
--     states] blindly if unnecessary" — refund state lives on the INVOICE, which already gained
--     'refunded' in its own ENUM in FIN-1, since the invoice is this system's financial document of
--     record; the payment row itself stays an accurate record of what the GATEWAY reported)
--   - does NOT auto-subtract wallet credit that has already been spent (app/Financial.php's
--     billing_refund_credit_purchase() checks available_balance first and refuses rather than
--     creating a negative balance when insufficient credit remains — see that function's own
--     docblock)
--   - does NOT call any real payment-provider refund API (payment_gateway_supports_refund()
--     reports false for zarinpal — no such integration exists; true for the fake gateway only, as a
--     capability flag for testing, never auto-invoked)
--
-- Additive, guarded and rerun-safe: no data is written, nothing is dropped, and a second run emits
-- SELECT 1 no-ops.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ellsms_refund_events (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id        BIGINT UNSIGNED NOT NULL,
  payment_id        INT UNSIGNED NOT NULL,
  organization_id   INT UNSIGNED NULL,
  user_id           BIGINT NOT NULL,
  amount            BIGINT UNSIGNED NOT NULL,       -- the invoice total_amount refunded (framework supports only full refund of the invoice, not partial line-item refund)
  wallet_reversed   TINYINT(1) NOT NULL DEFAULT 0,   -- whether a wallet_credit(...,'refund',...) reversal actually happened (a credit purchase whose credit was already fully spent gets this =0, amount recorded as the invoice amount regardless — the wallet reversal and the financial refund record are independently true or false)
  reason            VARCHAR(500) NOT NULL,           -- REQUIRED — enforced in code (billing_refund_invoice()), not just here
  actor_user_id     BIGINT NOT NULL,                 -- the platform admin who authorized this; never NULL, this is never an automated action
  idempotency_key   VARCHAR(191) NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_refund_idempotency (idempotency_key),
  -- At most one refund event per invoice, ever — this framework supports refunding an invoice
  -- exactly once (full refund only), matching "do not implement dangerous automatic wallet
  -- subtraction... without defining policy": defining a partial/repeatable refund policy is
  -- explicitly out of scope here.
  UNIQUE KEY uniq_refund_invoice (invoice_id),
  KEY idx_org_created (organization_id, created_at),
  KEY idx_payment (payment_id),
  CONSTRAINT fk_refund_events_invoice FOREIGN KEY (invoice_id) REFERENCES ellsms_invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_events_payment FOREIGN KEY (payment_id) REFERENCES ellsms_payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
