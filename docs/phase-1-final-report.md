# ELLSMS — Phase 1 Final Report

Phase 1 covered STEP 1 through STEP 15 of the engineering-baseline effort: architecture analysis,
documentation, logging, error handling, configuration, database-transaction infrastructure, health
checks, worker reliability, operational metadata, a security review, a database audit, a technical
debt register, a test foundation, and developer commands. This report is the mandatory full
validation and close-out of that phase, performed after STEP 15.

## 1. Executive Summary

Phase 1 turned ELLSMS from a working-but-unobserved application into one with a real safety net:
structured logging, centralized error handling that never leaks internals in production, a
reusable transaction helper, health/readiness endpoints, a materially more reliable worker, a
starter test suite, and a full paper trail of what's actually wrong with the system today
(`docs/security-review.md`, `docs/database-audit.md`, `docs/technical-debt.md`).

**Backward compatibility:** every change was verified to preserve existing behavior — see
Section 9 (Test Results) and Section 10 (Breaking Changes) for the evidence. No intentional
breaking change was introduced.

**Is Phase 1 complete?** Yes, all 15 steps are implemented and this validation pass has run to
completion with everything checked below.

**Is it safe to begin Phase 2?** Conditionally yes — see Section 12's checklist, all items of
which are satisfied. "Safe to begin Phase 2" means the baseline is stable enough to build on, **not**
that the application is secure or production-hardened. It is explicitly neither. Two CRITICAL
authorization gaps (Section 4) remain live in the current codebase, unchanged by design — Phase 1's
job was to build the infrastructure to see and reason about problems like these, not to fix them
yet. Phase 2 exists specifically to close them.

## 2. Architecture Discovered

ELLSMS is a self-hosted, no-framework PHP 8.2 SMS panel that deliberately does not own its own
user database or talk to an SMS gateway directly:

- **ELLSMS application** — plain PHP pages under `public/`, shared helpers under `app/`, no
  Composer-managed runtime dependency (Composer is dev/test-only as of STEP 14/15).
- **Shared backend database** — ELLSMS attaches to a MySQL database owned and migrated by an
  external "backend" platform. It owns every `ellsms_*` table outright and reads (with two narrow,
  documented write exceptions) `user_`, `domain`, `outbound_message`, `inbound_message`.
- **Backend messaging API** — ELLSMS sends SMS by calling the backend's own REST API
  (`POST /api/messages/send`), never the underlying gateway directly.
- **Worker** — a single container (`cron/worker.php`) polling for due schedules, new inbound
  messages to auto-reply to, and queued bulk-send rows.
- **Payment provider** — ZarinPal, called directly from ELLSMS (the one external service ELLSMS
  integrates with on its own, not via the backend platform).

Full detail, with a Mermaid diagram and an explicit ownership table, is in `docs/architecture.md`.
Per-flow deep dives (entry point → validation → DB reads/writes → external calls → failure paths →
security concerns → race conditions) are in `docs/flows/` (authentication, send-message,
bulk-message, scheduled-message, autoreply, payment, credit).

## 3. Changes Made

**Configuration (STEP 4):** `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_VERSION`,
`WORKER_POLL_INTERVAL_SECONDS` added as environment variables (`.env.example`,
`docker-compose.yml`), all additive — no existing variable renamed. Found and fixed a real
pre-existing bug: `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` were documented as `.env`-configurable
but never actually passed into the `app` container's environment.

**Logging (STEP 5):** `app/Support/Logger.php` — a centralized, structured (JSON-lines) logger
with `debug/info/warning/error/critical` levels, automatic redaction of sensitive keys
(passwords, 2FA codes, API keys, payment secrets, KYC/document fields), and operational metadata
(`env`, `version`, `request_id`) on every entry. Every scattered `error_log()` call in the codebase
was replaced with it; new logging was added at previously-silent failure points (`dispatch_message()`
lifecycle, login failures, payment callback branches).

**Error handling (STEP 6):** `app/Support/ErrorHandler.php` + `app/Support/AppException.php` —
one global exception/error/shutdown handler, registered before the first DB connection attempt.
Production never shows raw exceptions/stack traces; `AppException` (a `RuntimeException` subtype)
marks messages that are safe to show verbatim. `kyc_store_upload()` was switched to throw it as a
zero-risk demonstration of gradual adoption.

**Database transaction infrastructure (STEP 7):** `db_transaction(callable $work)` in
`app/bootstrap.php` — begins/commits/rolls back automatically, nesting-safe. Adopted in
`bulk_queue_job()` and `number-categories.php`'s create/delete handlers (the latter fixing a
previously non-transactional delete). The wallet/credit path was explicitly left untouched per
direct instruction not to redesign it yet.

**Health checks (STEP 8):** `public/health.php` (liveness + DB), `public/health-ready.php`
(+ backend API reachability), backed by `app/Support/HealthCheck.php`. Two real bugs were found
and fixed during end-to-end Docker testing of this step: a directory/URL naming collision that
made `/health` 301-redirect instead of answering, and a check that could itself throw when the
database was unreachable.

**Worker reliability (STEP 9):** graceful SIGTERM/SIGINT handling via `pcntl` (added to the Docker
image), a configurable poll interval, startup/shutdown logs, and — found while implementing this —
`run_due_schedules()` gained per-row exception isolation it was previously missing (one bad
schedule no longer aborts the rest of that tick's batch).

**Operational metadata (STEP 10):** `app_version()`/`app_env()` surfaced in every log line, both
health endpoints, and the panel footer/login page. `app_debug()` was hardened so
`APP_ENV=production` forcibly disables debug output regardless of `APP_DEBUG` — verified in
Section 9 to hold even under real misconfiguration.

**Documentation (STEPs 1, 3, 11, 12, 13):** `docs/architecture.md`, `docs/flows/*.md` (7 files),
`docs/security-review.md`, `docs/database-audit.md`, `docs/technical-debt.md`,
`PATHFINDER-2026-07-26/*` (architecture audit artifacts).

**Testing (STEP 14):** PHPUnit via Composer (dev-only), 54 tests / 110 assertions covering pure
business logic (phone/originator normalization, SMS part costing, Jalali calendar, operator
detection, auto-reply matching/templating, `Logger` redaction).

**Developer commands (STEP 15):** `Makefile` (lint, test, check, docker build/up/down/logs,
worker-once, db-schema-show/db-tables/db-schema-apply with mutation clearly marked), a
`composer test` script, and a README "Developer Commands" section.

## 4. Security Findings

Full detail in `docs/security-review.md`. Re-verified fresh in this validation pass (Section 9 of
this validation) — every finding below still exists in the current codebase, unmodified by Phase 1.

### 🔴 CRITICAL — 2 findings (immediate Phase 2 blockers, see Section 12/13)

1. **`inbox.php` cross-tenant IDOR** — any regular user whose legacy `ellsms_meta.originator`
   field is empty (the normal state under the current numbers-pool model) sees every user's
   inbound messages system-wide, content included. `public/inbox.php:17`.
2. **`users.php` unscoped admin actions** — an ELLSMS admin can read/reset the password/change
   credit/edit KYC of any account in the shared backend database, not just ones ELLSMS granted
   access to. 5 of 7 `$id`-scoped mutating actions plus the GET edit view never check
   `panel_access`. `public/users.php:37-108,162-170`.

### 🟠 HIGH — 6 findings
Payment callback credit increment not transactional (real money-loss-on-crash risk, no
reconciliation); no rate limiting/lockout anywhere in the app; `url_send.html`'s GET-credentials +
brute-force error-code oracle; unsalted SHA-256 password hashing (inherited, needs coordinated
migration); zero authentication on ELLSMS→backend API calls; the shared-database architecture
coupling itself.

### 🟡 MEDIUM — 3 findings
2FA attempt counter/code validity is session-resettable; session cookie missing `secure` flag +
no absolute lifetime; secrets stored plaintext at rest in `ellsms_settings`.

### 🟢 LOW — 4 findings
Raw string-interpolated SQL in 5 spots (confirmed not currently exploitable); `logout.php` missing
CSRF (session-availability only, no data impact); no CSP/HSTS/X-Frame-Options anywhere; two minor
upload-validation inconsistencies.

### Positive finding
`kyc-photo.php` was read in full and found correctly implemented (auth check, filename-shape
validation as defense-in-depth, `nosniff` + no-cache headers) — called out explicitly rather than
omitted.

**These findings are NOT remediated in Phase 1 and were not touched during this validation pass**,
per explicit instruction — authorization behavior needs deliberate design and regression testing,
which belongs to Phase 2.

## 5. Technical Debt

Full register (34 items across 10 phases) in `docs/technical-debt.md`. Highest-priority items,
explicitly called out per the report requirements:

- **Authorization boundaries** — TD-001, TD-002 (the two CRITICAL findings above) → Phase 2.
- **Wallet/credit race condition** — TD-005 (non-atomic check-then-deduct in `dispatch_message()`,
  double-spend/negative-balance risk) → Phase 3, explicitly **not** Phase 2.
- **Payment atomicity/reconciliation** — TD-003, TD-004 → Phase 2 (addressable now with the
  `db_transaction()` infrastructure already built, doesn't require the wallet redesign).
- **Backend API authentication** — TD-015 (no credential on ELLSMS→backend calls) → Phase 6.
- **Shared database coupling** — TD-014 (the architecture itself) → Phase 6.
- **Worker/queue concurrency** — TD-007 (`run_bulk_send_pass()` missing atomic claim), TD-008
  (schedule cancel/finalize race) → Phase 4, explicitly not part of this phase's worker-reliability
  work (which improved isolation/shutdown, not the queue model itself).
- **Password hashing** — TD-016 → Phase 7 (coordinated with the backend team).
- **Rate limiting** — TD-011, TD-012, TD-013 → Phase 5.

## 6. Files Created

- `app/Support/Logger.php`, `AppException.php`, `ErrorHandler.php`, `HealthCheck.php`
- `public/health.php`, `public/health-ready.php`
- `composer.json`, `composer.lock`, `phpunit.xml`, `tests/bootstrap.php`, `tests/Unit/*.php` (7 test classes)
- `Makefile`
- `docs/architecture.md`, `docs/flows/*.md` (7), `docs/security-review.md`, `docs/database-audit.md`,
  `docs/technical-debt.md`, `docs/phase-1-final-report.md` (this file)
- `PATHFINDER-2026-07-26/*` (feature inventory, flowcharts, duplication report, unified proposal,
  handoff prompts — analysis artifacts from STEP 1)

## 7. Files Modified

`app/backend.php`, `app/bootstrap.php`, `app/tickets.php`, `app/zarinpal.php`,
`app/views/header.php`, `app/views/public_footer.php`, `cron/worker.php`, `public/login.php`,
`public/number-categories.php`, `public/zarinpal-callback.php`, `docker-compose.yml`,
`docker/Dockerfile`, `docker/entrypoint.sh`, `.env.example`, `.gitignore`, `README.md`.

Every one of these was individually re-read in full during this validation (Section 9 below) to
confirm no accidental duplication and no unintended behavior change.

## 8. Database Changes

**No schema, table, index, or foreign key was changed. No production data was touched.**
`db/ellsms_extra.sql` was not modified during Phase 1. `docs/database-audit.md` documents proposed
future migrations (e.g. a unique constraint on `ellsms_contacts`) — these are **proposals with an
explicit staged, do-not-blindly-apply plan**, not schema changes made in this phase. The only
database-adjacent code change was `db_transaction()` (an application-level helper) and its adoption
in two places (Section 3) — neither altered any table's shape.

## 9. Test Results

| Check | Result |
|---|---|
| PHP lint | **PASS** — 56 files checked, 0 syntax errors |
| PHPUnit | **PASS** — 54 tests, 110 assertions, 0 failures, 0 errors, 0 skipped |
| Docker build | **PASS** — image builds successfully; `pdo_mysql`, `zip`, `pcntl` all confirmed loaded |
| Health endpoints | **PASS** — all 4 paths (`/health`, `/health.php`, `/health/ready`, `/health-ready.php`) return valid JSON with correct HTTP status (503 under an unreachable DB) and zero leaked credentials/hostnames/stack traces |
| Worker `--once` | **PASS** — runs all 3 passes to completion, isolates 3/3 induced failures independently, exits 0, logs start+shutdown |
| Worker SIGTERM | **PASS** — real signal sent to a running worker process across multiple full tick cycles; finished its in-flight pass, correctly skipped starting the next pass, logged `worker.signal_received` + `worker.shutdown` (reason `signal_15`), process confirmed exited |
| Production error handling | **PASS** — verified with real application code (an actual uncaught `PDOException` from `login.php`'s `ellsms_has_admin()` against an unreachable DB) under `APP_ENV=production, APP_DEBUG=1` (misconfigured): safe generic message shown, full detail logged; contrasted against `APP_ENV=local, APP_DEBUG=1` showing the full trace; `AppException` shown safely; a PHP warning logged without disrupting output; a true engine-level fatal (memory exhaustion) caught via the shutdown handler and shown safely |
| Git diff duplication review | **PASS** — every modified file read in full in its final state; automated adjacent-duplicate-line scan across all touched files; duplicate function/class-name scan across `app/`; zero accidental duplicates found |
| Critical path regression review | **PASS** — see Section 3/Section 10; every core path either untouched or provably behavior-preserving |
| Secrets scan | **PASS** — `.env.example` placeholder-only; no private keys/AWS keys/bearer tokens/connection-string credentials found in the working tree **or full git history** |

## 10. Breaking Changes

**No intentional breaking changes identified.**

Operational changes administrators should know about:
- New optional environment variables: `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_VERSION`,
  `WORKER_POLL_INTERVAL_SECONDS`, `LOG_LEVEL` — all have safe defaults, none are required.
- `storage/logs/` is a new directory the app writes to (day-named JSON log files); ensure it stays
  writable the same way `storage/kyc/` already is (handled automatically by `docker/entrypoint.sh`).
- The Docker image now installs the `pcntl` PHP extension (needed for worker SIGTERM handling) —
  no action needed, just noting the image is not byte-identical to before.
- `composer.json`/`composer.lock`/`vendor/` now exist for development/testing only — the
  production application still requires none of it to run.
- One disclosed, low-probability behavioral nuance: pages now route ALL PHP warnings/notices
  through the new error handler instead of PHP's raw default display. If any page had a latent,
  currently-silent-in-practice warning that happened to print inline in its HTML output before
  (none were found in the STEP 1 audit or this review, but exhaustive live testing against a real
  database wasn't possible in this environment), that inline text would no longer appear — the
  warning is logged instead. This is a defensive improvement, not a functional regression, but is
  disclosed here for completeness.

## 11. Known Limitations

Deliberately **not** fixed in Phase 1 (all tracked in `docs/technical-debt.md` and
`docs/security-review.md`):
- The two CRITICAL authorization findings (Section 4).
- The wallet/credit TOCTOU race (explicitly deferred to Phase 3 per direct instruction).
- The bulk-send worker's missing atomic per-item claim and the schedule cancel/finalize race
  (queue-model changes, explicitly out of scope for the worker-reliability step).
- Payment reconciliation for abandoned ZarinPal sessions.
- Rate limiting / brute-force protection anywhere in the app.
- Password hashing migration (needs backend-team coordination).
- Backend API authentication (needs backend-team coordination).
- Test coverage is narrow by design — no test touches the database or the backend API yet (needs
  either a test database or a mocked HTTP client, neither of which exists).
- Code duplication identified in the STEP 1 audit (e.g. `p2p-send.php`/`smart-send.php`,
  5 copies of one `ellsms_numbers` query) was documented but not consolidated.

## 12. Phase 2 Entry Criteria

- [x] PHP lint passes
- [x] PHPUnit passes
- [x] Docker build passes
- [x] health checks verified
- [x] worker shutdown verified
- [x] no credentials accidentally committed
- [x] Phase 1 diff reviewed
- [x] final report completed

All criteria are satisfied. Phase 2 may begin **when the team is ready** — this report does not
itself start Phase 2.

## 13. Recommended Phase 2 Scope (not implemented — recommendation only)

Priority order:
1. **Authorization boundary fixes** — `inbox.php` data isolation (reuse the correct
   `ellsms_numbers`-based ownership logic already present in `autoreply.php`); `users.php`
   admin/backend-user scope restrictions (one `resolve_target_user()` gate before every
   `$id`-scoped action).
2. **Session security** — `secure` cookie flag (once HTTPS is confirmed in the target deployment),
   an absolute session lifetime for admin sessions.
3. **Authentication hardening** — begin the coordinated password-hashing migration path
   (transparent upgrade-on-login), pending backend-team engagement.
4. **2FA hardening** — move the attempt counter and code invalidation off session state and onto
   a per-account record.
5. **Rate limiting foundation** — one shared primitive covering login, `url_send.html`, and the
   public contact form.
6. **Backend service-to-service authentication** — add a credential/signature to ELLSMS→backend
   API calls, pending backend-team agreement on the mechanism.

**Wallet redesign is explicitly excluded from Phase 2** and will be handled in its own dedicated
phase (Phase 3), per direct instruction.
