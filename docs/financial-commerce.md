# ELLSMS — Financial commerce (FIN-1 through FIN-13)

Order/invoice/payment/fulfillment architecture built on top of the existing, unchanged billing/
wallet/subscription foundation. See `docs/financial-commerce-final-report.md` for the closure
narrative and test results.

## DECISION: extend the existing financial model, not a parallel one

`ellsms_payments` (`db/ellsms_extra.sql`; `purpose`/`billing_record_id`/`gateway`/`invoice_id`
added additively across `db/migrations/2026_08_06_billing.sql` and this work) already played the
role of a merged order+payment record before this work began — one row per purchase intent,
`purpose='credit'|'subscription'`, `status='pending'|'verification_failed'|'paid'|'failed'`. The
atomic claim-then-fulfill functions that make a duplicate/concurrent callback safe
(`payment_claim_and_credit()`, `payment_claim_and_activate_subscription()`, both in
`app/zarinpal.php`) already existed, already tested, before this work began.

This work therefore did **not** build a new `ellsms_orders` table. `ellsms_payments` remains the
order/purchase-intent record. What was genuinely missing — a distinct, immutable, printable
financial document, a generic (not ZarinPal-hardcoded) payment gateway interface, a sandbox gateway
for testing, pure renewal, tax/discount, customer/admin financial UI, and a refund framework — is
what FIN-1 through FIN-13 added, entirely as extensions:

| Concept | Source of truth | Owner |
|---|---|---|
| Purchase intent / payment attempt | `ellsms_payments` | `app/zarinpal.php` (unchanged) |
| Immutable financial document | `ellsms_invoices` / `ellsms_invoice_items` (new) | `app/Financial.php` (new) |
| SMS credit ledger | `ellsms_wallet_transactions` | `app/wallet.php` (unchanged) |
| Subscription state | `ellsms_subscriptions` | `app/Billing.php` (extended, not replaced) |

Nothing above was merged into anything else. `wallet_credit()`, `wallet_debit()`,
`wallet_reserve()`/`wallet_commit_reservation()`/`wallet_release_reservation()`, and every
subscription transition function in `app/Billing.php` (`subscription_create()`,
`subscription_change_plan()`, `subscription_cancel()`, `subscription_transition()`) are called
exactly as before — none of their existing, tested behavior changed.

## The invoice layer (FIN-1)

`ellsms_invoices`: one row per `ellsms_payments` row (`UNIQUE(payment_id)`), `status`
`issued`→`paid`/`cancelled`/`expired`/`refunded`. `ellsms_invoice_items`: one or more line items,
always present even for today's single-line purchases (schema is future-proof, not
over-engineered — no multi-line purchase exists yet, so nothing multi-line is exercised).

**Immutable by construction**: `billing_invoice_create()` snapshots `subtotal_amount`,
`discount_amount`, `tax_amount`, `total_amount` at issuance from server-derived unit prices only —
never re-reads a plan/credit-rate table after that point, never accepts an amount from request
input. If the credit rate or a plan's price changes tomorrow, every already-issued invoice reads
exactly as it did when issued (`tests/Integration/FinancialInvoiceTest.php::testInvoiceIsImmutableAgainstALaterPriceChange`).

**Invoice numbering**: `INV-{year}-{6 random hex chars}`, not the raw auto-increment id — a
predictable sequential financial-document number is an enumeration/IDOR surface even behind
authorization, so the public identifier is opaque while the UNIQUE index on it is the actual
collision guarantee.

**Idempotent creation**: `billing_invoice_create()` checks `UNIQUE(payment_id)` first — a retried
"create the invoice for this payment" call (a double-submitted checkout) replays the existing
invoice rather than erroring or creating a second one.

## Tax (FIN-9)

`BILLING_TAX_PERCENT` (env, default `0`, clamped `0`–`100`). Applied to the amount **after**
discount (`billing_calculate_tax()`), never to the pre-discount subtotal. Integer division, floors
— a fractional Rial from rounding is absorbed by the merchant, never charged to the customer.
Distributed proportionally across line items so `SUM(line_total) === total_amount` exactly, always
(`tests/Integration/FinancialInvoiceTest.php::testInvoiceItemsLineTotalSumsToInvoiceTotal`).

## Coupons (FIN-10)

`ellsms_coupons` / `ellsms_coupon_redemptions` — fixed or percent discount, `enabled`,
`valid_from`/`valid_until`, `usage_limit`, `minimum_amount`, optional single-organization
eligibility. Deliberately minimal — no marketing/promotion platform, no stacking, no per-user
limits beyond what `organization_id` restriction already provides.

**Race-safe usage counting**: `billing_coupon_validate()` (called first, unlocked — a cheap
preview) is *not* the guarantee. `billing_coupon_redeem()`, called inside the SAME transaction as
invoice creation, locks the coupon row (`SELECT ... FOR UPDATE`) and re-checks the usage limit
immediately before incrementing — this is the actual race guarantee, proven by
`UNIQUE(invoice_id)` on `ellsms_coupon_redemptions` making a double-redemption for one invoice
structurally impossible even under a crafted retry.

**Discount never exceeds the subtotal it's applied to** — a fixed discount larger than the
subtotal clamps to the subtotal (`min($subtotal, $couponValue)`), never producing a negative
invoice total.

## Payment gateway abstraction (FIN-2)

`app/Payment/PaymentGateway.php` — a name-based dispatcher (`payment_gateway_create()`,
`payment_gateway_redirect_url()`, `payment_gateway_verify()`, `payment_gateway_supports_refund()`)
so core commerce logic (`public/buy-credit.php`, `public/billing.php`, the shared callback handler)
never references a ZarinPal-specific field name directly.

`payment_gateway_name()` decides which adapter a NEW payment uses:
`PAYMENT_DEFAULT_GATEWAY` (env, default `zarinpal`), except `fake` is only ever selected when
`ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1` is *also* set — a misconfigured `PAYMENT_DEFAULT_GATEWAY=fake`
in production alone can never route real payments through the sandbox.

`ellsms_payments.gateway` (new, additive, default `'zarinpal'`) records which adapter created each
payment, so `cron/payments-reconcile.php` re-verifies a stale payment against the SAME gateway that
issued its authority — never assumes ZarinPal.

**The ZarinPal adapter is a thin wrapper, not a rewrite.** `payment_zarinpal_gateway_create()`/
`payment_zarinpal_gateway_verify()` call the existing, unchanged `zarinpal_request()`/
`zarinpal_verify()` (`app/zarinpal.php`) — zero behavior change to live payment processing.

## Fake/sandbox gateway (FIN-3)

`app/Payment/FakeGateway.php`. `ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED` — default `0`. No external
network call anywhere in the file.

**Defense in depth**: refused at THREE independent points — `payment_gateway_name()` won't select
it when disabled, `payment_fake_gateway_create()` refuses even if called directly, and
`payment_fake_gateway_verify()` refuses even if called directly. A caller that bypasses the
dispatcher entirely still cannot get a fake success out of a disabled sandbox.

**Modes**, encoded into the authority string itself (`FAKE-{MODE}-{random}`) so `verify()` — which
only ever receives the authority, exactly like a real gateway callback — recovers the intended
outcome with no separate state table:

| Mode | Behavior |
|---|---|
| `SUCCESS` | create + verify both succeed, `verified_amount_rial` matches exactly |
| `FAILED` | create refuses outright |
| `CANCELLED` | create succeeds; `payment_fake_gateway_redirect_url()` signals `Status=NOK`, mirroring a real user cancelling at the gateway's own checkout page |
| `TIMEOUT` | create refuses (simulates the gateway being unreachable) |
| `VERIFY_FAILURE` | create succeeds; verify fails |
| `AMOUNT_MISMATCH` | create + verify both succeed, but `verified_amount_rial` is deliberately different from the requested amount — exercises the caller's OWN amount-comparison logic, not a shortcut past it |
| `DUPLICATE_CALLBACK` | verify is safely callable any number of times — the actual duplicate-prevention guarantee lives in the caller's claim transaction (`payment_claim_and_credit()`'s atomic UPDATE), not in this mode |

**Selected via `mobile: "test:{MODE}"` at creation time** — a fake-only test convenience field; a
genuine mobile number never matches that pattern and falls through to `SUCCESS`.

**No business logic is bypassed.** The fake gateway exercises the identical create → callback →
verify → claim → fulfill pipeline a real gateway does; it never calls
`payment_claim_and_credit()`/`payment_claim_and_activate_subscription()`/`billing_invoice_mark_paid()`
directly or skips any of them.

## Callback → verify → claim → fulfill (FIN-4)

A gateway callback is **never** sufficient by itself. `public/zarinpal-callback.php` (shared by
every gateway, not ZarinPal-specific despite the filename — changing it would be a URL-compatibility
break for an already-registered real merchant callback):

1. Load the payment row by id, check ownership (`user_id` matches the logged-in user).
2. Check the `authority` echoed back matches the one this payment row actually requested.
3. If the gateway itself reports cancellation/failure: mark the payment `failed` (final).
4. Otherwise: `payment_gateway_verify($gateway, $amountRial, $authority)` — a real
   server-to-server verification call, never trusting the callback's own query-string parameters as
   proof of payment.
5. **AMOUNT MISMATCH check (FIN-39)**: if the gateway's own verify response carries a distinct
   confirmed amount (`verified_amount_rial`) and it disagrees with the payment's own stored
   `amount_rial`, **fail closed** — mark the payment permanently `failed`, log a `CRITICAL` security
   event (`payment.amount_mismatch`), audit it, and never proceed to claim/fulfill. (ZarinPal's v4
   API does not expose a separately-confirmed amount distinct from the request parameter, so this
   check is a structural no-op for that adapter and a real, exercised guard for the fake gateway and
   any future real adapter that does expose one.)
6. Only then: `payment_claim_and_credit()` or `payment_claim_and_activate_subscription()` — the
   existing, unchanged atomic claim-then-fulfill functions.

## Exactly-once, end to end

`payment_claim_and_credit()`/`payment_claim_and_activate_subscription()` each perform, in ONE
transaction:

```
UPDATE ellsms_payments SET status='paid' WHERE id=? AND status IN ('pending','verification_failed')
  -> rowCount() === 0 means someone else already claimed it: return, do nothing further
billing_invoice_mark_paid()   -- additive (FIN-4): same transaction, same atomic-UPDATE pattern
wallet_credit(...) / subscription activate-or-renew(...)
```

The payment claim's `rowCount()` check is the actual duplicate-prevention mechanism — not an
application-level `if ($status === 'paid')` read, which would race under concurrency. Two
genuinely concurrent callbacks for the same payment both reach this UPDATE; exactly one affects a
row and proceeds, the other sees zero rows affected and returns immediately. Proven with real
subprocesses, not mocked concurrency, in
`tests/Integration/FakePaymentGatewayE2eTest.php::testCreditPurchaseConcurrentCallbacksCreditExactlyOnce`.

`billing_invoice_mark_paid()` is one additive call inside that same existing transaction — it did
not require touching the claim UPDATE's own WHERE clause or return shape, so every pre-existing
test covering `payment_claim_and_credit()`/`payment_claim_and_activate_subscription()`'s duplicate/
concurrent-callback behavior kept passing unmodified.

## Pure renewal (FIN-7)

`subscription_renew()` (`app/Billing.php`) — same plan, no unintended change. **Rule**: if
`current_period_end` is still in the future, the new period is appended onto it (a renewal paid
early never costs the customer the remaining days); if the period has already lapsed, the new
period starts from now instead.

`ellsms_billing_records.purchase_type` (`'new'`/`'renewal'`/`'upgrade'`, default `'new'` — zero
behavior change for every pre-existing row) is how `payment_claim_and_activate_subscription()`
decides whether to route to `subscription_renew()` or keep its pre-existing plan-overwrite
behavior. Idempotent via the exact same `UNIQUE(idempotency_key)` guard on
`ellsms_subscription_events` every other transition in that file already relies on.

## Plan upgrade (FIN-8)

Uses `subscription_change_plan()`'s existing, unmodified immediate-mode behavior (STEP 27: full
new-plan-period price, no proration, no reset of usage counters) — `purchase_type` defaults to
`'new'`, which the activation function treats identically to a first-time subscription. This work
added invoice linkage and test coverage for that path; it changed no upgrade semantics.

## Refund framework, not policy (FIN-13)

This product has no automatic refund policy — `app/Billing.php`'s `subscription_cancel()` already
documented "never auto-refunds" before this work began, and
`docs/wallet-architecture.md`'s "Refund / compensation" section already documented that
`wallet_credit(..., 'refund', ...)` is the primitive a future refund UI would call, without one
existing yet. This work builds exactly that UI, as a framework:

`billing_refund_invoice()` (`app/Financial.php`):

- **Reason required.** An empty reason is refused outright.
- **Full-invoice-only, one time.** `UNIQUE(invoice_id)` on `ellsms_refund_events` makes a repeat
  refund attempt a safe, auditable no-op replay — never a second reversal. No partial/line-item
  refund exists (a deliberate scope limit: which items are refundable is a product decision this
  codebase has never made).
- **Only a `paid` invoice may be refunded.**
- **Credit-purchase invoices**: reverses the wallet credit via the existing `wallet_credit()`
  primitive **only if** the account still holds at least that much unspent balance. If the
  purchased credit has already been spent (in full or in part), the invoice is still marked
  `refunded` and the event is still recorded, but `wallet_reversed=0` and **nothing is subtracted
  from the wallet** — this is the explicit choice that avoids ever creating a negative balance from
  an already-spent purchase.
- **Subscription invoices**: **no automatic subscription action of any kind.** The organization's
  plan and period are left exactly as they are. Deciding what to do about the subscription itself
  (cancel? downgrade? nothing?) is left to an admin using the existing, unmodified
  `app/Billing.php` tools — inventing an automatic answer here would be exactly the "dangerous
  automatic behavior without a defined policy" this framework is built to avoid.
- **`ellsms_payments.status` is not widened.** Refund state lives entirely on the invoice, which
  already carried `'refunded'` in its own ENUM since FIN-1 — adding a `refunded` payment status
  would mean updating every existing piece of code that branches on payment status, for no
  additional guarantee the invoice's own status doesn't already provide.
- Admin-only (`require_admin()`), audited (`audit()` + `Logger::info('invoice.refunded', ...)`).

Real payment-provider refund (actually returning money through ZarinPal) is **not implemented** —
`payment_gateway_supports_refund('zarinpal')` returns `false` honestly; no such integration exists.
The fake gateway reports `true` as a capability flag only, never auto-invoked.

## Security

- **Tenant isolation**: `billing_invoice_by_id()`/`billing_invoices_for_organization()` scope every
  read by `organization_id` (or `user_id` for a personal, no-org caller) — an invoice from another
  organization returns `null`, proven directly
  (`tests/Integration/FinancialInvoiceTest.php::testInvoiceIsInvisibleToAnotherOrganization`,
  `FakePaymentGatewayE2eTest::testInvoiceFromOneOrganizationIsInvisibleToAnother`).
- **IDOR on the pay-a-pending-invoice action**: `public/invoices.php`'s `pay` action resolves the
  payment row from the ALREADY-ownership-checked invoice's own `payment_id`, never from a
  separately-posted payment id — a crafted request naming another organization's payment cannot be
  paid through this page.
- **Server-derived amounts everywhere.** No POST handler in this work reads an amount, a plan
  price, or a credit rate from request input — every amount traces back to a plan row, the
  configured `rial_per_credit` setting, or an already-issued invoice's own stored total.
- **CSRF**: every mutating POST handler added by this work (`public/buy-credit.php`,
  `public/billing.php`, `public/invoices.php`, `public/financial-admin.php`) calls `csrf_check()`
  first, matching the pre-existing pattern every other mutating page in this codebase already uses.
- **RBAC**: `public/financial-admin.php` is gated on `require_admin()` — platform-wide
  administration, never an organization permission (Invariant O, unchanged from Phase 13).
  `public/invoices.php` requires `Permissions::PAYMENTS_VIEW` for a non-admin caller, matching
  `public/buy-credit.php`'s existing gate.

## What was deliberately not built

- **No `ellsms_orders` table** — see the top-level DECISION above.
- **No `COMMERCE_ENABLED` master flag.** Credit purchase (`public/buy-credit.php`) is pre-existing,
  always-on functionality this work extended, not new functionality being introduced to an
  existing deployment — gating it now would be a behavior REGRESSION for existing customers, not a
  safety net. Subscription purchase/renewal (`public/billing.php`) is already fully inert whenever
  `BILLING_ENABLED=0` (the default), via `app/Entitlements.php`'s existing `billing_enabled()`
  checks — an existing flag already covers exactly the risk FIN's §44 describes. The two genuinely
  new pages (`public/invoices.php`, `public/financial-admin.php`) are read-mostly and admin-gated
  respectively; neither introduces a payment-initiation risk of its own that a flag would mitigate.
- **No real payment-provider refund.**
- **No partial/line-item refund policy.**
- **No proration engine** for plan upgrades — the existing full-price, no-proration rule (STEP 27)
  is unchanged.
