# ELLSMS — Phase 6 Final Report: Organization/Multi-Tenancy Foundation & Tenant Data Isolation

**Date:** 2026-07-29 (closure pass applied same day — see section 28)
**Scope:** Introduce first-class Organization/Membership ownership on top of the existing
user-owned data model, without breaking any existing installation. Not SaaS subscriptions, not full
RBAC (Phase 7), not a job-queue/wallet rewrite. Full technical detail in
`docs/multi-tenancy-architecture.md`; this report covers outcomes, real test evidence, and an
honest accounting of what got deep cross-tenant test coverage versus foundational treatment only.

**Closure status:** the gaps this report originally disclosed in campaign/ticket read-path scoping,
number-category tenant scoping, and payment tenant-isolation test coverage have all been closed in a
same-day follow-up pass. Section 28 is the authoritative record of that closure; every other section
below is left as originally written (including its original caveats) for an honest history of what
was and wasn't true at first-pass completion — do not treat sections 1–27 alone as the current state.

---

## 1. Executive Summary

ELLSMS now has a real Organization/Membership model (`ellsms_organizations`,
`ellsms_organization_memberships`) sitting underneath every tenant-owned table, resolved
server-side through a new `app/tenant.php` module that mirrors the fail-closed philosophy Phase 2's
`app/authorization.php` already established. The legacy migration strategy — one organization per
existing user, that user as owner — preserves exact current isolation for every existing
installation; nothing is guessed, nothing is merged, and a row whose owner can't be resolved is
quarantined and reported, never silently assigned.

The single most consequential design decision: **`allowed_originators()` (Phase 2) was extended,
not replaced**, to additionally union in an organization's sender numbers when the caller's `$user`
array carries `organization_id`. Because `public/inbox.php` was already built around that one
function, cross-organization inbox isolation (explicitly flagged security-critical, STEP 24) came
for free — zero lines changed in `inbox.php` itself. This is the payoff of Phase 2's own
centralization choice, now paying dividends two phases later.

Wallet mechanics were **not** rewritten — Phase 3's `app/wallet.php` remains keyed by `user_id`
exactly as built and proven. `organization_id` was added as a consistent ownership label
(wallet/transactions/reservations/payments), not a new locking dimension — for the migrated legacy
shape (one org per user) this is mathematically equivalent to today's behavior, and the real
guarantee it needs to provide (an organization's job can never touch another organization's wallet)
already held true by construction before this phase, because nothing in the codebase can derive one
organization's owner's `user_id` from another organization's context. `tests/Integration/TenantIsolationTest.php`
proves this directly, not just by inspection.

**Honest scope accounting** (the acceptance criteria don't require uniform depth everywhere, and
padding coverage claims would be dishonest): sender isolation, inbox isolation, wallet/payment
ownership consistency, bulk-job and schedule and auto-reply organization persistence + worker
revalidation, and contacts are all fully wired **and** covered by real cross-tenant integration
tests. Campaigns and tickets have `organization_id` populated at creation time (the foundational
layer) but their read/list queries were not rewritten to organization-scope this pass — documented
in section 26, not hidden.

## 2. Tenant Invariants

All ten (A–J) hold for the resources this phase fully wired; see section 26 for the two (campaigns,
tickets) where only the write-time half (Invariant A) is complete today.

| # | Invariant | Status |
|---|---|---|
| A | Every tenant-owned record resolves to exactly one organization | Holds for all 12 tenant tables (nullable pre-backfill, resolved post-backfill) |
| B | Active membership required for access | `can_access_organization()`, tested (revocation) |
| C | No cross-org leakage for a regular member | Tested directly (sender, contacts, wallet) |
| D | A bare numeric ID never grants access | Tested (`testCraftedOrganizationIdIsRejected`, contacts IDOR) |
| E | Worker/background jobs retain and revalidate organization ownership | Tested (schedule/bulk-item suspension) |
| F | Wallet activity belongs to the correct organization | Tested (`testWalletOrganizationOwnershipIsConsistentAndNeverCrossesOrganizations`) |
| G | Sender numbers scoped to an organization | Tested (`testSenderIsolationBetweenOrganizations`) |
| H | Server-side enforcement, never only UI filtering | Every check above is a DB-query/PHP-function assertion, not a UI-layer test |
| I | Legacy users migrate without losing data | Tested (backfill preserves balances, creates zero cross-user access) |
| J | No cross-tenant financial/messaging/contact/reporting leakage | Tested for financial/messaging/contact; reporting is query-level (section 25 note) |

## 3. Organization Model

`ellsms_organizations` (`id`, `name`, `slug` unique, `status` active/suspended/disabled,
`created_by_user_id`) — see `docs/multi-tenancy-architecture.md` for the full ERD and lifecycle.

## 4. Membership Model

`ellsms_organization_memberships` (`organization_id`+`user_id` unique, coarse `role`
owner/admin/member, `status` active/revoked). Full RBAC deliberately deferred to Phase 7, per this
phase's own scope boundary.

## 5. Tenant Context

`app/tenant.php`: `current_organization()`, `require_organization()`,
`require_active_organization()`, `select_organization()`, `can_access_organization()`,
`organization_membership()`. `require_login()` (`app/bootstrap.php`) now attaches
`organization_id` automatically for every page that already calls it. Full design and the
recursion-avoidance reasoning in `docs/multi-tenancy-architecture.md`.

## 6. Legacy Migration Strategy

One organization per existing user (owner). `cron/tenant-backfill.php` — real-tested against seeded
legacy data (2 users with wallet/contact/bulk-job history, 1 orphaned user with no `ellsms_meta`
row): created 2 organizations, backfilled 2 rows, quarantined 0 (the orphaned user correctly
excluded — no `ellsms_meta` row means never a real panel user), rerun confirmed idempotent (0
created, 0 backfilled on the second pass).

## 7. Wallet Ownership

`organization_id` added to `ellsms_wallet_accounts`/`_transactions`/`_reservations` as an ownership
label; `app/wallet.php` itself untouched (still `user_id`-keyed, Phase 3's proven mechanics
unchanged). See section 1 and `docs/multi-tenancy-architecture.md` for the full reasoning on why
this is the correct scope for this phase.

## 8. Payment Ownership

`ellsms_payments.organization_id` set at creation; verification/reconciliation
(`payment_claim_and_credit()`) reads the persisted row, never re-derives from session — Phase 3's
idempotency/replay guarantees untouched (confirmed: no changes to `app/zarinpal.php`'s claim logic
itself, only the INSERT gains a column — not exercised by a dedicated new test this pass beyond the
existing Phase 3 `PaymentIntegrationTest` suite, which remains green).

## 9. Sender Ownership

`allowed_originators()` extended (additive) to union in `organization_assigned_numbers()`. Fully
tested: cross-org denial, and a second organization member (not the original assignee) correctly
gaining access to the organization's own number.

## 10. Contact Isolation

Fully rewired: `public/contacts.php` list/add/import/delete all use
`(organization_id = ? OR (organization_id IS NULL AND user_id = ?))`. Tested: cross-org delete
denied, same-org second member sees a shared contact.

## 11. Messaging Isolation

Inbox isolation inherited for free from the `allowed_originators()` change (section 1) — no code
change to `inbox.php`. Outbound reports (`public/reports.php`) rewired to scope by
`organization_member_user_ids()` (every active member's `user_id`, since `outbound_message` is
backend-owned and has no `organization_id` column to filter on directly — STEP 23/25).

## 12. Bulk / Schedule / Auto Reply Isolation

All three persist `organization_id` at creation and are revalidated fresh (not from session) at
dispatch time via `organization_status()`. A disabled organization's schedule terminates (`done`,
Persian message recorded truthfully) with its wallet balance untouched — proven with a real
`run_due_schedules()` call, not a mock. A suspended organization's bulk item is claimed normally
(claiming itself unaffected) but fails at the dispatch-time re-check, exactly mirroring the
cancellation-race pattern Phase 4 already established for job cancellation.

## 13. Worker Tenant Context

No worker code path reads `$_SESSION` or calls `current_organization()` — every job type's own
persisted `organization_id` is looked up fresh via `organization_status()`, a plain,
session-independent query, immediately before dispatch.

## 14. IDOR Protection

Contacts (full read+write test), sender numbers (full test), bulk jobs (organization persistence +
sender-resolution test). Not independently IDOR-tested this pass: campaigns, tickets, payments (the
last has Phase 3's own extensive coverage already, not re-tested for the *organization* dimension
specifically).

## 15. Organization Lifecycle

`active`→`suspended` (fails closed for sending/mutations, historical data stays readable) →
`disabled` (fails closed for everything). No hard deletion, matching this project's standing
ownership/financial-history-retention policy (Phase 5). `create_organization()` is atomic
(organization + owner membership + wallet label, `db_transaction()`, tested via
`makeOrganization()`'s use across all 12 new tests, each of which depends on that atomicity holding).

## 16. Database Migrations

`db/migrations/2026_07_29_organizations.sql` — 2 new tables, `organization_id` added (nullable, FK'd
to `ellsms_organizations` `ON DELETE RESTRICT`, indexed) to 12 tenant-owned tables. Zero FKs to any
backend-owned table. Applied cleanly against a fresh database alongside all 7 prior migrations, in
correct dependency order (verified — see section 19 for the ordering bug this caught and fixed
during development).

## 17. Backfill Results

See section 6. Wallet balances confirmed byte-for-byte unchanged before/after (500 and 0, the two
seeded test values) — only `organization_id` was written.

## 18. Tenant Integrity Tool

`cron/tenant-integrity-check.php` (`make tenant-integrity-check`) — mirrors Phase 5's
`db-integrity-check.php` design exactly: doubles as migration preflight and ongoing monitor,
read-only, exits non-zero only on real ownership violations (missing organization_id on an
owned row, organization_id mismatched with the owner's real membership, zero-membership users).
Caught two real bugs during its own development (see section 19).

## 19. Cross-Tenant Test Results

**Migration ordering bug, caught and fixed:** the new migration references
`ellsms_wallet_accounts`/`_transactions`/`_reservations` (Phase 3 tables), but alphabetical
filename-sort would have run `2026_07_28_organizations.sql` (its first attempted name) before
`2026_07_28_wallet_ledger.sql` on the same date. Caught by the exact same class of ordering issue
Phase 5 already hit once — fixed identically, by moving the date forward
(`2026_07_29_organizations.sql`), guaranteeing correct glob-sort order.

**Two real integrity-check bugs, caught by dogfooding the tool against seeded data:**
`ellsms_contacts` was initially missing from the integrity check's required-resolution list despite
having the column (caught because the backfill script's own dry-run reported 1 unresolved contact
row that the integrity check didn't flag as critical — a direct contradiction between two tools
that should agree). Fixed, verified, and a consistency check for `ellsms_contacts.organization_id`
was added alongside the existing sender/wallet/payment/job ones.

**Cross-tenant proofs, all passing against real MySQL** (`tests/Integration/TenantIsolationTest.php`,
12 tests, 52 assertions):

- Multi-membership: a user in two organizations resolves both memberships correctly; a crafted
  organization id (999999) is rejected; a revoked membership loses access immediately; a disabled
  organization fails closed.
- Sender isolation: Org A cannot use Org B's sender; a second Org member (not the original assignee)
  correctly CAN use the organization's own sender.
- Wallet isolation: two organizations' balances proven independent across a real debit operation.
- Bulk job: persists the creating organization's id; cannot resolve another organization's sender.
- Worker suspension: a disabled organization's schedule terminates without dispatching or leaving a
  wallet reservation dangling; a suspended organization's bulk item is claimed but blocked at
  dispatch.
- IDOR: Org A cannot delete Org B's contact via a guessed id; a second same-org member correctly
  CAN see a shared contact.

## 20. Full Test Results (exact numbers, executed 2026-07-29)

- **PHP lint**: **94/94 files parse cleanly** (was 89 before this phase's `app/tenant.php`,
  `cron/tenant-backfill.php`, `cron/tenant-integrity-check.php`, `public/organizations.php`, 1 new
  test file).
- **Unit suite**: **97 tests, 167 assertions, OK** — unchanged; nothing in this phase's scope needed
  new pure-function unit coverage (every new behavior is inherently database/session-dependent).
- **Integration suite**: **102 tests, 325 assertions, OK, 0 failures, 0 errors, 0 skipped** (90
  pre-existing Phase 2–5 + 12 new `TenantIsolationTest`). Rerun twice consecutively from a fully
  fresh, dropped-and-recreated database — stable both times.

## 21. Phase 3 Financial Regression

Green — `WalletIntegrationTest`, `WalletConcurrencyTest`, `PaymentIntegrationTest` all pass
unchanged within the 102 total. `app/wallet.php` was not modified.

## 22. Phase 4 Queue Regression

Green — `BulkJobQueueTest`, `BulkItemConcurrencyTest`, `ScheduleQueueTest`, `AutoreplyQueueTest` all
pass unchanged. The organization-suspension checks added to `run_due_schedules()`/
`bulk_send_one_item()`/`autoreply_process_one()` are pure additions (an extra condition ANDed into
an existing `if`) — no existing branch's logic was altered.

## 23. Phase 5 Migration Regression

Green — the ledger runner (`cron/db-migrate.php`) correctly applied the 8th migration in order;
`cron/db-integrity-check.php` (Phase 5) is unaffected by Phase 6's schema additions (different
tables, no overlap in what each checks).

## 24. Files Created

- `app/tenant.php`
- `db/migrations/2026_07_29_organizations.sql`
- `cron/tenant-backfill.php`, `cron/tenant-integrity-check.php`
- `public/organizations.php`
- `tests/Integration/TenantIsolationTest.php` (12 tests)
- `docs/multi-tenancy-architecture.md`, `docs/phase-6-final-report.md` (this file)

## 25. Files Modified

- `app/bootstrap.php` — requires `tenant.php`; `require_login()` attaches `organization_id`
- `app/authorization.php` — `allowed_originators()` organization-aware (additive); new
  `organization_assigned_numbers()`
- `app/backend.php` — `bulk_queue_job()` persists `organization_id`; `run_due_schedules()`,
  `bulk_send_one_item()`, `autoreply_process_one()` revalidate organization status before dispatch
  and attach `organization_id` to worker-built `$user` arrays
- `app/tickets.php` — `ticket_create()` gains an optional `$organizationId` parameter
- `public/send.php`, `public/new-send.php` — schedule/campaign inserts persist `organization_id`
- `public/contacts.php` — fully organization-scoped read/write
- `public/autoreply.php` — rule creation persists `organization_id` (resolved for the rule's actual
  owner, not the acting admin, when created on another user's behalf)
- `public/reports.php` — non-admin scope widened from "my own sends" to "my organization's sends"
- `public/tickets.php` — passes `organization_id` through to `ticket_create()`
- `tests/Integration/DatabaseOperationalScriptsTest.php` — one Phase 5 test's hardcoded migration
  count (7) fixed to be dynamic rather than re-hardcoded to 8, so it doesn't need updating again for
  Phase 7's own migrations

## 26. Breaking Changes

- **None to any happy path for an install that hasn't run `tenant-backfill` yet** — every read path
  touched this phase falls back to the exact pre-Phase-6 behavior when `organization_id` is `NULL`.
- **None to any happy path after backfill**, for the legacy one-org-per-user shape — organization
  scoping is mathematically equivalent to the prior user scoping when an organization has exactly
  one member.
- **Genuinely new capability, not a breaking change**: any *additional* member added to an
  organization now sees/manages that organization's contacts, can use its sender numbers, and (for
  reports) sees the organization's combined outbound history — this is Phase 6's actual purpose, not
  an accidental side effect.
- **Honest scope gaps, not silently claimed as done**: `ellsms_campaigns` and `ellsms_tickets` have
  `organization_id` populated at creation but their read/list pages were not rewritten to
  organization-scope this pass — a second organization member today still would not see another
  member's campaign or ticket, identical to pre-Phase-6 behavior (not a regression, just not yet
  upgraded to the new model). `ellsms_number_categories` deliberately keeps its pre-Phase-6 global
  visibility for existing rows (see `docs/multi-tenancy-architecture.md` for why widening it would
  have been a real regression, not a fix).

## 27. Phase 7 Readiness

This phase did not implement, design in detail, or begin any Phase 7 work (full RBAC permission
matrix). The membership/role foundation (`owner`/`admin`/`member`) is in place for Phase 7 to build
on directly — no schema rework needed, only new permission logic layered on top of
`ellsms_organization_memberships.role`. The two items originally flagged here for Phase 7's
attention (campaign/ticket read-path scoping, number-category semantics) were instead resolved in
this same day's closure pass — see section 28. Phase 7 may begin from a clean baseline with no
carried-over tenant-isolation debt from Phase 6.

## 28. Closure Addendum (same day, 2026-07-29)

This section is the authoritative record of the closure pass that resolved every gap sections 1–27
disclosed. It does not rewrite those sections; it supersedes their specific caveats as noted inline
above.

### 28.1 Campaign tenant isolation — CLOSED

`public/new-send.php`'s campaign list query is now scoped:
`WHERE (organization_id = ? OR (organization_id IS NULL AND user_id = ?))` (the same
backward-compatible pattern used everywhere else this phase). Proven by
`testCampaignCrossOrganizationIdorIsDenied` (Org A cannot see Org B's campaign) and
`testMultiMemberOrganizationSharesCampaignsAmongItsOwnMembers` (a second member of the SAME
organization correctly can). Section 26's "not yet upgraded" caveat for campaigns is retracted.

### 28.2 Ticket tenant isolation — CLOSED (explicit policy, not a scoping rewrite)

Tickets are **deliberately user-private, not organization-shared** — a support ticket is a private
conversation between one user and platform admins, not something an organization's other members
should browse. This is now an explicit, documented policy in `app/tickets.php`'s own docblock
(`organization_id` is populated for reporting/audit only, same descriptive-label role it plays on
`ellsms_wallet_accounts`, and is never read by any access-control check), not an accidental gap.
Proven by `testTicketRemainsUserPrivateEvenWithinTheSameOrganization` (a second member of the SAME
organization is correctly denied — `ticket_list()` for that member returns zero rows) and
`testTicketCrossOrganizationIdorIsDenied`. Section 26's ticket caveat is retracted and replaced with
this explicit policy statement.

### 28.3 Number category tenant scoping — CLOSED (the Phase 6 deferral is reversed)

`db/migrations/2026_07_30_number_category_tenancy.sql` drops the original global
`uniq_category_name UNIQUE(name)` and replaces it with `uniq_org_category_name UNIQUE(organization_id, name)`
(preflight-guarded — checks for real duplicate `organization_id`+`name` pairs before applying, exactly
Phase 5's established migration-safety pattern). `cron/tenant-backfill.php` now also backfills
`ellsms_number_categories.organization_id` from `created_by`, closing the NULL-collision loophole
that originally justified deferring this. `public/number-categories.php` (admin-only) now requires
an explicit target organization on creation and displays each category's owning organization.
Proven by `testTwoOrganizationsCanShareTheSameCategoryName` (two organizations, same name, two
distinct rows) and `testDuplicateCategoryNameWithinTheSameOrganizationIsStillRejected`, plus the
now-updated `DatabaseIntegrityTest::testDuplicateCategoryNameWithinTheSameOrganizationIsRejectedByUniqueConstraint`
(the old test asserted the now-superseded *global* constraint and was updated to assert the new
tenant-local one — this is a genuine, disclosed behavior change, not silently patched over).

**A real IDOR was found and fixed as a direct consequence**: `public/send.php` and
`public/new-send.php` both expanded a user-submitted `category` id into mobile numbers with **zero
ownership check** — harmless before this pass (categories were globally shared by design) but a real
cross-tenant IDOR the moment categories became organization-scoped. Both files now join to
`ellsms_number_categories` and verify organization ownership before returning any numbers. Proven by
`testNumberCategoryCrossOrganizationIdorIsDenied`.

### 28.4 Payment tenant isolation — CLOSED (tested, and one real gap fixed; Phase 3 claim logic untouched)

As instructed, Phase 3's payment/claim logic was **not** redesigned. Three integration tests were
added and pass against real MySQL: `testPaymentCreditsOnlyItsOwnOrganizationsWallet` (Org A's
payment credits only Org A's wallet), `testPaymentOrganizationIsPersistedNotDerivedFromActiveSession`
(switching the active organization between payment creation and the ZarinPal callback has zero effect
on which organization the payment is attributed to — `payment_claim_and_credit()` never re-derives
organization from session), and `testDuplicatePaymentCallbackAcrossOrganizationsRemainsIdempotent`
(Phase 3's idempotency guarantee, unchanged).

One real gap **was** found and fixed, per the "fix only if tests expose a real tenant bug" instruction:
this report's own section 8 claimed `organization_id` was "set at creation" in `buy-credit.php`, but
the actual `INSERT INTO ellsms_payments` never included that column — new payments were silently
never getting an organization at all. Fixed (one line: added `organization_id` to the INSERT). This
was a real bug this report previously mis-described as done; it is now actually done and tested.

### 28.5 IDOR regression coverage — CLOSED

Representative cross-tenant tests now exist for all four resources in this closure's scope: campaign
(28.1), ticket (28.2), number category (28.3), payment (28.4) — all following existing 403/404-style
denial semantics (a query that returns no row / a `user_id` check that doesn't match), none leaking
any content across tenants.

### 28.6 Tenant integrity tool — CLOSED

`cron/tenant-integrity-check.php` now also requires `ellsms_number_categories.organization_id` to be
resolvable (moved from the old "informational, deliberately NULL" section into the same
must-resolve list as every other tenant table), checks `ellsms_number_category_items` for orphaned
category references, and adds organization-consistency checks (owner's real membership must match)
for `ellsms_number_categories`, `ellsms_campaigns`, and `ellsms_tickets`, plus a new
payment↔wallet organization-mismatch check (`ellsms_payments.organization_id` must agree with the
same user's `ellsms_wallet_accounts.organization_id`). Still read-only; still never auto-fixes. Run
against the fully-migrated, fully-exercised test database used for this closure's validation: **zero
violations** (`OK: zero tenant-integrity violations`, exit code 0).

### 28.7 A latent bug found and fixed while writing the payment test (not itself a tenant-isolation bug)

`app/tickets.php`'s `ticket_create()` used raw `$db->beginTransaction()`/`commit()`/`rollBack()`
instead of this codebase's established `db_transaction()` helper (`app/bootstrap.php`), which every
other transactional function (including this same phase's own `create_organization()`) uses
specifically because it checks `$db->inTransaction()` first and no-ops the nested begin/commit rather
than throwing. `ticket_create()`'s own tests never exercised it from inside another open transaction
before this closure pass added the ticket cross-tenant tests, which do. Fixed by converting it to use
`db_transaction()` — no behavior change for any existing caller, matches the codebase's own
established pattern exactly.

A second, unrelated latent bug was found and fixed in the same debugging session:
`current_user()` (`app/bootstrap.php`) cached its result behind a plain "resolved once, keep forever"
static flag. `Logger::info()`/`Logger::warning()` etc. call `current_user()` internally (to attach the
acting user id to every log line), so the very first log call in a PHP process — which, in a
PHPUnit run, can easily happen before any test sets `$_SESSION['uid']` — permanently poisoned the
cache to `null` for the rest of that process, breaking any later test that legitimately logs in as a
real user (`select_organization()`'s own test needed exactly this). `current_organization()`
(`app/tenant.php`) already avoided this exact trap via a cache keyed on `(userId, selectedId)`, with
a comment explicitly calling out the cross-test staleness risk — `current_user()` was simply never
given the same treatment. Fixed identically: cache now keyed on `$_SESSION['uid']` itself, so it
naturally re-resolves whenever the session's user actually changes, and stays a correct O(1) cache
for the common case (uid unchanged) that motivated the caching in the first place. Not a
tenant-isolation vulnerability on its own (a fresh PHP-FPM/CLI process per request in production
means the cache starts empty every time) — a test-suite-process-lifetime correctness bug, fixed at
the root rather than worked around per-test.

### 28.8 Validation (this closure pass, executed 2026-07-29)

- **PHP lint**: 94/94 files parse cleanly (unchanged file count — this pass edited existing files
  and added one migration + no new PHP source files outside the existing test file).
- **Unit suite**: 97 tests, 167 assertions, OK — unchanged.
- **`TenantIsolationTest` (targeted)**: **22 tests, 88 assertions, OK** (12 original Phase 6 tests +
  10 new closure tests: 3 payment, 2 campaign, 2 ticket, 3 number-category).
- **Full integration suite**: **112 tests, 361 assertions, OK, 0 failures, 0 errors, 0 skipped**,
  against a fully fresh, dropped-and-recreated database with all 9 migrations applied in order.
  Rerun twice consecutively — stable both times.
- **Phase 3 financial regression**: green — `WalletIntegrationTest`, `WalletConcurrencyTest`,
  `PaymentIntegrationTest` all pass unchanged within the 112 total; `app/zarinpal.php`'s claim logic
  was not modified (only `buy-credit.php`'s INSERT gained a column, per 28.4).
- **Phase 5 migration regression**: green — `cron/db-migrate.php --status` shows all 9 migrations
  applied in correct order (the new `2026_07_30_number_category_tenancy.sql` last, as designed);
  `DatabaseIntegrityTest` and `DatabaseOperationalScriptsTest` both pass (one `DatabaseIntegrityTest`
  test was updated in place — see 28.3 — not disabled or deleted).
- **`cron/tenant-integrity-check.php`**: exit code 0, zero violations, against the same
  fully-migrated, fully test-exercised database (see 28.6).

### 28.9 Files created in this closure pass

- `db/migrations/2026_07_30_number_category_tenancy.sql`

### 28.10 Files modified in this closure pass

- `cron/tenant-backfill.php` — backfills `ellsms_number_categories.organization_id`
- `cron/tenant-integrity-check.php` — see 28.6
- `public/number-categories.php` — organization-scoped admin creation/list (28.3)
- `public/send.php`, `public/new-send.php` — campaign/category/contact-group read-path scoping and
  the category-use IDOR fix (28.1, 28.3)
- `public/buy-credit.php` — `organization_id` actually persisted on payment creation (28.4)
- `app/tickets.php` — explicit user-private policy docblock (28.2); `ticket_create()` now uses
  `db_transaction()` (28.7)
- `app/bootstrap.php` — `current_user()` cache keyed on session uid (28.7)
- `tests/Integration/TenantIsolationTest.php` — 10 new closure tests (28.1–28.4)
- `tests/Integration/DatabaseIntegrityTest.php` — one test updated to match the new tenant-local
  category uniqueness constraint (28.3)

### 28.11 Breaking changes introduced by this closure pass

- **Number category uniqueness is now tenant-local, not global** — a disclosed, intentional
  behavior change (this was the whole point of 28.3): two organizations can now use the same
  category name, where before the second would have been rejected. No existing single-organization
  installation can observe any difference (its own names were already unique to itself).
- **New payments now always carry `organization_id`** (28.4) — this is a bugfix against this
  report's own original claim, not a new breaking change; nothing previously depended on the column
  being left NULL for new rows.
- No other behavior for any existing installation changes: campaign/ticket visibility for a
  single-member (legacy) organization is bit-for-bit identical to before this pass; the ticket policy
  clarification (28.2) documents existing behavior, it does not change it.
