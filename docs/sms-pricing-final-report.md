# SMS pricing — final report

**Status: PASS**

Admin-managed SMS operators, providers, routes, sender-route assignments and effective-dated
tariffs, resolved through one central pricing engine that both the Cost Preview and every real send
path call, with immutable per-send price snapshots.

Feature guide: [`docs/sms-pricing.md`](sms-pricing.md)

---

## 1. What changed, in one paragraph

The price of an SMS was a constant — one credit per segment — written as a literal
`sms_parts($content) * $count` in `dispatch_message()`, `dispatch_message_retryable()`,
`bulk_queue_job()` and the cost estimator, with no way for an operator to change it. All four now
call `sms_pricing_price_messages()` (`app/Sms/Pricing.php`), which resolves an operator from
admin-configured prefixes, a route from an explicit sender assignment or the single configured
default, and an effective-dated tariff for that `(route, operator)` pair. Applying the migration
changes nobody's bill: it seeds a catalog that reproduces the previous behavior exactly.

---

## 2. Validation

### PHP lint
`make lint` — **224 files, 0 parse errors.**

### Unit tests
`vendor/bin/phpunit` — **300 tests, 730 assertions, 0 failures, 0 errors, 0 skipped.**
(23 new: `tests/Unit/SmsPricingTest.php`, plus the rewritten pricing-block tests in
`tests/Unit/CostEstimatorTest.php`.)

### Integration tests (real MySQL 8, clean database)
`vendor/bin/phpunit -c phpunit.integration.xml` — **345 tests, 1582 assertions, 0 failures,
0 errors, 0 skipped.** (39 new across three classes; 306 before this feature.)

### Pricing tests

| Area | Result |
|---|---|
| Operator CRUD + archive semantics | PASS — archiving stops matching immediately |
| Prefix CRUD, normalization, validation | PASS — digits only, no pattern language |
| Longest-prefix resolution | PASS — over real rows and as a pure rule; order-independent |
| Ambiguous prefix rejection | PASS — the database itself refuses two active rules on one prefix |
| Provider CRUD | PASS — archived provider makes its routes unusable for new pricing |
| Route CRUD | PASS — DB refuses a second active default route per message type |
| Sender→route mapping | PASS — explicit assignment beats default; selection is deterministic |
| Effective-dated price | PASS — correct period per pricing instant, half-open boundary |
| Route-default fallback | PASS — covers every operator *and* unknown numbers |
| Missing-price failure | PASS — fails closed with a per-recipient reason |
| Legacy fallback | PASS — explicit, admin-visible, admin-disableable |
| Price snapshot | PASS — written at acceptance, replay-safe, settlement separate |
| Historical immutability | PASS — send at X, change to Y, old cost stays X, new send uses Y |
| Same-second rate replacement | PASS — two non-overlapping, gapless periods |

### Cost Preview

| Surface | Result |
|---|---|
| Direct send (`public/send.php`) | PASS — route-aware, unpriced-recipient card |
| Bulk / gradual (`public/new-send.php`) | PASS |
| Campaign | PASS |
| API `POST /messages/preview`, `POST /bulk-jobs/preview` | PASS |
| Operator breakdown | PASS — labels from the configured catalog, never hard-coded |
| Provider/route breakdown | PASS — behind an expandable "جزئیات مسیر ارسال" |
| Preview↔actual parity | PASS — both call the same function; asserted numerically |
| Full pre-existing Cost Preview suite | PASS — including the hard zero-mutation criterion |

### Concurrency

| Scenario | Result |
|---|---|
| Rate change during acceptance | PASS — each accepted send resolves to exactly ONE price version and ONE pricing instant |
| Effective-price count during churn | PASS — exactly one price in effect at every instant across 8 changes |
| Wallet race, unaffordable sends | PASS — neither reserves, balance untouched, no snapshot |
| Wallet race, one affordable | PASS — exactly one wins, balance never negative, snapshot matches what was charged |
| Bulk pricing timestamp | PASS — one `priced_at` per job acceptance |

Run in separate OS processes with their own MySQL connections
(`tests/Integration/SmsPricingConcurrencyTest.php`).

### Security

| Check | Result |
|---|---|
| Platform-admin enforcement | PASS — real HTTP; admin 200, organization owner 403 |
| Anonymous access | PASS — 302 to login, zero mutation |
| Organization user POSTing every mutating action directly | PASS — 403 on all nine, zero mutation |
| IDOR with real existing route/price ids | PASS — 403, seeded tariff untouched |
| Only one writer to the catalog | PASS — static scan finds no other page writing pricing tables |
| Client-supplied price/route/operator/message_type via API | PASS — 422 rejected, not silently ignored |

### Regressions

| Suite | Result |
|---|---|
| Phase 13 billing/quota | PASS — quota semantics unchanged (messages, not segments) |
| Phase 12 API/webhook | PASS |
| Phase 11 backup/restore | PASS — DR test extended to seed and verify pricing tables + a snapshot |
| Phase 10 security | PASS |
| Phase 9 metrics | PASS |
| Phase 8 boundaries | PASS — `backend-boundary-check` clean; no backend-owned table touched |
| Phase 7 RBAC | PASS |
| Phase 6 tenancy | PASS |
| Phase 4 queue | PASS |
| Phase 3 wallet | PASS — reservation/commit/release model unchanged |

### Operational commands

| Command | Result |
|---|---|
| `sms-pricing-integrity-check` | exit 0 — zero critical, zero warnings |
| `sms-pricing-status` | OK — prints the seeded catalog and effective rates |
| `sms-price-simulate` | OK — full resolution chain for known and unknown numbers |
| `config-check` | exit 0 (2 pre-existing environment warnings) |
| `predeploy-check` | exit 0 |
| `production-integrity-check` | exit 0 |
| `backend-boundary-check` | PASS |

### Docker & live smoke

Image builds cleanly from `docker/Dockerfile` (no new dependency; no Dockerfile change needed).

A real end-to-end run against a real MySQL and a real PHP server serving the real `public/` —
platform admin creates a provider → route → sender assignment → 2.5 credit/segment tariff over HTTP
through the actual admin page (CSRF included), preview reflects it, a send acceptance reserves the
wallet and writes a snapshot, the admin replaces the tariff, the historical cost does not move, a new
send uses the new rate, and an organization user is refused with zero mutation. **All 26 checks pass.**

---

## 3. Defects found and fixed during this work

**1. Generated columns made backups unrestorable.** The uniqueness slots (`active_prefix`,
`default_slot`, `active_slot`, `operator_slot`) were first implemented as STORED generated columns —
the same technique `ellsms_subscriptions.effective_organization_id` already uses.
`RestoreDisasterRecoveryTest` failed on a REAL restore: the mariadb-client `mysqldump` this project
ships emits generated columns as ordinary data, and MySQL then rejects the resulting INSERT. A table
whose generated column holds no rows never trips it, which is why it had not surfaced before; this
feature's seeded catalog has rows on day one. They are now plain columns maintained by the admin page,
with the database still enforcing the uniqueness and the integrity check auditing for drift.

*Pre-existing, NOT fixed here (out of scope, reported deliberately):* `ellsms_subscriptions` has the
same STORED generated column. It is harmless while that table is empty, but a production install with
at least one subscription row will produce a backup that cannot be restored by this toolchain. This
is a real Phase 13 backup/restore hazard and should be scheduled as its own change.
**Update 2026-08-10: closed** by `db/migrations/2026_08_10_td070_subscription_restore_safety.sql` —
see `docs/td-070-restore-safety-closure.md`.

**2. Two rate changes within one second silently rolled back.** `effective_from` has one-second
granularity and `(route, operator, effective_from)` is UNIQUE, so an admin correcting a tariff
immediately after entering it collided on the index and the whole change rolled back. Found by the
live end-to-end run, not by theory. `sms_pricing_next_effective_from()` now advances to the first free
instant, keeping periods non-overlapping *and* gapless — a regression test covers both sides of the
boundary.

---

## 4. Deliverables

### New files (13)

| Path | Purpose |
|---|---|
| `app/Sms/Pricing.php` | the pricing engine |
| `db/migrations/2026_08_09_sms_pricing.sql` | 7 tables, 5 additive bulk-item columns, legacy-parity seed |
| `public/sms-pricing.php` | platform-admin UI (operators/prefixes/providers/routes/senders/prices) |
| `app/views/cost_preview_unpriced.php` | the unpriced-recipient refusal card |
| `cron/sms-pricing-integrity-check.php` | configuration audit + static legacy-arithmetic scan |
| `cron/sms-pricing-status.php` | effective configuration, text or JSON |
| `cron/sms-price-simulate.php` | read-only "what would this cost, and why" |
| `tests/Unit/SmsPricingTest.php` | 23 pure-rule tests |
| `tests/Integration/SmsPricingTest.php` | 29 real-MySQL tests |
| `tests/Integration/SmsPricingSecurityTest.php` | 6 real-HTTP authorization tests |
| `tests/Integration/SmsPricingConcurrencyTest.php` | 4 cross-process tests |
| `tests/fixtures/sms_pricing_concurrent_worker.php` | subprocess for the above |
| `docs/sms-pricing.md` + this report | documentation |

### Modified

`app/backend.php` (all send paths), `app/Cost/MessageCostEstimator.php`, `app/bootstrap.php`
(loader + `detect_operator()`), `app/Api/Request.php`, `app/Api/Handlers/{Preview,Messages,BulkJobs}.php`,
`app/views/{header,cost_preview}.php`, `public/{send,new-send,analytics}.php`, `cron/release.php`,
`Makefile`, `.env.example`, `tests/Integration/{RestoreDisasterRecoveryTest,CostPreviewApiTest}.php`,
`tests/Unit/CostEstimatorTest.php`, and `docs/{cost-preview,architecture,billing-operations,production-runbook,public-api}.md`.

### Migrations

One: `db/migrations/2026_08_09_sms_pricing.sql`. Idempotent. Creates
`ellsms_sms_operators`, `ellsms_sms_operator_prefixes`, `ellsms_sms_providers`, `ellsms_sms_routes`,
`ellsms_sender_routes`, `ellsms_sms_route_prices`, `ellsms_sms_price_snapshots`; adds five nullable
columns to `ellsms_bulk_items`; seeds the legacy-parity catalog. Modifies no pre-existing row.

### Environment variables

One new, optional: **`SMS_PRICING_CACHE_TTL_SECONDS`** (default `30`) — how long a process may reuse
a loaded catalog, so the long-running worker picks up an admin's change without a restart. There is
deliberately no master switch and no per-price env var: tariffs are database configuration.

### Breaking changes

**None for operators or customers.** An install that applies the migration prices identically to
before.

Three interface changes worth noting for anyone reading the code:

1. `dispatch_message_raw()` returns a 7th element (the accepted destinations). Additive — every
   existing call site destructures fewer elements and PHP ignores the rest.
2. `dispatch_message()`, `dispatch_message_retryable()`, `bulk_queue_job()`, `estimate_message_cost()`,
   `estimate_bulk_cost()` and `estimate_campaign_cost()` each take one optional trailing
   `$messageType`. All existing calls are unaffected.
3. The preview `pricing` block changed shape: `currency` is now `credit` (the unit the cost is in) with
   `rial_currency: "IRR"` alongside; `credits_per_segment` is `null` when operators differ in rate;
   `groups`, `message_type` and the min/max unit price are new; `estimator_version` is `"2"`.
   `cost_pricing_snapshot()` was replaced by `cost_pricing_block()`.
   The API additionally now **rejects** client-supplied pricing/routing/message-type fields with 422
   where they were previously ignored.

---

## 5. Acceptance criteria

- [x] operators are admin-configurable
- [x] prefixes are admin-configurable
- [x] longest-prefix resolver works
- [x] providers are admin-configurable
- [x] routes are admin-configurable
- [x] sender-route relationship is deterministic
- [x] operator/route pricing is admin-configurable
- [x] effective price history exists
- [x] legacy 1-credit behavior is preserved after migration
- [x] central pricing service exists
- [x] MessageCostEstimator uses central pricing
- [x] actual send uses same pricing source
- [x] price snapshots are immutable
- [x] historical costs do not change after rate changes
- [x] unknown pricing fails closed
- [x] public API cannot control price/route
- [x] organization users cannot modify pricing
- [x] bulk lookup avoids N+1 DB queries
- [x] pricing integrity tool exists
- [x] full Cost Preview suite remains green
- [x] full previous regression suite remains green

---

## 6. Explicitly not implemented

No cheapest-route selection, no quality-based routing, no automatic failover, no provider-health
routing, no route selection exposed to the public API, no Telegram, no external carrier/HLR lookup,
no queue redesign, no change to the wallet as source of truth, no new accounting platform.
