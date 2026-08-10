# ELLSMS — Technical Debt Register

This is the roadmap. Every row below is grounded in one of the earlier documents produced during
this engineering-baseline phase — `PATHFINDER-2026-07-26/`, `docs/flows/`,
`docs/security-review.md`, `docs/database-audit.md` — nothing here is a new claim; it's those
findings organized into a single prioritized list with a recommended phase attached. Nothing in
this document changes any code.

> **Phase 2 update (2026-07-27).** The real-world engineering phase actually named "Phase 2"
> (Authorization, Authentication & Security Hardening — see `docs/phase-2-final-report.md`) is
> **not the same thing** as this register's own internal "Phase 2" label below (which only covers
> TD-001–TD-004). The real Phase 2 remediated items scattered across this register's Phase 2, 5, 6,
> 7, 8, and 10 buckets — a new **Status** column has been added to the master table below to record
> exactly which items that touched, without renumbering or reshuffling this register's own phase
> scheme. No row has been deleted; items outside Phase 2's actual scope (wallet/queue/architecture/
> duplication cleanup not related to authorization) are unchanged and still open.
>
> **Phase 3 update (2026-07-28).** The real-world "Phase 3" (Wallet Ledger, Atomic Credit,
> Reservation & Payment Integrity — see `docs/phase-3-final-report.md`) fixed TD-003/TD-004/TD-005/
> TD-006 — this register's own "Phase 3" label below happens to line up with the real Phase 3's
> scope for once, unlike Phase 2's mismatch above. See `docs/wallet-architecture.md` for the full
> design.
>
> **Phase 4 update (2026-07-28, same day).** The real-world "Phase 4" (Worker Reliability, Job
> Claiming, Idempotent Execution & Crash Recovery — see `docs/phase-4-final-report.md`) fixed
> TD-007 and TD-008 in full, and addresses TD-009's underlying risk (though not by the literal
> "add a mutex" mechanism this register originally envisioned — see that row's own note) — this
> register's own "Phase 4" label below also lines up with the real Phase 4's scope. TD-010
> (gradual-job UI visibility) remains open — genuinely out of this phase's scope, which was
> reliability/concurrency, not UI. See `docs/job-queue-architecture.md` for the full design.
>
> **Phase 5 update (2026-07-29).** The real-world "Phase 5" (Database Integrity, Constraints,
> Migration Safety & Data Lifecycle — see `docs/phase-5-final-report.md`) does **not** line up with
> this register's own "Phase 5" label below (Rate Limiting, already fully handled by the real
> Phase 2) — same mismatch pattern as the real Phase 2 above. The real Phase 5 actually touched
> this register's own **Phase 9** bucket (TD-024–TD-027): fixed TD-025, partially addressed TD-026
> and TD-027, deliberately left TD-024 as an ambiguous product decision (now backed by live
> tooling rather than a one-time note). TD-028 (analytics.php memory usage) is unchanged — out of
> a database-integrity phase's scope. See `docs/database-migrations.md` for the full design.
>
> **Phase 6 update (2026-07-29, same day).** The real-world "Phase 6" (Organization/Multi-Tenancy
> Foundation & Tenant Data Isolation — see `docs/phase-6-final-report.md`) introduces a scope this
> register never had a row for: this codebase was single-tenant (user-owned data) when the
> original Phase 1 audit was written, so there is no pre-existing TD item Phase 6 "fixes" — it's
> new foundational capability, not debt remediation. Recorded here for continuity with every other
> phase's update-note convention, not because a TD-0xx row maps to it. See
> `docs/multi-tenancy-architecture.md` for the full design and `docs/database-migrations.md`
> section 5 for the new FKs this phase's own migration added on top of Phase 5's constraint
> discipline.

> **Phase 9 update (2026-08-01).** The real-world "Phase 9" (Observability, Performance Baseline,
> Load Testing & Queue Scaling Decision — see `docs/phase-9-final-report.md`) does **not** line up
> with this register's own "Phase 9" label below (Data hygiene, retention & performance — already
> covered by the real Phase 5, 2026-07-29, per that update note above) — same mismatch pattern as
> the real Phase 2/5 above. The real Phase 9 introduces a scope this register never had a row for
> (metrics, a load-test harness, a MySQL-vs-Redis queue-technology decision) and, incidentally,
> fixed one genuine performance bug found while investigating: `run_due_schedules()`'s due-row
> lookup used a non-sargable `COALESCE()` predicate that defeated an index (`idx_due`) that has
> existed since the real Phase 4 — rewritten to an equivalent sargable form, confirmed via EXPLAIN
> to move from a full table scan to an index range scan. TD-028 (`analytics.php` memory usage)
> remains open and out of scope — a page-load memory concern, not a queue/worker concern. TD-021
> (`app/backend.php` size) grew further with this phase's instrumentation, same trend every prior
> phase touching that file already noted. See `docs/observability-and-performance.md` for the full
> metrics/benchmark design and `docs/phase-9-final-report.md` §24 for the queue-technology decision.
>
> **Phase 10 update (2026-08-02).** The real-world "Phase 10" (Production Hardening, Security
> Closure & Release Safety — see `docs/phase-10-final-report.md`) lines up reasonably well with
> this register's own "Phase 10" label below (Hardening & defense-in-depth, TD-029-034/036) — closer
> than most of the earlier mismatches in this document. Fixed this pass: **TD-033** (xlsx
> decompression-before-cap) and **TD-034** (bootstrap-admin race), both with regression tests. Also
> fixed, found during this phase's own risk inventory rather than pre-existing in this register:
> `client_ip()`/`request_is_https()` (app/rate_limit.php, app/bootstrap.php) trusted
> `X-Forwarded-For`/`X-Forwarded-Proto` from ANY client unconditionally — a real rate-limit-bypass
> vector, closed with `TRUSTED_PROXY_IPS` (fail-closed by default). TD-030 (secrets in plaintext in
> `ellsms_settings`) remains open — documented, deliberately out of scope (would require
> secret-management infrastructure this phase was explicitly told not to build). TD-036 (CSP
> `'unsafe-inline'`) remains open, same reasoning as before — tightening requires a larger UI change
> than this phase's scope permits. TD-037 (reserved RBAC permissions) reconfirmed unchanged by
> design — no organization-scoped feature was built for them to gate, matching this phase's own
> explicit instruction not to wire permissions to non-existent actions. TD-038 (tenant-integrity-check
> zero-owner bug) reconfirmed still fixed (Phase 8). See `docs/production-hardening.md` for the full
> hardening design and the backend-HMAC-verifier status (still PARTIAL, honestly disclosed, not
> marked fixed).

## Phase legend

| Phase | Theme | Status |
|---|---|---|
| Phase 1 | Engineering baseline — analysis, docs, logging, error handling, config, health checks, worker reliability, transaction-helper infrastructure | **complete** (this effort) |
| Phase 2 | Critical security & data-integrity fixes | **TD-001/TD-002 fixed** (real Phase 2, 2026-07-27); **TD-003/TD-004 fixed** (real Phase 3, 2026-07-28) |
| Phase 3 | Wallet / credit atomicity redesign | **complete** (real Phase 3, 2026-07-28) — TD-005/TD-006 fixed, see `docs/wallet-architecture.md` |
| Phase 4 | Worker & job-queue redesign | **complete** (real Phase 4, 2026-07-28) — TD-007/TD-008 fixed, TD-009's risk addressed, see `docs/job-queue-architecture.md` |
| Phase 5 | Rate limiting & brute-force protection infrastructure | **TD-011/TD-013 fixed, TD-012 partially fixed** (real Phase 2, 2026-07-27) |
| Phase 6 | Architecture decoupling (shared DB, backend API trust boundary) | TD-015 partially fixed (real Phase 2); TD-014 not started |
| Phase 7 | Authentication hardening (coordinated with backend team) | **partially fixed** — shadow verifier infra only (real Phase 2, 2026-07-27); login itself unchanged |
| Phase 8 | Code consolidation & duplication cleanup | TD-017/TD-023 fixed as side effects of real Phase 2; the rest not started |
| Phase 9 | Data hygiene, retention & performance | **TD-025 fixed, TD-026/TD-027 partially addressed, TD-024 deliberately deferred** (real Phase 5, 2026-07-29); TD-028 not started, see `docs/database-migrations.md` |
| Phase 10 | Hardening & defense-in-depth | **TD-029/TD-031/TD-032 fixed** (real Phase 2, 2026-07-27); **TD-033/TD-034 fixed** (real Phase 10, 2026-08-02); TD-030 open (documented, out of scope); TD-036 open (disclosed) |

Phases are ordered by urgency and by dependency, not strictly by severity — e.g. Phase 3 (wallet)
comes before Phase 6 (architecture) even though some Phase 6 items are HIGH, because the wallet
race is a live financial-integrity risk today and the architecture items require external
coordination that will take longer regardless of when it's started. Phase 1 is listed for context
since later phases build on the infrastructure it added (`Logger`, `ErrorHandler`, `AppException`,
`db_transaction()`, health endpoints) — none of that is itself debt.

## Master list

| ID | Area | Problem | Risk | Severity | Recommended Phase | Status |
|---|---|---|---|---|---|---|
| TD-001 | Authorization | `inbox.php` applies no ownership filter when a user's legacy `originator` field is empty | Any regular user reads every tenant's inbound messages, content included | CRITICAL | Phase 2 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-002 | Admin Authorization | `users.php`'s edit view and 5 of 7 mutating actions never check `panel_access` on the target id | An ELLSMS admin can read/reset the password/change credit of any backend account, not just ELLSMS-granted ones | CRITICAL | Phase 2 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-003 | Payments | Payment-row claim and `currentcredit` increment are two separate, non-transactional statements | A crash between them permanently marks a payment paid with the customer never credited, no recovery | HIGH | Phase 2 | **FIXED** (real Phase 3, 2026-07-28) — `payment_claim_and_credit()`, see `docs/wallet-architecture.md` |
| TD-004 | Payments | No reconciliation job for payments stuck `pending` (user never returns from ZarinPal) | Customer pays, is never credited, nothing in the system ever notices | MEDIUM | Phase 2 | **FIXED** (real Phase 3, 2026-07-28) — `cron/payments-reconcile.php` / `make payments-reconcile` |
| TD-005 | Wallet | Credit check-then-deduct in `dispatch_message()` is not atomic — reads a snapshot, decides, then writes | Double spending / negative balance under concurrent requests to the same account | CRITICAL | Phase 3 | **FIXED** (real Phase 3, 2026-07-28) — reserve→dispatch→commit/release via `app/wallet.php`, `SELECT ... FOR UPDATE` row locking; proven under real concurrency by `tests/Integration/WalletConcurrencyTest.php` |
| TD-006 | Wallet | `bulk_queue_job()` re-implements the same credit check against an even staler snapshot | Widens the TOCTOU window above rather than closing it; gives false assurance a queued batch "fits" | HIGH | Phase 3 | **FIXED** (real Phase 3, 2026-07-28) — the job's full worst-case cost is now reserved atomically at creation time, in the same transaction as the job/item rows |
| TD-007 | Job Queue | `run_bulk_send_pass()` has no atomic per-item claim before sending (unlike schedules/auto-reply) | Duplicate sends if the worker ever overlaps itself (overlapping cron + persistent loop, or a scaled replica) | HIGH | Phase 4 | **FIXED** (real Phase 4, 2026-07-28) — `bulk_claim_items()`, atomic `UPDATE ... ORDER BY id LIMIT n`, proven under real two-process concurrency by `tests/Integration/BulkItemConcurrencyTest.php`; see `docs/job-queue-architecture.md` |
| TD-008 | Scheduling | Worker's schedule-finalize `UPDATE` has no `WHERE status='processing'` guard | User cancel vs. worker finalize is a lost-update race — a cancelled, already-charged send can silently revert to active/repeat | HIGH | Phase 4 | **FIXED** (real Phase 4, 2026-07-28) — finalize `UPDATE` now guarded with `CASE WHEN status='cancelled' THEN 'cancelled' ELSE ? END`; a fresh cancellation re-check also runs right after claim, before dispatch. See `docs/job-queue-architecture.md`'s Cancellation semantics section |
| TD-009 | Worker | Two invocation modes (persistent loop + `--once` cron) can coexist with no mutex | Running both against one install double-processes every pass | MEDIUM | Phase 4 | **RISK ADDRESSED, not via a mutex** (real Phase 4, 2026-07-28) — the actual harm this row named was double-processing, which the new atomic per-row claim (same fix as TD-007) already prevents regardless of how many worker processes/modes are running concurrently; no mutex was added, and none is needed for correctness now. Running both modes against one install remains unnecessary (redundant work, more DB polling) but is no longer unsafe |
| TD-010 | Bulk Send UX | "Gradual" jobs are queued with `type='gradual'` but the only listing/cancel page filters on `type='p2p'` | A queued gradual job is invisible and uncancellable anywhere in the UI | MEDIUM | Phase 4 | open (unchanged — a UI-visibility gap, genuinely out of Phase 4's reliability/concurrency scope; explicitly excluded by that phase's own "do not redesign UI" ground rule) |
| TD-011 | Rate Limiting | No rate-limit/lockout mechanism exists anywhere in the codebase | Login, 2FA, and the public contact form are all brute-forceable/spammable at will | HIGH | Phase 5 | **FIXED** for login/2FA/API send (real Phase 2, 2026-07-27); `contact.php` still has no rate limit — not on an auth/send path, left open |
| TD-012 | Rate Limiting | `url_send.html` accepts credentials via GET and exposes a distinguishable error code for "wrong password" vs. every later failure | Credentials logged in plaintext in transit/access logs; usable as a brute-force success oracle | HIGH | Phase 5 | **PARTIALLY FIXED** — rate limiting added (real Phase 2); the GET-credentials/error-code-oracle design itself unchanged |
| TD-013 | 2FA | Attempt counter and code validity live in session state, reset by restarting the login step | Weaker practical brute-force limit on the 6-digit code than "5 attempts" implies, if credentials are already known | MEDIUM | Phase 5 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-014 | Architecture | ELLSMS shares its database wholesale with an external backend platform it doesn't control | High coupling — every schema assumption about `user_`/`outbound_message`/`inbound_message` is unverified and unversioned from ELLSMS's side | HIGH | Phase 6 | open (unchanged — explicitly out of real Phase 2's scope) |
| TD-015 | Architecture / Security | No authentication (API key, signature, token) on ELLSMS→backend API calls | If that API is ever reachable from anywhere but ELLSMS, anyone can send SMS as an arbitrary user or create accounts | HIGH | Phase 6 | **PARTIALLY FIXED** — ELLSMS-side HMAC request signing implemented (real Phase 2); no backend-side verifier exists in this repo, so end-to-end auth is not complete |
| TD-016 | Authentication | Password hashing is plain unsalted SHA-256, inherited from the backend platform | Cheap to crack at scale if the database is ever read (backup exposure, unrelated breach) | HIGH | Phase 7 | **PARTIALLY FIXED** — shadow Argon2id verifier infrastructure added (real Phase 2); login itself still authorizes via legacy SHA-256, unchanged, since the backend platform authenticates against the same column independently |
| TD-017 | Duplication | `SELECT number,label FROM ellsms_numbers WHERE assigned_user_id=?` copy-pasted verbatim in 5 files | A future schema change needs 5 coordinated edits; already the source of the `inbox.php` IDOR (TD-001) via divergence | LOW | Phase 8 | **FIXED** (side effect of building `user_assigned_numbers()`/`allowed_originators()` in real Phase 2 — all 5 call sites now use the shared helper) |
| TD-018 | Duplication | `parse_destinations()` reimplemented ad hoc in `contacts.php`, `blacklist.php`, `number-categories.php` | Each has slightly different normalization semantics; a fix to one doesn't propagate | LOW | Phase 8 | open (unchanged) |
| TD-019 | Duplication | `p2p-send.php`/`smart-send.php` duplicate ~90% of their code (only row-parsing genuinely differs) | Maintenance burden — every non-parsing change has to be made twice, identically, to stay in sync | MEDIUM | Phase 8 | open (unchanged) |
| TD-020 | Duplication | `pricing.php`/`guide-admin.php`/`slides.php` each hand-roll an identical admin CRUD scaffold | Same maintenance burden as TD-019, smaller surface | LOW | Phase 8 | open (unchanged) |
| TD-021 | Maintainability | `app/backend.php` (627+ lines, ~5 responsibilities), `app/bootstrap.php` (520+ lines), `new-send.php` (413 lines, 3-mode dispatch), `users.php` (382 lines, 7-way dispatch) | Large, multi-responsibility files are harder to review/change safely — directly contributed to TD-002 being missed for this long | LOW | Phase 8 | open (both files grew further in real Phase 2 — authorization/rate-limit/2FA/HMAC logic added — a future split is now more warranted, not less) |
| TD-022 | Code Quality | 5 raw string-interpolated SQL statements (`schedules.php`, `autoreply.php` ×2, `p2p-send.php`, `smart-send.php`) alongside otherwise-universal prepared statements | Not currently exploitable (values are `(int)`-cast first) but a latent injection risk if a future edit adds an un-cast field without noticing | LOW | Phase 8 | open (unchanged — not on an authorization/auth path) |
| TD-023 | Code Quality | `slides.php`'s upload validator lacks the extension-based MIME fallback `kyc_store_upload()` has | Inconsistent behavior between two structurally-identical helpers; admin-only, low impact | LOW | Phase 8 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-024 | Database | `ellsms_contacts` has no unique constraint on `(user_id, mobile[, group_name])` | Re-importing the same list twice silently duplicates rows, confirmed observable | MEDIUM | Phase 9 | **DELIBERATELY DEFERRED** (real Phase 5, 2026-07-29) — still an ambiguous product decision (which shape?), exactly as this row originally said; `make db-integrity-check` now reports live duplicate counts for both candidate shapes so the decision has real numbers, but no constraint was added. See `docs/database-migrations.md`. |
| TD-025 | Database | `ellsms_number_categories.name` has no unique constraint | An admin can create two identically-named categories with no indication in the UI | LOW | Phase 9 | **FIXED** (real Phase 5, 2026-07-29) — preflight-guarded `UNIQUE` constraint added; see `docs/database-migrations.md`. |
| TD-026 | Database | Every `ellsms_*` FK-like column pointing at a backend-owned table has zero DB-level enforcement, and orphan risk is unverified | Silent data drift is possible if the backend deletes/changes an account ELLSMS still references; unquantified without a discovery pass | MEDIUM | Phase 9 | **PARTIALLY ADDRESSED** (real Phase 5, 2026-07-29) — deliberately still zero hard FKs to backend-owned tables (unchanged, correct per `docs/database-audit.md`'s own reasoning), but `make db-integrity-check` now reports live orphan counts against `user_.id` for every referencing ELLSMS table, turning "unquantified" into a standing, re-runnable report. Separately, 5 FKs were added between ELLSMS-owned table *pairs* (bulk_items→bulk_jobs, wallet_transactions/reservations→wallet_accounts, category_items→categories, ticket_replies→tickets) — see `docs/database-migrations.md`. |
| TD-027 | Database | `ellsms_audit_log`, `ellsms_autoreply_log`, `ellsms_2fa_codes`, `ellsms_bulk_items` grow unbounded with no retention policy or supporting index for one | Backup size and query performance degrade slowly over the life of the install | MEDIUM | Phase 9 | open — **and now also applies to the new `ellsms_rate_limits` table** (real Phase 2), which is opportunistically pruned per-bucket on each hit but has no standalone retention job either. **PARTIALLY ADDRESSED** (real Phase 5, 2026-07-29) — `ellsms_2fa_codes` (expired rows) and `ellsms_rate_limits` (stale rows) now have an operator-triggered cleanup command (`make db-cleanup`/`db-cleanup-apply`, dry-run by default). `ellsms_audit_log`/`ellsms_autoreply_log`/`ellsms_bulk_items` remain permanent by deliberate policy (audit value / parent-dependent lifecycle), not fixed — see `docs/database-migrations.md`'s Data lifecycle section. |
| TD-028 | Performance | `analytics.php` loads up to 300,001 full rows (including `content`) into PHP memory and aggregates there instead of in SQL | Real memory/latency cost per page load on a table ELLSMS doesn't control the size of | MEDIUM | Phase 9 | open (unchanged) |
| TD-029 | Session Security | Session cookie has no `secure` flag; no absolute session lifetime independent of PHP's GC | No app-level backstop if HTTPS is ever misconfigured at the infra layer; sessions can persist indefinitely | MEDIUM | Phase 10 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-030 | Secrets Handling | ZarinPal merchant ID / Telegram bot token stored as plaintext in `ellsms_settings.svalue` | Readable in the clear if the database is ever exposed independent of the app itself | MEDIUM | Phase 10 | open (unchanged — different concern than the real Phase 2 repo-secrets scan, which re-confirmed clean) |
| TD-031 | Security Headers | No CSP, `X-Frame-Options`, or `Strict-Transport-Security` anywhere, despite `a2enmod headers` being enabled but unused | No browser-side defense-in-depth backstop for a future missed escape or clickjacking | LOW | Phase 10 | **FIXED** (real Phase 2, 2026-07-27) — CSP still allows `'unsafe-inline'` for script/style, see TD-036 below |
| TD-032 | CSRF | `logout.php` performs `session_destroy()` on a bare GET with no `csrf_check()` | A third party can force-terminate a victim's session; no data impact | LOW | Phase 10 | **FIXED** (real Phase 2, 2026-07-27) |
| TD-033 | Reliability | `xlsx_reader.php` fully decompresses/parses an uploaded file before any row-count cap is applied | A highly-compressed or huge sheet can exhaust memory/CPU before the cap has a chance to reject it | MEDIUM | Phase 10 | **FIXED** (real Phase 10, 2026-08-02) — `ZipArchive::statName()` reports each member's uncompressed size from the zip's own local file header BEFORE any decompression happens; a member over 64MB uncompressed is rejected immediately. See `tests/Unit/XlsxReaderTest.php`. |
| TD-034 | Security | `bootstrap-admin.php`'s "only one admin" check-then-insert has no lock | Two concurrent first-admin submissions for two different accounts can both succeed; narrow, first-deploy-only window | MEDIUM | Phase 10 | **FIXED** (real Phase 10, 2026-08-02) — the check+insert is now serialized with `GET_LOCK('ellsms_bootstrap_admin', 5)`. See `tests/Integration/BootstrapAdminLockTest.php`. |
| TD-035 | Authorization | *(new, found during real Phase 2)* Scheduled/auto-reply/bulk-worker execution paths fetched but didn't consistently check `panel_access`, and no send path revalidated sender-line authorization at the point of dispatch | A revoked user or one whose sender-line assignment changed could still have queued work executed under the old authorization | HIGH | Phase 2 (real) | **FIXED** (real Phase 2, 2026-07-27) — see `docs/security-review.md` finding 15 |
| TD-036 | Security Headers | The new CSP (TD-031) allows `script-src 'self' 'unsafe-inline'` and `style-src 'self' 'unsafe-inline'` because the app uses inline `<script>`, inline event-handler attributes, and inline `style=""` widely | A missed output-escaping bug could still execute inline script — CSP doesn't backstop that class of bug the way a nonce/hash-based policy without `'unsafe-inline'` would | LOW | Phase 10 | open (new debt disclosed by real Phase 2 — tightening further requires migrating inline handlers to external/nonced scripts, a larger change than adding headers) |
| TD-037 | Authorization | *(new, found during real Phase 7)* Five RBAC permissions (`sender.manage`, `wallet.adjust`, `kyc.view`, `kyc.manage`, `audit.view`) are catalog-complete and correctly present in the owner/admin/member role matrix, but gate no real organization-scoped feature today — `public/numbers.php`, `app/wallet.php`'s manual adjustment, `public/users.php`'s KYC actions, and audit-log viewing all remain platform-admin-only (or, for KYC, self-view-only), completely outside organization RBAC | None currently — this is intentional scope discipline (`docs/rbac-architecture.md`'s "Reserved Permissions" section), not a gap, since building the underlying org-scoped features themselves was explicitly out of Phase 7's scope | LOW | Phase 7 (real) | open by design — becomes real work only if/when one of those features is actually built as organization-scoped; no code change needed to the permission catalog itself when that happens |
| TD-038 | Database | `cron/tenant-integrity-check.php`'s "organizations with zero active owners" check (Phase 6) is structurally unable to ever report a finding — it `GROUP BY organization_id HAVING COUNT(*) = 0` over EXISTING membership rows, which can only ever produce groups with COUNT >= 1; an organization with literally zero owner rows never appears as a group at all, so the check is permanently a silent no-op | Currently masked because `cron/rbac-integrity-check.php` (Phase 7) added the correct `LEFT JOIN`/`NOT EXISTS` version of the same check and both tools are recommended together, but the Phase 6 tool's own copy stays wrong on its own | LOW | Phase 7 (real, found) | open — deliberately left unfixed in `cron/tenant-integrity-check.php` itself (Phase 6's file, out of Phase 7's stated scope: "do not redo Phase 1-6 work"); the correct check now lives in `cron/rbac-integrity-check.php` instead |

## Phase detail

### Phase 2 — Critical security & data-integrity fixes
TD-001, TD-002, TD-003, TD-004. These are the only items with a live, low-precondition
exploitation path (TD-001, TD-002) or a real quantifiable financial-loss scenario (TD-003, TD-004)
found anywhere in this review. Full detail: `docs/security-review.md` findings 1, 2, 6;
`docs/flows/payment.md`.

**Update (2026-07-27):** TD-001 and TD-002 are now FIXED — see `docs/phase-2-final-report.md` for
the full real-world Phase 2 report (this register's "Phase 2" label predates and only partially
overlaps that phase's actual scope, which also touched sessions/2FA/rate-limiting/backend-auth,
i.e. items filed under this register's Phase 5/6/7/10). TD-003/TD-004 (payment transactionality)
remained open at the end of Phase 2 — Phase 2's own ground rules explicitly excluded wallet/credit
changes.

**Update (2026-07-28):** TD-003 and TD-004 are now FIXED — the real-world Phase 3 built the wallet
ledger this finding needed; see `docs/phase-3-final-report.md` and `docs/wallet-architecture.md`.

### Phase 3 — Wallet / credit atomicity redesign
TD-005, TD-006. Explicitly deferred during this baseline phase per direct instruction not to
redesign the wallet yet — `db_transaction()` (built in this phase) is the tool a fix would use, but
the actual fix requires deciding the right atomic-check shape (e.g. a single conditional
`UPDATE ... WHERE currentcredit >= cost`), which is a deliberate design decision, not a mechanical
change. Full detail: `docs/flows/credit.md`, `PATHFINDER-2026-07-26/03-unified-proposal.md`
section D.

**Update (2026-07-28): FIXED.** The real-world Phase 3 replaced the check-then-deduct pattern with
a reserve→dispatch→commit/release cycle backed by an immutable ledger and `SELECT ... FOR UPDATE`
row locking (`app/wallet.php`) — the exact atomic-check shape this section anticipated, chosen as
a reservation model rather than a single conditional `UPDATE` because bulk jobs need to hold credit
across many individually-committed items, not just one all-or-nothing statement. Proven under real
concurrency (not just unit-tested) by `tests/Integration/WalletConcurrencyTest.php`. Full detail:
`docs/wallet-architecture.md`, `docs/phase-3-final-report.md`.

### Phase 4 — Worker & job-queue redesign
TD-007, TD-008, TD-009, TD-010. Originally deferred per direct instruction not to introduce Redis
or redesign the job queue yet during the worker-reliability phase. Full detail (pre-Phase-4 state):
`docs/flows/bulk-message.md`, `docs/flows/scheduled-message.md`,
`PATHFINDER-2026-07-26/03-unified-proposal.md` section C.

**Update (2026-07-28, same day as Phase 3): TD-007 and TD-008 FIXED, TD-009's risk addressed.**
The real-world Phase 4 added atomic claim/lease/retry semantics to all three background execution
paths (bulk items, schedules, auto-reply) using plain MySQL — no Redis, no new infrastructure, per
that phase's own ground rules. TD-010 (gradual-job UI visibility) is unchanged — a UI gap, not a
reliability/concurrency issue, explicitly out of this phase's scope. Full detail:
`docs/job-queue-architecture.md`, `docs/phase-4-final-report.md`.

### Phase 5 — Rate limiting & brute-force protection infrastructure
TD-011, TD-012, TD-013. One shared primitive (a rate-limit table or cache keyed on
`identifier + action`) would address all three surfaces at once. Full detail:
`docs/security-review.md` findings 3, 7.

**Update (2026-07-27):** Built and shipped in real Phase 2 — `app/rate_limit.php` +
`ellsms_rate_limits` (migration `db/migrations/2026_07_27_rate_limits.sql`) is exactly the shared
primitive described above, wired into login, 2FA verify/resend, and `url_send.html`. TD-013 (2FA
session-resettable attempts) is also fully fixed via the same phase's 2FA hardening. TD-012's
underlying GET-credentials/error-code-oracle design was not changed, only rate-limited — see
`docs/security-review.md` finding 3's Phase 2 update.

### Phase 6 — Architecture decoupling
TD-014, TD-015. Both require agreement from whoever operates the backend platform — not something
ELLSMS can resolve unilaterally, which is why this phase sits after the wallet/queue work that
ELLSMS fully controls. Full detail: `docs/architecture.md`, `docs/security-review.md` finding 5.

**Update (2026-07-27):** TD-015 partially fixed in real Phase 2 — `backend_service_auth_headers()`
(`app/backend.php`) adds ELLSMS-side HMAC request signing (opt-in via `BACKEND_SERVICE_ID`/
`BACKEND_SERVICE_SECRET`). Marked partially, not fully, fixed because no backend-side verifier
exists in this repository — see `docs/security-review.md` finding 5's Phase 2 update for the
documented verification contract the backend still needs to implement. TD-014 (shared-DB coupling
itself) is unchanged.

### Phase 7 — Authentication hardening
*(Naming note: this section's "Phase 7" is this original roadmap's own numbering, planned before the
project's actual execution order diverged from it — the actually-executed Phase 7 delivered
fine-grained RBAC instead, see `docs/phase-7-final-report.md` and `docs/rbac-architecture.md`. This
section's authentication-hardening item remains open, unrelated to and unaffected by that work.)*

TD-016. Requires a coordinated transparent-upgrade-on-login migration with the backend team, since
neither side has access to the other's users' plaintext passwords to do a one-shot re-hash. Full
detail: `docs/security-review.md` finding 4.

**Update (2026-07-27):** Partially fixed in real Phase 2 — `ellsms_password_verifiers` (migration
`db/migrations/2026_07_27_password_verifiers.sql`) plus `backend_verify_password_and_upgrade()`
opportunistically stores a modern Argon2id verifier on every successful login, exactly the
transparent-upgrade groundwork this section calls for. Login itself still authorizes via the
legacy SHA-256 check only — nothing reads the new table yet — since a real cutover still needs
backend-team coordination, per this section's own description.

### Phase 8 — Code consolidation & duplication cleanup
TD-017 through TD-023. Lowest risk of any phase — mechanical refactors of code whose *current*
behavior is already understood and, in most cases, already correct; the value is in preventing the
next divergence (like TD-001, which was caused by exactly this kind of duplication). Full detail:
`PATHFINDER-2026-07-26/02-duplication-report.md`, `PATHFINDER-2026-07-26/03-unified-proposal.md`,
`PATHFINDER-2026-07-26/04-handoff-prompts.md`.

**Update (2026-07-27):** TD-017 and TD-023 fixed as direct side effects of real Phase 2 (building
`user_assigned_numbers()`/`allowed_originators()` to fix TD-001 necessarily consolidated the same 5
duplicated query sites this item flagged; fixing the `slides.php` upload validator to match
`kyc_store_upload()` was explicitly in scope as part of the file-upload hardening step). TD-018
through TD-022 are unchanged.

### Phase 9 — Data hygiene, retention & performance
TD-024 through TD-028. TD-024/TD-025 need a data-cleanup pass before any constraint is added (see
the staged migration plan in `docs/database-audit.md` — do not apply directly). TD-026 starts with
a read-only discovery/reporting pass, not a schema change. Full detail (pre-Phase-5 state):
`docs/database-audit.md` in full.

**Update (2026-07-29): TD-025 FIXED, TD-026/TD-027 PARTIALLY ADDRESSED, TD-024 DELIBERATELY
DEFERRED.** The real-world Phase 5 executed most of the staged plan this section describes:
`ellsms_number_categories.name` got its preflight-guarded `UNIQUE` constraint (TD-025); the
discovery/reporting pass TD-026 called for is now `make db-integrity-check`, a standing re-runnable
command rather than a one-time query set; `ellsms_2fa_codes`/`ellsms_rate_limits` got an
operator-triggered cleanup command addressing part of TD-027's unbounded-growth concern (the other
three unbounded tables remain permanent by deliberate audit/parent-dependent policy, not fixed).
TD-024 (`ellsms_contacts` uniqueness) is unchanged in substance — still needs the product decision
this section already called for — but is no longer just a note: `db-integrity-check` now reports
live duplicate counts for both candidate shapes. TD-028 (`analytics.php` memory usage) is untouched,
out of a database-integrity phase's scope. Full detail: `docs/database-migrations.md`,
`docs/phase-5-final-report.md`.

### Phase 10 — Hardening & defense-in-depth
TD-029 through TD-034. Each is independent and low-risk to fix on its own schedule; grouped
together because none is urgent enough to justify reordering ahead of Phases 2–9, not because
they're related to each other. Full detail: `docs/security-review.md` findings 8, 9, 11, 12, 13;
`docs/flows/bulk-message.md`; `docs/flows/authentication.md`.

**Update (2026-07-27):** TD-029 (session security), TD-031 (security headers), and TD-032 (logout
CSRF) all fixed in real Phase 2 — see `docs/security-review.md` findings 8, 12, 11 respectively.
TD-030 (secrets at rest), TD-033 (xlsx decompression), and TD-034 (bootstrap-admin race) are
unchanged, out of that phase's scope. TD-036 (CSP `'unsafe-inline'` debt) is new, disclosed
directly by the finding-12 fix rather than discovered independently.

### Phase 11 — Backup, restore, disaster recovery & release operations
Not a numbered TD item — this phase closed a standing structural gap this register never had its
own line for ("no tested backup/restore path exists"). Built and proved for real: `cron/backup.php`/
`cron/restore.php`/`cron/restore-test.php`/`cron/backup-prune.php`/`cron/backup-status.php`/
`cron/dr-drill.php`/`cron/release.php`, maintenance mode, and a full migration rollback matrix — see
`docs/backup-and-disaster-recovery.md` and `docs/phase-11-final-report.md`.

**Residual debt disclosed by this phase, not fixed by it** (deliberately out of scope, per this
phase's own instructions): point-in-time recovery is documented but **not implemented or
verified** (binlog-based PITR needs `log_bin`/`binlog_format=ROW` this repo doesn't configure);
no offsite/cloud-storage vendor integration was added (documentation-only guidance instead); TD-030
(secrets stored in plaintext in `ellsms_settings`) is unchanged — Phase 11's backup tooling doesn't
touch that column's encryption status either way, since the backup captures whatever's already in
the table regardless of its own at-rest protection; no default backup schedule is installed by this
repo (an operator must configure cron/systemd — see the backup doc's scheduled-backup section).

### Phase 2 (real) — Sender/originator authorization revalidation
TD-035. Not part of this register's original phase scheme — discovered mid-remediation while
fixing TD-001/TD-002, when the same "trust the caller" pattern turned out to also affect background
worker execution (scheduled sends, auto-reply, bulk jobs) and every direct send path's choice of
sender line. Fixed in the same real Phase 2 pass. Full detail: `docs/security-review.md` finding 15.

### Phase 12 — Public API, API keys, idempotency & webhooks
Not a numbered TD item — a new capability, not a fix to prior debt. Built and proved for real:
`/api/v1/*` (API keys, scopes, idempotent writes, contacts/messages/bulk-jobs/balance endpoints),
`cron/webhook-worker.php` (signed delivery, retry/dead-letter, SSRF-validated endpoints, AES-256-GCM
secret encryption) — see `docs/public-api.md`, `docs/webhooks.md`, `docs/phase-12-final-report.md`.

**Residual debt disclosed by this phase, not fixed by it:** `message.sent`/`message.failed` webhook
events fire only for API-initiated sends (`POST /api/v1/messages`), not for web-UI-initiated sends
— wiring the same events into the panel's own send pages is a natural follow-up, deliberately kept
out of scope to keep this phase's fan-out points minimal and reviewable. API key rotation has no
overlap/grace window (the old secret stops working immediately) — acceptable for v1, documented as
a possible future enhancement. `POST /api/v1/bulk-jobs` has no per-item read endpoint in this
version (a status summary only), a deliberate STEP 2 scope reduction, not an oversight. TD-030
(secrets stored in plaintext in `ellsms_settings`) is unchanged; unrelated to this phase's own new
`WEBHOOK_MASTER_KEY`-encrypted secret storage, which is a new, separate, already-encrypted store.
The backend HMAC verifier remains PARTIAL, exactly as every prior phase disclosed — this phase adds
no service-to-service authentication changes and deliberately shares no key material with it
(Invariant L).

### Phase 13 — Plans, subscriptions, entitlements & quotas
Not a numbered TD item — a new capability. Built and proved: central entitlement/limit catalogs, a
plan/subscription model with a DB-enforced one-effective-subscription guarantee, race-safe quota
reservation and resource-slot allocation (both proven with real multi-process tests), plan-aware API
rate limits and bulk caps, worker-side subscription enforcement, server-derived subscription payments
with immutable price snapshots, and a lifecycle scheduler. See `docs/plans-and-entitlements.md`,
`docs/billing-operations.md`, `docs/phase-13-final-report.md`.

**Residual debt disclosed by this phase, not fixed by it** (all deliberately out of scope, documented
rather than faked):
- **Retention entitlements are metadata only.** Plan-varying data retention is NOT enforced — no
  deletion is driven by plan. STEP 25 explicitly permits deferring this, and doing it properly needs
  a dedicated retention phase that can reason about financial/audit/delivery record classes safely.
- **Threshold warning notifications (80%/90%) are not implemented.** The underlying data exists
  (`organization_usage()`); the idempotent per-organization/metric/period/threshold notification
  state machine does not. STEP 46 permits documenting this as future work.
- **Soft limits are schema-only.** `ellsms_plan_limits.enforcement` supports `'soft'`, but only
  `'hard'` is exercised anywhere. Deliberate: soft limits without overage billing create unbounded
  liability, and overage billing is explicitly out of scope for this phase.
- **No proration.** An upgrade charges a full period with no credit for the unused remainder of the
  old one. Documented policy, not an oversight.
- **No public subscription-management API.** Plans are changed through the web UI only; no `/api/v1`
  route can alter a subscription.
- **Paid plan prices ship as placeholders** and must be reviewed before anyone is charged.
- TD-030 (secrets in plaintext in `ellsms_settings`) unchanged; the backend HMAC verifier remains
  PARTIAL, exactly as every prior phase disclosed — Phase 13 adds no service-to-service auth changes.

## Admin-managed SMS pricing (2026-08-09)

Operators/prefixes/providers/routes/tariffs became admin-configured database state, resolved through
one pricing engine used by both the Cost Preview and every send path. See `docs/sms-pricing.md` and
`docs/sms-pricing-final-report.md`.

**Residual debt disclosed by this work, not fixed by it:**

- **TD-070 — `ellsms_subscriptions.effective_organization_id` is a STORED generated column, and the
  mysqldump this project ships cannot round-trip one that holds data. — CLOSED 2026-08-10.**
  *Root cause:* the mariadb-client `mysqldump` in `docker/Dockerfile` emits generated columns as
  ordinary column data, and MySQL then rejects the resulting INSERT with *"The value specified for
  generated column ... is not allowed"*. Any install with at least one subscription row produced a
  backup that could not be restored — and the backup itself succeeded every check
  (`cron/backup.php` verifies exit code, trailer and checksum), so the defect only surfaced during
  recovery. Found while building the SMS pricing tables, which used the same technique and had
  seeded rows on day one; those were fixed immediately, subscriptions were recorded here.
  *Fix:* `db/migrations/2026_08_10_td070_subscription_restore_safety.sql` converts the column to an
  ordinary nullable column in place (`ALTER ... MODIFY`, which retains every value and never drops
  the UNIQUE index), and `billing_effective_organization_id()` in `app/Billing.php` now derives it on
  every status-changing write. The one-effective-subscription guarantee is unchanged and still
  enforced by `uniq_effective_subscription`.
  *Proof:* `SubscriptionLegacySchemaUpgradeTest` takes a real backup of a genuinely pre-TD-070
  database with subscription rows, shows the restore fails, applies only this migration, and shows
  the same stack then restores byte-for-byte; `RestoreDisasterRecoveryTest` now seeds an effective
  and a historical subscription through the full backup/DROP/restore cycle.
  See `docs/td-070-restore-safety-closure.md`. A trigger-based alternative was tested and rejected
  (creating one needs SUPER while binary logging is on, and dumping one needs TRIGGER) — that
  rejection is what leaves the single residual difference documented in §Residual of that file.
- **No portability/HLR lookup.** Operator detection is a configured prefix classification and says so
  (`operator_source = 'prefix'`); it can disagree with a ported number's real current carrier. Adding
  a real lookup is a separate feature with its own cost, latency and failure-mode questions.
- **No smart routing, failover, or provider health awareness.** Deliberately excluded. Route selection
  is explicit configuration; the schema (`priority` columns on providers/routes) leaves room for it.
- **Overlapping price periods are prevented by the admin flow and reported by the integrity check,
  not enforced by a constraint.** MySQL has no range-exclusion constraint; the unique index only
  prevents duplicate start instants for one (route, operator).
- **`ellsms_sms_price_snapshots` has no retention policy.** It grows with send volume. It is
  financial history, so pruning it needs the same care as the wallet ledger — out of scope here, and
  deliberately not wired into `cron/db-cleanup.php`, which never touches financial rows.

## How to use this register

Each phase above is sized to become one `/make-plan` → `/do` cycle when the team is ready for it —
`PATHFINDER-2026-07-26/04-handoff-prompts.md` already has ready-to-run prompts for several Phase 8
items. Re-run the relevant discovery queries in `docs/database-audit.md` immediately before
starting Phase 9, since row counts/orphan counts will have changed since this review. This
register should be revisited (not necessarily rewritten) after each phase ships, since fixing one
item can surface or retire others — e.g. Phase 3's wallet fix will change what TD-006 even means.
