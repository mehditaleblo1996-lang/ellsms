# SMS pricing — admin-managed operators, providers, routes & tariffs

Before this feature, the price of an SMS in ELLSMS was a constant: **one credit per SMS segment**,
expressed as a literal `sms_parts($content) * $count` in four different places, with no way for an
operator to change it. This document describes what replaced it — an admin-configured catalog of
operators, prefixes, providers, routes and effective-dated tariffs, resolved through **one** pricing
engine that both the Cost Preview and every real send path call.

Source: `app/Sms/Pricing.php` · Admin UI: **مدیریت → تعرفه‌ی پیامک** (`public/sms-pricing.php`) ·
Schema: `db/migrations/2026_08_09_sms_pricing.sql`

---

## 1. The resolution pipeline

```
recipient phone ──normalize_msisdn()──► normalized MSISDN (98…)
                                             │
                                    longest configured prefix
                                             ▼
                                        OPERATOR  (or "unknown")
                                             │
sender + message type ──explicit assignment──► ROUTE ──► PROVIDER
                                             │
                              effective-dated (route, operator) tariff
                                             ▼
                             UNIT PRICE (integer millicredits/segment)
                                             │
                     segments × unit price, rounded up, per message
                                             ▼
                                     COST (whole credits)
```

Every step is a lookup. **Nothing compares prices, provider health, or delivery quality.** There is
no smart routing and no failover in this feature, by design.

---

## 2. Money

| Concept | Unit | Where |
|---|---|---|
| Configured tariff | **integer millicredits per segment** (1 credit = 1000) | `ellsms_sms_route_prices.price_per_segment_millicredits` |
| Cost of a message | **whole credits** | `sms_pricing_cost_for_segments()` |
| Wallet / ledger | **whole credits** (unchanged) | `ellsms_wallet_*` |
| Rial display | `rial_per_credit` × credits, **display only** | Settings |

No float ever participates in a cost computation. `1.25 credits` is stored as `1250`.

**Rounding happens once, per message, upward.** `cost = ceil(segments × unit_price / 1000)`.

Rounding per message rather than per batch is deliberate: it makes a recipient's cost a property of
that recipient alone, so splitting a job, retrying one row, or reporting on a single message all
reproduce the same number as the original acceptance. Rounding at the aggregate would make a row's
cost depend on which other rows it happened to be batched with — something no per-item
reconciliation could ever reproduce.

Currency is `credit`. The integrity check rejects any other value: the wallet ledger is
credit-denominated and nothing in this feature converts currencies.

---

## 3. Operators and prefixes

`ellsms_sms_operators` is an admin-managed catalog. `code` (`mci`, `mtn`, `rightel`, …) is the stable
internal identifier; `name` is a translatable display label and is **never** used as an identifier.

`ellsms_sms_operator_prefixes` holds the number prefixes each operator owns. An admin enters a prefix
in whatever form is natural (`0912`, `+98 912`, `98912`); it is canonicalized to the same
international form `normalize_msisdn()` produces (`98912`) and matched with a plain string
comparison.

**Matching is longest-prefix.** Given `09`, `091` and `0912`, the number `09121234567` matches
`0912`. Prefixes are **not** a pattern language — no wildcards, no regex. That is a deliberate
limitation: prefix matching is analyzable and safe, an admin-entered regex is neither.

Two ACTIVE rules can never claim the same prefix — the `uniq_active_prefix` index makes that
impossible, so longest-prefix matching is unambiguous even under concurrent admin edits. Archiving a
rule frees its prefix for another operator.

There is deliberately **no `unknown` operator row**. An unresolvable number is `operator_id = NULL`,
which is the same value a route DEFAULT tariff uses — so "an unknown number falls back to the route
default price" needs no special case anywhere.

### Number portability — read this

Prefix detection tells you **which operator the configured prefix table assigns this number to**. It
does **not** tell you which carrier serves the number today: Iranian numbers are portable, so an
0912 number can genuinely be on a different network.

Everything in this feature reports `operator_source = 'prefix'` for exactly that reason, and the
schema/UI avoid claiming a verified current carrier. Adding a real HLR/portability lookup later would
introduce a second `operator_source` value; **no external carrier lookup is implemented here.**

---

## 4. Providers and routes

`ellsms_sms_providers` is **pricing/configuration metadata only**. Gateway credentials are *not*
stored here and never will be — they stay in the existing secure integration layer
(`app/Backend/ApiClient.php` + `BACKEND_*`). Creating a provider or a route here **does not change
message transport at all**: every message still goes out through the same backend API it did before.
This feature configures what a send *costs*, not how it travels.

`ellsms_sms_routes` is a `(provider, message_type)` lane that tariffs hang off. A route belongs to
exactly one provider, its `code` is unique within that provider, and an ACTIVE route under an
ARCHIVED provider is unusable for new price resolution (the integrity check reports these).

### Message types

`promotional`, `transactional`, `otp`, `default`. `default` is the catch-all a route uses when it
serves every type.

The type is decided **server-side, from the send context**:

| Send path | Type |
|---|---|
| Direct send, bulk, campaign, scheduled | the configured default (`promotional`) |
| Auto-reply | `transactional` |
| 2FA login code | `otp` |

**A client can never state the message type.** `message_type` is on the API's forbidden-field list
(§9) precisely because a cheaper OTP tariff must not be reachable by simply claiming the type. If a
future phase permits it, it will be an explicitly allowlisted, policy-checked field.

---

## 5. Sender → route assignment

`ellsms_sender_routes` maps a sender to a route per message type. It is keyed by the **normalized
originator string**, not by `ellsms_numbers.id`, because this product has two legitimate kinds of
sender: a pooled `ellsms_numbers` row *and* a legacy free-text `ellsms_meta.originator` — both
accepted by `can_use_originator()`. A numbers-table foreign key would silently fail to price every
legacy-originator install.

Route selection order (`sms_pricing_route_for_sender()`), each step yielding at most one row:

1. explicit sender assignment for this exact message type
2. explicit sender assignment for message type `default`
3. the configured default route for this exact message type
4. the configured default route for message type `default`

Every step additionally requires the owning provider to be ACTIVE. Uniqueness at steps 1–2
(`uniq_active_sender_route`) and 3–4 (`uniq_default_route_per_type`) is enforced by the database, so
"which of the two?" can never arise.

---

## 6. Tariffs and effective dating

`ellsms_sms_route_prices` holds one row per `(route, operator-or-route-default)` per period.

**Precedence:**

1. exact `route + operator` price in effect
2. route **default** price (`operator_id IS NULL`) in effect
3. the explicit global legacy fallback, *if enabled* (§7)
4. otherwise **fail closed** — no guessed rate, ever

**Periods are half-open**: `[effective_from, effective_to)`, always in **UTC**. A row whose
`effective_to` equals the pricing instant has already ended. That is what makes the admin flow
("close the old period at T, open a new one at T") produce exactly one answer at T — never zero,
never two.

Changing a price **never rewrites history**. The admin page closes the current period and inserts a
new one, in one transaction. Overlapping active periods are impossible to create through the UI and
are reported as CRITICAL by the integrity check if they ever appear.

---

## 7. The legacy fallback (`sms_pricing_legacy_fallback`)

Setting key: `sms_pricing_legacy_fallback` (ellsms_settings), default **`1` = enabled**.

When enabled, a send that resolves to no configured tariff is priced at exactly **1 credit per
segment** — verbatim what this product charged before route pricing existed.

It exists because pricing is always-on and fails closed: applying the migration to an existing
install and then requiring a human to configure a tariff before any SMS could go out would be an
outage, not a migration.

It is **not** a hidden fallback:

* `make sms-pricing-status` prints whether it is on.
* `cron/sms-pricing-integrity-check.php` reports every route that depends on it.
* Every preview, snapshot and simulator output that used it carries `price_source = 'legacy_fallback'`.

An operator who has finished configuring real tariffs turns it off from the admin page. From then on
the engine fails closed instead.

---

## 8. Backward compatibility (the hard requirement)

Applying `db/migrations/2026_08_09_sms_pricing.sql` **must not change what anybody pays.**

The migration therefore seeds — unusually for this codebase, which normally keeps migrations
schema-only — the catalog that reproduces the pre-existing behavior exactly:

* operators + prefixes mirroring the old hard-coded `OPERATOR_PREFIX_MAP`
* one `legacy` provider
* one `default` route, marked as the default for message type `default`
* one route-default tariff: **1000 millicredits = 1 credit per segment**, effective from 2000-01-01

Every seeded row is ordinary, admin-editable configuration; none of it is special-cased anywhere in
the code. `tests/Integration/SmsPricingTest.php` asserts byte-identical legacy parity as a hard
acceptance criterion.

---

## 9. Who controls the price

**Nobody outside platform admin.**

* The admin page is guarded by `require_admin()` — the platform-admin guard, deliberately **not** an
  organization permission like `settings.manage`. These are global rates applying to every tenant, so
  even an organization Owner is refused (403).
* The public API **rejects** (422, not "ignores") `provider_id`, `provider`, `route_id`, `route`,
  `operator_id`, `operator`, `unit_price`, `price`, `price_per_segment`, `cost`, `estimated_cost` and
  `message_type` on every send and preview endpoint — see `api_reject_client_pricing_fields()`.
  Rejecting rather than dropping is deliberate: a client sending those has a wrong mental model of who
  owns pricing, and silence would let them keep it.
* Route selection is **not** exposed to the customer API in this phase, in any form.
* Every mutation on the admin page is audited (`sms_pricing.*` actions) with before/after values.

---

## 10. Price snapshots (`ellsms_sms_price_snapshots`)

At **acceptance** — money held, about to dispatch — each send writes one snapshot row per pricing
group, where a *group* is one distinct `(route, operator, unit price, message type, price source)`
decision. Every message inside a group was priced identically, so the group total is an exact sum,
never an average.

Written once, never updated:
`operator_*`, `provider_*`, `route_*`, `message_type`, `unit_price_millicredits`, `currency`,
`price_source`, `pricing_rule_id`, `priced_at`, `recipient_count`, `segment_count`,
`total_cost_credits`.

Updated at settlement only: `committed_cost_credits`, `status`. That is a genuinely different fact
from the price — how much of the accepted amount was actually spent once the gateway answered.

Snapshot writes are replay-safe: `UNIQUE (reference_type, reference_id, group_key)` with an
explicitly no-op `ON DUPLICATE KEY` clause, so a crashed worker replaying an acceptance keeps the
**first** accepted price. That is what makes an admin rate change unable to rewrite history.

**Historical reporting reads snapshots and never recomputes from the tariff tables** (see the cost
breakdown on the Analytics page).

### Bulk jobs

A bulk job's per-row accepted price is frozen **onto the row** (`ellsms_bulk_items.unit_price_millicredits`,
`price_cost_credits`, `price_operator_code`, `price_route_id`, `price_group_key`). The worker commits
exactly that number and never re-prices, so a retry — or a throttled row that sends three days later
— costs what the customer was quoted at acceptance.

Rows queued *before* this migration have NULL price columns and settle at exactly one credit per
segment, which is what they were accepted at.

---

## 11. Pricing timestamps: preview, bulk, scheduled

* **One pricing instant per acceptance.** A bulk job resolves every row against a single UTC
  timestamp, so a rate change halfway through a 50,000-row file cannot split the job across two price
  periods. `tests/Integration/SmsPricingTest.php` asserts exactly one `priced_at` per job.
* **A preview is an ESTIMATE, never a lock.** No send path in ELLSMS reserves money at preview time.
  `notes.price_mode` is `estimated` for that reason. If a rate changes between preview and
  confirmation, the web UI re-asks (`cost_preview_confirmation_check()` → `require_reconfirm`) rather
  than silently charging a different amount; the API always proceeds on current authoritative values
  and returns the accepted cost.
* **Scheduled sends are priced at EXECUTION, not at scheduling.** That is the pre-existing behavior of
  this product, retained deliberately rather than quietly changed. A schedule created today and run
  next month is priced at next month's tariff. The occurrence's price is snapshotted at that
  execution, and a *retry* of that occurrence reuses the snapshot rather than re-resolving.

---

## 12. Quota vs. cost — different units, on purpose

| | Unit | Enforced by |
|---|---|---|
| **Billing cost** | credits, derived from **segments** × tariff | wallet (`app/wallet.php`) |
| **Usage quota** | **messages** (one per recipient) | Phase 13 meters (`app/Entitlements.php`) |

This feature changes **only** the cost side. Quota semantics are untouched: a 3-segment message to
one recipient consumes 1 message of quota and (at 1 credit/segment) 3 credits. Do not conflate them.

---

## 13. Operations

```
make sms-pricing-integrity-check           # configuration audit; non-zero exit on critical findings
make sms-pricing-status                    # what is in effect right now
make sms-pricing-status SENDER=5000435800  # ...including what THAT sender would actually resolve to
make sms-pricing-status-json               # machine-readable
make sms-price-simulate PHONE=09121234567 SENDER=5000435800 SEGMENTS=2
```

`sms-pricing-integrity-check` is also wired into `make release-preflight`, because a pricing
misconfiguration fails closed the moment a release goes live. It checks: duplicate operator codes,
ambiguous/malformed/orphan prefixes, active routes under archived providers, ambiguous default
routes, ambiguous sender assignments, overlapping or zero or foreign-currency tariff periods, routes
with no usable rate, drift in the uniqueness slot columns, snapshots settled for more than they were
accepted at — plus a **static scan** that fails if fixed-price segment arithmetic reappears in a send
path. It never auto-fixes anything.

---

## 14. Troubleshooting

**"A send is refused with `pricing_unavailable`."**
Run `make sms-price-simulate PHONE=<the number> SENDER=<the sender>`. It prints the whole resolution
chain and the exact reason. Usually one of:

| Reason | Meaning | Fix |
|---|---|---|
| `route_unavailable` | no active route for this sender/type, and no default route | assign a route, or mark one route as the default |
| `route_price_missing` | the route exists but has no tariff for this operator and no route default | add a route-default tariff |
| `operator_unknown_no_default_price` | the number matched no prefix, and the route has no default tariff | add a route-default tariff (it covers unknown numbers by design) |

All three are impossible while the legacy fallback is enabled — if you are seeing them, it has been
turned off deliberately.

**"An admin changed a tariff but sends are still priced at the old rate."**
The catalog is cached per process for `SMS_PRICING_CACHE_TTL_SECONDS` (default 30s). The admin page
drops its own cache immediately; the long-running worker picks the change up within the TTL. Set it
to `0` to disable caching entirely.

**"The preview and the bill disagree."**
They cannot disagree at the same instant — both call `sms_pricing_price_messages()`. They *can*
differ across time if a tariff changed in between, which is exactly what the reconfirmation check
surfaces (§11).

**"A historical report shows a different cost than the current tariff."**
That is correct and intentional. Reports read snapshots (§10).

---

## 15. Admin workflow (typical first configuration)

1. **Operators** — review the seeded carriers; correct names, add any missing operator.
2. **Prefixes** — verify the seeded blocks against the current regulator assignments; add/archive as
   needed. Ambiguity is impossible to create; the DB refuses it.
3. **Providers** — create a provider per commercial supplier. Metadata only; no credentials.
4. **Routes** — create a route per `(provider, message type)` you actually price differently. Mark
   exactly one route per message type as the default.
5. **Prices** — add a **route default** tariff for every active route first (it covers every operator
   *and* unknown numbers), then add operator-specific overrides where the supplier's rates differ.
6. **خط ← مسیر** — assign specific senders to specific routes where they should not use the default.
7. Run `make sms-pricing-integrity-check` and `make sms-pricing-status`.
8. Verify with `make sms-price-simulate` for a number on each operator.
9. Only once every route is genuinely priced: **turn off the legacy fallback** so pricing fails closed.

---

## 16. What this feature deliberately does NOT do

* No cheapest-route or quality-based routing.
* No automatic provider failover or health-based switching.
* No change to message transport (Invariant I) — configuring a provider here sends nothing anywhere.
* No external carrier/HLR lookup.
* No route selection exposed to the customer API.
* No change to the wallet as the source of truth, to the queue, or to quota semantics.
