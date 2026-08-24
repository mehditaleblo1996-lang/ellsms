# ELLSMS — Financial Commerce Final Report

## Order/Invoice/Payment/Fulfillment Extension (FIN-1 through FIN-13)

## 0. A note on phase numbering

**"FIN-1" through "FIN-13" are LOCAL labels used only within this financial-commerce continuation
session.** They are unrelated to this repository's own historical `docs/phase-N-final-report.md`
series (Phase 1–13, a different body of work) and unrelated to the "Phase 9A/9B/9C/10/11" labels
used by the immediately preceding scale-continuation session
(`docs/scale-continuation-final-report.md`). This document follows the existing
`docs/phase-N-final-report.md` closure-report convention without claiming to be part of either
numbered series.

## 1. Executive Summary

**DECISION: extend the existing financial model rather than build a parallel Order/Invoice/Payment
system.** Forensic audit before any implementation found that `ellsms_payments` already served as a
merged order/payment record, and `payment_claim_and_credit()`/
`payment_claim_and_activate_subscription()` (`app/zarinpal.php`) already implemented atomic
claim-then-fulfill with exactly the idempotency guarantee this work's own requirements describe —
already tested, already production-grade. Building a new three-table Order/Invoice/Payment system
alongside that would have created two competing financial sources of truth in one codebase, which
this continuation's explicit instruction forbids.

What was genuinely missing — confirmed by grep, not assumed — was built as extensions: an immutable
invoice layer (FIN-1), a generic payment gateway abstraction plus a fake/sandbox gateway (FIN-2/3),
their integration into the existing purchase/callback/reconciliation flow with a fail-closed
amount-mismatch check (FIN-4), pure subscription renewal (FIN-7), tax and coupon support (part of
FIN-1), customer and admin financial UI (FIN-5/11/12), and a refund framework — explicitly not an
automatic policy, since this product has none (FIN-13). Plan upgrade (FIN-8) required no new code —
it already worked through the existing, unmodified `subscription_change_plan()` — and received
invoice-linkage test coverage only.

**Every existing tested behavior — the payment claim's atomic UPDATE, the wallet ledger's exactly-
once semantics, the subscription state machine's transition table — was preserved unmodified.**
`billing_invoice_mark_paid()` was added as one additive call inside the SAME existing transaction
both claim functions already used; nothing about their existing signatures, return shapes, or
tested duplicate/concurrent-callback guarantees changed.

**Final gate: full fresh-database integration suite — 702 tests, 6362 assertions, 0 failures, 0
errors** (up from the 658-test baseline this continuation started from — 44 new integration tests,
plus 24 new unit tests, 369 unit total). Full breakdown in §8.

## 2. FIN-1 — Immutable invoice layer

`ellsms_invoices`/`ellsms_invoice_items` (new), one invoice per `ellsms_payments` row
(`UNIQUE(payment_id)`), snapshotted subtotal/discount/tax/total that never changes after issuance
regardless of later plan-price or credit-rate changes — proven directly
(`testInvoiceIsImmutableAgainstALaterPriceChange`). Invoice numbers are opaque
(`INV-{year}-{6 hex chars}`), not the raw sequential id, specifically to avoid a financial-document
enumeration/IDOR surface.

Tax (`BILLING_TAX_PERCENT`, default `0`) and minimal coupons (`ellsms_coupons`/
`ellsms_coupon_redemptions` — fixed/percent, validity window, usage limit, minimum amount, optional
org eligibility) were built alongside the invoice layer since both are properties OF an invoice
(FIN-9/FIN-10). Coupon usage-limit races are closed by a locked re-check inside the same transaction
as invoice creation, backed by `UNIQUE(invoice_id)` on the redemption row.

## 3. FIN-2/FIN-3 — Payment gateway abstraction and fake gateway

`app/Payment/PaymentGateway.php` dispatches by gateway name; the ZarinPal adapter is a thin wrapper
around the pre-existing, unmodified `zarinpal_request()`/`zarinpal_verify()` — zero behavior change
to live payment processing. `ellsms_payments.gateway` (new, additive, default `'zarinpal'`) records
which adapter created each payment so reconciliation dispatches correctly.

`app/Payment/FakeGateway.php` — `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED`, default `0`, refused at three
independent points (the dispatcher's own selection logic, and both the create/verify functions
themselves even if called directly). Seven modes (SUCCESS/FAILED/CANCELLED/TIMEOUT/
VERIFY_FAILURE/AMOUNT_MISMATCH/DUPLICATE_CALLBACK), no external network call, exercises the real
pipeline end to end rather than shortcutting past any of its business logic.

## 4. FIN-4 — Verified fulfillment integration

The shared callback handler now dispatches through `payment_gateway_verify()` and enforces an
AMOUNT_MISMATCH fail-closed check before any claim is attempted: if the gateway's own verify
response carries a distinct confirmed amount that disagrees with the payment's stored amount, the
payment is marked permanently `failed`, a `CRITICAL` security event is logged and audited, and
nothing is claimed or fulfilled. (Structural no-op for ZarinPal's v4 API, which exposes no
independently-confirmed amount; real and exercised for the fake gateway and any future adapter that
does expose one — disclosed honestly in `docs/technical-debt.md`, not hidden.)

`billing_invoice_mark_paid()` — one additive call inside `payment_claim_and_credit()`'s and
`payment_claim_and_activate_subscription()`'s existing atomic transaction. Invoice-paid state now
transitions under the exact same guarantee that already protected the payment claim and the wallet/
subscription fulfillment.

## 5. FIN-7 — Pure subscription renewal

`subscription_renew()` (`app/Billing.php`), genuinely distinct from `subscription_change_plan()` —
same plan, no unintended change. **Documented rule**: extend from `current_period_end` if still in
the future; extend from now if the period has already lapsed. `ellsms_billing_records.purchase_type`
(new, additive, default `'new'`) routes `payment_claim_and_activate_subscription()` to this function
only for `'renewal'`-typed billing records — every other value keeps the exact pre-existing
activate/plan-overwrite behavior, unchanged and still passing every one of its own pre-existing
tests.

## 6. FIN-8 — Plan upgrade

No code changes to upgrade semantics. `subscription_change_plan()`'s existing immediate-mode
behavior (STEP 27: full new-plan-period price, no proration, usage counters not reset) is
unmodified. This continuation added invoice linkage (the same `billing_invoice_create()` call the
credit-purchase and new-subscription paths already use) and two tests confirming the upgrade
invoice is issued, marked paid exactly once, and immune to duplicate-callback double-activation.

## 7. FIN-13 — Refund framework, not policy

**This product has no automatic refund policy** — `app/Billing.php`'s `subscription_cancel()`
already documented this before this work began. `billing_refund_invoice()` (`app/Financial.php`)
builds exactly the framework `docs/wallet-architecture.md` had already anticipated
(`wallet_credit(..., 'refund', ...)` as "the primitive a future refund UI would call"):

- Reason required; admin-authorized only (`require_admin()`).
- Full-invoice refund, exactly once — `UNIQUE(invoice_id)` on new `ellsms_refund_events` makes a
  repeat attempt a safe replay.
- Only a `paid` invoice may be refunded.
- Credit-purchase invoices: wallet credit reversed only if the account still holds at least that
  much UNSPENT balance — never creates a negative balance from an already-spent purchase; the
  invoice is still marked refunded either way, `wallet_reversed` recorded honestly.
- Subscription invoices: **no automatic subscription action whatsoever** — refunding money never
  touches the plan or period.
- `ellsms_payments.status` was NOT widened with a `refunded` value (FIN-14's explicit
  "do not widen blindly") — refund state lives entirely on the invoice.
- Real payment-provider refund is not implemented; `payment_gateway_supports_refund('zarinpal')`
  honestly reports `false`.

## 8. Full Test Results

- **Unit**: **369 tests, 2473 assertions, 0 failures** (24 new: 8 in
  `BillingInvoiceArithmeticTest`, 16 in `PaymentGatewayTest`).
- **`FinancialInvoiceTest`** (FIN-1): 19 tests — creation/arithmetic, immutability, idempotent
  creation, tenant isolation, cancel/expiry, 8 coupon scenarios.
- **`FakePaymentGatewayE2eTest`** (FIN-3/4): 8 tests — credit purchase success, duplicate callback
  ×10, concurrent callback (real subprocesses), plan purchase with duplicate-activation guard,
  failed payment, verify-failure retryable state, amount-mismatch fail-closed, tenant isolation.
- **`SubscriptionRenewalTest`** (FIN-7): 7 tests — the future-vs-lapsed extension rule, plan-never-
  changes, non-renewable free plan refused, duplicate-callback idempotency, full payment-driven
  flow.
- **`SubscriptionUpgradeInvoiceTest`** (FIN-8): 2 tests — invoice-linked upgrade, duplicate-callback
  guard.
- **`RefundFrameworkTest`** (FIN-13): 8 tests — reason required, unpaid-invoice refusal, wallet
  reversal when sufficient balance remains, negative-balance guard (fully spent and partially spent
  scenarios), idempotent replay, subscription-untouched guarantee, audit trail.
- **Full integration suite, fresh database, every migration applied in filename order, no filter**:
  **702 tests, 6362 assertions, 0 failures, 0 errors** — includes every pre-existing Phase 1–13 and
  scale-continuation regression suite alongside all 44 new financial-commerce integration tests.
- **PHP lint**: 304 files, clean.
- **Backend boundary check**: PASS.
- **Fresh-DB migration replay**: verified independently at three points during this work (after
  FIN-1, after FIN-8/13, and as the final gate above) — every new migration applies cleanly to a
  database built from `db/ellsms_extra.sql` + every `db/migrations/*.sql` file in order, and is
  independently rerun-safe (verified for each new migration individually).

## 9. Files Created

Migrations: `db/migrations/2026_08_24_financial_invoices.sql`,
`2026_08_24_financial_payment_gateway_column.sql`,
`2026_08_24_financial_billing_record_purchase_type.sql`,
`2026_08_24_financial_refund_events.sql`. App: `app/Financial.php`,
`app/Payment/PaymentGateway.php`, `app/Payment/FakeGateway.php`. Web: `public/invoices.php`,
`public/financial-admin.php`. Tests: `tests/Unit/BillingInvoiceArithmeticTest.php`,
`tests/Unit/PaymentGatewayTest.php`, `tests/Integration/FinancialInvoiceTest.php`,
`tests/Integration/FakePaymentGatewayE2eTest.php`, `tests/Integration/SubscriptionRenewalTest.php`,
`tests/Integration/SubscriptionUpgradeInvoiceTest.php`, `tests/Integration/RefundFrameworkTest.php`,
`tests/fixtures/payment_credit_claim_worker.php`. Docs: `docs/financial-commerce.md`,
`docs/financial-commerce-final-report.md` (this document).

## 10. Files Modified

`app/bootstrap.php` (require the two new financial files), `app/zarinpal.php`
(`billing_invoice_mark_paid()` calls added inside both existing claim transactions; the renewal
branch added to `payment_claim_and_activate_subscription()`), `app/Billing.php`
(`subscription_renew()` added; `billing_record_create()` gains an additive `$purchaseType`
parameter, default `'new'`), `public/buy-credit.php`, `public/billing.php`,
`public/zarinpal-callback.php`, `cron/payments-reconcile.php` (all four: dispatch through the
gateway abstraction instead of calling `zarinpal_*()` directly; invoice creation added to the
purchase-creation POST handlers; a "renew at current plan" action added to `billing.php`),
`app/views/header.php` (nav links for the two new pages), `public/assets/css/style.css` (a minimal
print rule for the invoice detail page), `docs/technical-debt.md`, `docs/production-runbook.md`.

## 11. Breaking Changes

**None.** Every new column is additive with a default matching prior actual behavior exactly
(`gateway` defaults `'zarinpal'` — the only gateway that ever existed before this work;
`purchase_type` defaults `'new'` — the only behavior `payment_claim_and_activate_subscription()`
ever had before FIN-7). The fake gateway is off by default and independently refused at multiple
points even if misconfigured. No existing function's signature lost a parameter or changed its
return shape; `billing_record_create()`'s new parameter is appended with a default, and
`db_transaction()`'s existing nesting contract is what let `subscription_renew()`'s own transaction
join the caller's without any special-casing.

## 12. Rollback Considerations

- Every new migration is purely additive (new tables, or new nullable/defaulted columns) — dropping
  them loses only the new financial-document/coupon/refund history, never the underlying
  payment/wallet/subscription state those systems already protect.
- The fake gateway's rollback is simply never setting `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1` in
  production — no data or configuration needs to be undone.
- No existing behavior needs a rollback path of its own, since none of it changed.

## 13. Remaining Risks — disclosed, not hidden

- **No real payment-provider refund** — see `docs/technical-debt.md`'s new dated section.
- **No partial/line-item refund policy** — deliberately out of scope; the underlying product
  decision (which items are refundable, and how much) has never been made.
- **The AMOUNT_MISMATCH check is a structural no-op for ZarinPal specifically** — a fact about that
  provider's API, not a gap in the check itself, and it is real and exercised for the fake gateway
  and would activate for a future adapter that exposes a confirmed amount.
- **No `COMMERCE_ENABLED` master flag** — a deliberate decision, documented in
  `docs/financial-commerce.md`, reasoned from the fact that credit purchase already existed and
  was already always-on before this work, and subscription purchase/renewal is already fully
  gated by the existing `BILLING_ENABLED` (default off).

**FINANCIAL READINESS: the code changes in this continuation are complete, tested (702/702 on a
fresh database), and documented.** The conditions that cannot be satisfied from this repository
alone are entirely about the REAL payment provider: a real ZarinPal refund integration (if the
business ever needs one) and, more immediately, confirming the already-configured ZarinPal
merchant credentials still work end-to-end against the live API before relying on this system for
real customer payments — neither of those requires further code changes from this continuation,
both require an operator with real provider access to verify.
