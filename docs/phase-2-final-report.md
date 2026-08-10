# ELLSMS — Phase 2 Final Report: Authorization, Authentication & Security Hardening

**Date:** 2026-07-27
**Scope:** Remediate the most important security weaknesses identified in `docs/security-review.md`
(produced during Phase 1) — cross-user authorization, admin scope, session hardening, 2FA, rate
limiting, and backend service authentication. This was an implementation phase, not another
audit: it modifies application code and adds regression tests. Wallet/ledger redesign, job-queue
redesign, Redis/Kafka, a framework rewrite, a UI redesign, and any Phase 3 work were explicitly out
of scope and were not touched.

---

## 1. Executive Summary

Both CRITICAL findings from the Phase 1 security review are fixed: the `inbox.php` cross-user
message-exposure bug (any user could read every other user's inbound SMS when their legacy
`originator` field was blank — the common case) and the `users.php` unscoped admin-action bug (an
ELLSMS admin could read/reset/credit-adjust any backend account, not just ones ever granted ELLSMS
access). A new, closely related HIGH-severity gap discovered *during* this remediation — sender-line
authorization was never revalidated at the moment a queued/scheduled job actually executed, only
(sometimes) at creation time — is also fixed.

Beyond the two CRITICALs, this phase shipped: a centralized, unit- and integration-tested
authorization layer (`app/authorization.php`); session hardening (secure-cookie auto-detection,
strict mode, idle + absolute timeouts); 2FA hardening (hashed codes, durable per-challenge attempt
counter, resend supersession, replay-proofing); a DB-backed rate limiter with no new infrastructure
dependency, applied to login/2FA/the legacy send API; POST+CSRF-protected logout; security response
headers (CSP/X-Frame-Options/HSTS/etc.); client-side HMAC request signing for ELLSMS→backend calls;
an upload-validation consistency fix; and an expanded audit-logging surface.

Two items are explicitly **partially** fixed, not fully, because completing them requires the
external backend platform's cooperation, which is outside this repository: password-hash
modernization (legacy SHA-256 remains the sole authoritative login check; a shadow Argon2id
verifier table is now opportunistically populated for a future coordinated cutover) and backend
API authentication (ELLSMS now signs its outbound requests; no verifier exists on the backend side
in this repo). Both are documented precisely, with the exact contract the backend side would need
to implement.

Nothing here touches the wallet/credit model, the job queue's architecture, or introduces Redis/
Kafka/a framework — all explicitly out of scope per this phase's own ground rules. **Recommendation:
Phase 3 (if the team chooses to proceed with it) can begin** — see section 18.

## 2. Security Issues Fixed

| Finding | Area | Fix |
|---|---|---|
| `docs/security-review.md` #1 (CRITICAL) | `inbox.php` cross-user IDOR | `allowed_originators()` fail-closed scoping; zero-originator users get `1=0`, not "no filter" |
| `docs/security-review.md` #2 (CRITICAL) | `users.php` unscoped admin actions | `resolve_ellsms_managed_user()` gate on every mutating action + the GET edit view; `can_demote_or_revoke()` blocks self-lockout |
| `docs/security-review.md` #3 (HIGH) | No rate limiting anywhere | DB-backed sliding-window limiter on login, 2FA verify/resend, legacy send API |
| `docs/security-review.md` #7 (MEDIUM) | 2FA attempts/codes session-resettable | Durable per-challenge `attempts` column, hashed codes, resend supersession, replay-proof consumption |
| `docs/security-review.md` #8 (MEDIUM) | Session: no `secure` flag, no absolute lifetime | Dynamic `secure` flag, `use_strict_mode`, idle + absolute timeout |
| `docs/security-review.md` #11 (LOW) | `logout.php` no CSRF check | POST + `csrf_check()` required; GET shows a safe confirmation page |
| `docs/security-review.md` #12 (LOW) | No security response headers | `send_security_headers()`: nosniff, Referrer-Policy, X-Frame-Options, CSP, conditional HSTS |
| `docs/security-review.md` #13 (LOW) | `slides.php` upload validator missing MIME fallback | Matched to `kyc_store_upload()`'s fallback behavior; switched to `AppException` |
| `docs/security-review.md` #15 (HIGH, new) | Sender-line authorization not revalidated at execution time | `can_use_originator()` check inside `dispatch_message()`; `is_backend_account_active()`/`has_panel_access()` checks in every background execution path |

## 3. Security Issues Partially Fixed

| Finding | Why only partial | What exists today |
|---|---|---|
| `docs/security-review.md` #4 (HIGH) — password hashing | Backend platform authenticates against the same `user_.password` column independently; ELLSMS cannot unilaterally change its format without a coordinated migration | `ellsms_password_verifiers` table + `backend_verify_password_and_upgrade()` opportunistically stores a modern Argon2id verifier on every successful login. Nothing reads it yet; legacy SHA-256 remains the sole authoritative check. |
| `docs/security-review.md` #5 (HIGH) — backend API authentication | No backend-side verifier exists in this repository (separate codebase) | `backend_service_auth_headers()` adds HMAC-SHA256-signed `X-Ellsms-Service-Id`/`X-Ellsms-Timestamp`/`X-Ellsms-Signature` headers, opt-in via env vars, backward compatible when unset. Documented verification contract in `app/backend.php`'s docblock: reject unknown service id, reject `\|now - timestamp\|` beyond ~5 minutes, `hash_equals()` constant-time comparison, reject replays. |
| `docs/security-review.md` #12 (LOW) — security headers | CSP still allows `'unsafe-inline'` for script/style | Real, disclosed debt (`docs/technical-debt.md` TD-036) — the app uses inline `<script>`/event-handler attributes/`style=""` widely; tightening further requires migrating those, a larger change than adding headers |

## 4. Remaining Security Issues (unchanged, explicitly out of this phase's scope)

- **#6 (HIGH)** Payment callback credit increment not transactional — wallet/credit changes were
  explicitly excluded from this phase; reserved for Phase 3.
- **#9 (MEDIUM)** Secrets at rest in `ellsms_settings` (plaintext ZarinPal/Telegram credentials) —
  different threat model than the repo-secrets scan (which re-confirmed clean); not in this phase's
  authorization/authentication scope.
- **#10 (LOW)** Five raw string-interpolated SQL statements (not currently exploitable) — untouched,
  not on an authorization/auth path.
- TD-003/TD-004 (payments), TD-005/TD-006 (wallet), TD-007–TD-010 (job queue), TD-014 (shared-DB
  architecture), TD-018–TD-022 (duplication/maintainability not related to authorization), TD-024–
  TD-028 (database hygiene), TD-030/TD-033/TD-034 (secrets-at-rest, xlsx decompression,
  bootstrap-admin race) — all unchanged; see `docs/technical-debt.md`'s Status column.

## 5. Authorization Changes

New module `app/authorization.php` is the single authoritative rule for every authorization
decision in this phase:

- `user_assigned_numbers()` / `allowed_originators()` — admin gets `['*']`; a regular user gets
  their `ellsms_numbers.assigned_user_id` rows, falling back to the legacy
  `ellsms_meta.originator` only if none exist, and an **empty array** (not "no filter") otherwise.
- `can_use_originator()` — the single check `dispatch_message()` (the function every send path
  funnels through) now runs before every send, live or from a background worker.
- `can_view_inbound_message()` — same rule `inbox.php` uses to build its `WHERE` clause.
- `resolve_ellsms_managed_user()` — `null` unless the target has an `ellsms_meta` row with
  `panel_access = 1`; the gate every mutating action in `users.php` now goes through except the two
  deliberate exceptions (`grant`, `create_account`).
- `can_demote_or_revoke()` — blocks an admin from revoking/demoting their own account.
- `is_backend_account_active()` / `has_panel_access()` — pure fail-closed checks (`null` input →
  `false`) used both in `current_user()` and every background execution path.

`public/inbox.php`, `public/users.php`, `public/send.php`, `public/new-send.php`,
`public/p2p-send.php`, `public/smart-send.php`, `public/autoreply.php`, `app/backend.php`
(`dispatch_message()`, `run_due_schedules()`, `autoreply_process_one()`, `bulk_send_one_item()`)
all now route through this module instead of five+ independent, subtly-divergent implementations.

## 6. Session/Auth Changes

- `session_set_cookie_params()`: `secure` is now `request_is_https()` (checks `$_SERVER['HTTPS']`
  then `X-Forwarded-Proto`) instead of always-false; `ini_set('session.use_strict_mode', '1')` added.
- Idle timeout (`SESSION_IDLE_TIMEOUT_SECONDS`, default 1800s) and absolute timeout
  (`SESSION_ABSOLUTE_TIMEOUT_SECONDS`, default 43200s) enforced via `$_SESSION['_last_activity']`/
  `$_SESSION['_created_at']`. Missing legacy keys default to "now," not "already expired," so
  existing sessions survive the deploy instead of being force-logged-out.
- `session_mark_authenticated()` resets the absolute-timeout clock specifically at login/2FA-success
  time (`login.php`, `verify-2fa.php`, `bootstrap-admin.php`), not at first anonymous visit — a
  deliberate design correction made during implementation (an anonymous session browsing for hours
  must not count against a user the moment they log in).
- `backend_verify_password_and_upgrade()` replaces `backend_verify_password()` at every login call
  site; verifies via the legacy scheme (unchanged, authoritative), then opportunistically stores a
  modern verifier — see section 3.
- `public/logout.php`: POST + `csrf_check()` required; GET shows a safe confirmation page (backward
  compatible with old bookmarked/linked GET logout URLs); session cookie explicitly cleared via
  `setcookie(..., time()-42000, ...)` after `session_destroy()`. `app/views/header.php`'s logout
  link is now a form, not an anchor.

## 7. 2FA Changes

- Migration `2026_07_27_2fa_hardening.sql`: `code_hash` (SHA-256) replaces the plaintext `code`
  column (dropped); adds durable `attempts` and `superseded_at`.
- `send_2fa_code()` supersedes every prior unconsumed, non-superseded code for the user before
  issuing a new one — at most one code is ever valid at a time.
- `verify_2fa_code()`: attempt counter lives on the database row (`TWOFA_MAX_ATTEMPTS = 5`), not
  `$_SESSION['twofa_attempts']` — cannot be reset by a session restart. A challenge that hits the
  cap is permanently dead (logged as `auth.2fa.lockout`) regardless of what's submitted next.
  Successful verification sets `consumed = 1` — replay-proof.
- Never logs the actual code value anywhere.
- `public/verify-2fa.php` adds rate limiting on both resend (`2fa_resend:user:*`) and verify
  (`2fa_verify:user:*`) — the cross-challenge ceiling that survives even a full login restart.

## 8. Rate Limiting Changes

- New module `app/rate_limit.php`, DB-backed sliding window, no Redis/new infrastructure —
  `ellsms_rate_limits` table (migration `2026_07_27_rate_limits.sql`).
- `rate_limit_hit(bucket, maxAttempts, windowSeconds)`: inserts a row, prunes stale rows, counts
  rows within the window, returns whether the count is still within the limit. **Fails open** (not
  closed) if the check itself errors (e.g., migration not applied), logged via
  `Logger::error('rate_limit.check_failed', ...)` — a deliberate, documented choice so a DB hiccup
  degrades to "no rate limiting" rather than locking every user out.
- Buckets always combine an IP dimension and an account/session dimension — never IP alone (NAT),
  never session alone (an attacker can restart it).
- Applied to: `public/login.php` (`login:ip:*`, `login:username:*` — default 10/900s),
  `public/verify-2fa.php` (`2fa_verify:user:*` default 10/900s, `2fa_resend:user:*` default
  5/3600s), `public/sms/url_send.html` (`api_send:ip:*`, `api_send:username:*` — default 30/300s,
  with `Retry-After` header and a new `-8` error code on block).
- All limits configurable via env vars with safe defaults (see section 16).

## 9. Backend Authentication Changes

`backend_service_auth_headers(string $rawBody): array` (`app/backend.php`), called from both
`backend_api_send()` and `backend_create_account()`. Returns `[]` (identical to pre-Phase-2
behavior) unless both `BACKEND_SERVICE_ID` and `BACKEND_SERVICE_SECRET` env vars are set; when set,
adds:

- `X-Ellsms-Service-Id: {id}`
- `X-Ellsms-Timestamp: {unix time}`
- `X-Ellsms-Signature: {hex HMAC-SHA256 of "{timestamp}\n{rawBody}", keyed with the secret}`

**This is client-side only.** End-to-end authentication of this hop is not complete until the
backend platform implements a verifier that: rejects an unknown service id; rejects a timestamp
more than ~5 minutes from its own clock; recomputes the HMAC and compares with a constant-time
comparison (`hash_equals()` or equivalent); rejects a replay of a previously-seen
timestamp+signature pair. See section 3 for status.

## 10. Database Migrations

All under `db/migrations/`, documented in `db/migrations/README.md`, never auto-applied (see
`make db-migrations-show` / `make db-migrations-apply`):

| File | Adds |
|---|---|
| `2026_07_27_password_verifiers.sql` | `ellsms_password_verifiers` (user_id PK, verifier, algo, updated_at) |
| `2026_07_27_2fa_hardening.sql` | `ellsms_2fa_codes.code_hash`/`.attempts`/`.superseded_at`; **drops** the old plaintext `code` column |
| `2026_07_27_rate_limits.sql` | `ellsms_rate_limits` (id, bucket, created_at, KEY(bucket, created_at)) |

All idempotent (`information_schema` existence checks, matching `db/ellsms_extra.sql`'s own
pattern), scoped strictly to ELLSMS-owned tables (never `user_`/`domain`/`outbound_message`/
`inbound_message`). The 2FA migration's column drop is the one non-purely-additive change in this
set — justified because that column only ever held single-use, 5-minute-lived challenges.

## 11. Files Created

- `app/authorization.php`, `app/rate_limit.php`
- `db/migrations/2026_07_27_password_verifiers.sql`, `2026_07_27_2fa_hardening.sql`,
  `2026_07_27_rate_limits.sql`, `db/migrations/README.md`
- `tests/Unit/AuthorizationHelpersTest.php` (9 tests), `RateLimitHelpersTest.php` (8 tests),
  `BackendServiceAuthTest.php` (7 tests), `SessionSecurityTest.php` (12 tests)
- `tests/Integration/IntegrationTestCase.php`, `AuthorizationIntegrationTest.php` (11 tests),
  `RateLimitIntegrationTest.php` (5 tests), `TwoFactorIntegrationTest.php` (9 tests)
- `tests/fixtures/integration_schema.sql`, `tests/integration-bootstrap.php`, `phpunit.integration.xml`
- `docs/phase-2-final-report.md` (this file)

## 12. Files Modified

- `app/bootstrap.php` — session hardening, `send_security_headers()`,
  `backend_verify_password_and_upgrade()`, requires for the two new modules
- `app/backend.php` — `dispatch_message()` originator check, `run_due_schedules()`/
  `autoreply_process_one()`/`bulk_send_one_item()` panel-access revalidation,
  `backend_service_auth_headers()`, 2FA storage rewrite
- `public/inbox.php` — fail-closed scoping (CRITICAL fix)
- `public/users.php` — `resolve_ellsms_managed_user()`/`can_demote_or_revoke()` gating (CRITICAL fix)
- `public/login.php` — rate limiting, `backend_verify_password_and_upgrade()`,
  `session_mark_authenticated()`
- `public/verify-2fa.php` — durable attempts, rate limiting, `session_mark_authenticated()`
- `public/logout.php` — POST+CSRF, confirmation page, cookie clearing
- `public/bootstrap-admin.php` — `backend_verify_password_and_upgrade()`,
  `session_mark_authenticated()` (two-line change; rest of the file is Phase 1)
- `app/views/header.php` — logout link → form
- `public/sms/url_send.html` — rate limiting, `-7`/`-8` error codes, `Retry-After`
- `public/slides.php` — upload validator MIME fallback, `AppException`
- `public/send.php`, `new-send.php`, `p2p-send.php`, `smart-send.php`, `autoreply.php` —
  consolidated onto `user_assigned_numbers()`/`allowed_originators()`
- `.env.example` — new env vars (section 16)
- `docker-compose.yml` — new env vars wired into `app`/`worker` services
- `Makefile`, `README.md` — `db-migrations-show`/`db-migrations-apply` targets and docs
- `docs/security-review.md`, `docs/technical-debt.md` — findings marked FIXED/PARTIALLY FIXED/OPEN

## 13. Test Results (exact numbers)

- **Unit suite** (`vendor/bin/phpunit`, `phpunit.xml`): **90 tests, 152 assertions, OK** — 54
  pre-existing (Phase 1) + 36 new Phase 2 tests (9 + 8 + 7 + 12 across the four new files listed
  above).
- **Integration suite** (`vendor/bin/phpunit -c phpunit.integration.xml`, against a disposable
  MySQL 8.0 container): **25 tests, 48 assertions, OK** (11 + 5 + 9 across the three new files).
  Skips entirely (not a failure) if `ELLSMS_TEST_DB_HOST` is unset — never runs against a
  production database by accident.
- **PHP lint** (`make lint`): **67/67 files parse cleanly** (was 62 before Phase 2's 5 new PHP
  files under `tests/`).
- **Clean Composer install**: verified from a fully removed `vendor/` — `composer install`
  completes and reproduces `vendor/bin/phpunit` correctly from `composer.lock`.
- **Duplicate-code check**: no 3+ consecutive identical lines and no duplicate function names
  introduced anywhere touched by this phase (checked programmatically, see STEP 19 diff review).

## 14. Docker/Runtime Validation

All performed against a real, disposable stack (a throwaway MySQL 8.0 container loaded with
`db/ellsms_extra.sql` + all three Phase 2 migrations, plus a minimal backend-table stand-in for
`user_`/`inbound_message`/`domain`/`outbound_message`; `docker compose build`/`up` against this
project's real `docker-compose.yml` and `docker/Dockerfile`):

- `docker compose build`: succeeds for both `app` and `worker` images.
- `GET /health`: `200`, `{"checks":{"php":"ok","database":"ok"}}`.
- `GET /health/ready`: `503` with `backend_api: error` — correct, since `API_BASE_URL` was
  intentionally left blank in this validation environment (no real backend to reach); `php`/
  `database` checks both `ok`.
- Full live walkthrough: `bootstrap-admin.php` → grants first admin; `login.php` → succeeds,
  regenerates session, logs `auth.login.success`; 10 wrong-password attempts → 10th+ correctly
  blocked with the rate-limit message and `auth.login.rate_limited` logged; `inbox.php` → admin
  sees their own message, a second user assigned a *different* line sees zero of it, a third user
  with *no* assigned line gets the distinct "no sender line assigned" empty state (not a
  generic "no messages"); `users.php` → editing/resetting the password of an account that was
  never granted ELLSMS panel access is blocked (`?edit=` shows "not found," the POST no-ops, the
  password hash on that account is verified unchanged before and after); `logout.php` GET shows the
  confirmation page without destroying the session, POST without CSRF is rejected (400), POST with
  CSRF logs out and clears the cookie.
- `docker compose run --rm worker php cron/worker.php --once`: logs `worker.started` →
  `worker.shutdown` (`reason: once_mode_complete`), exits 0.
- `docker kill --signal=TERM` on the persistent worker container: logs
  `worker.signal_received` (`signal: 15`) → `worker.shutdown` (`reason: signal_15`), container
  exits with code 0 (graceful, not killed).
- Production error handling: a deliberately-triggered exception (a fixture gap, not an app bug —
  see below) returned a safe generic Persian error page with a request ID and **no stack trace**,
  while the real exception was logged server-side with full detail — confirming `ErrorHandler`'s
  production behavior is intact.
- Secrets scan: only match across the full tree (excluding `vendor/`) is the intentional literal
  `'topsecret'` test fixture in `tests/Unit/BackendServiceAuthTest.php`; `.env` confirmed gitignored
  and absent from git status.

Note: the validation database was a deliberately minimal stand-in for the real backend schema (this
project doesn't own `user_`/`inbound_message`/`domain`), so two fixture gaps (a missing
`received_at` column, a missing `domain` table) were hit and fixed in the *fixture*, not the app —
both were caught precisely because `ErrorHandler` degraded safely instead of crashing uncontrolled.
All Docker artifacts (containers, network, `.env`) were torn down after validation; none are part
of the final repo state.

## 15. Breaking Changes

- **`logout.php` via GET no longer destroys the session immediately** — it now shows a confirmation
  page with a POST form. Any external link/bookmark expecting instant GET-logout will instead land
  on the confirmation page and require one more click. This is the explicitly-required
  backward-compatible transition, not a silent break.
- **A user with zero assigned sender numbers and no legacy `originator` now sees zero inbound
  messages** in `inbox.php`, with a distinct explanatory empty state, instead of previously seeing
  *every* user's messages (the bug being fixed). Any legitimate workflow that depended on that
  unscoped visibility (there should be none — it was the vulnerability) will need the affected
  account's sender-line assignment corrected via Users → Grant/assign, not restored.
  administrators who could previously edit/reset accounts never granted ELLSMS panel access via
  `users.php` (an unintended capability, not a documented feature) can no longer do so.
- **Login/2FA/legacy-API callers can now receive HTTP 429 / rate-limit error responses** under
  sustained repeated failures — legitimate users operating normally are extremely unlikely to hit
  the default thresholds (10 login attempts/15min, 10 2FA verifies/15min, 5 2FA resends/hour, 30
  API sends/5min), but a caller that was already retrying aggressively on failure will now be
  throttled rather than allowed to continue indefinitely.
- No other flow's happy path changed — direct/bulk/smart/P2P/scheduled/recurring/auto-reply sends,
  contacts, KYC, payments, and legitimate admin operations all behave identically for
  already-authorized users (verified in section 14 and via static review, STEP 20).

## 16. Operational Configuration Changes

New environment variables (all in `.env.example` with safe defaults; also wired into
`docker-compose.yml`'s `app`/`worker` service `environment:` blocks as applicable):

| Variable | Default | Used by |
|---|---|---|
| `SESSION_IDLE_TIMEOUT_SECONDS` | 1800 | `app` |
| `SESSION_ABSOLUTE_TIMEOUT_SECONDS` | 43200 | `app` |
| `RATE_LIMIT_LOGIN_MAX` | 10 | `app` |
| `RATE_LIMIT_LOGIN_WINDOW_SECONDS` | 900 | `app` |
| `RATE_LIMIT_2FA_VERIFY_MAX` | 10 | `app` |
| `RATE_LIMIT_2FA_VERIFY_WINDOW_SECONDS` | 900 | `app` |
| `RATE_LIMIT_2FA_RESEND_MAX` | 5 | `app` |
| `RATE_LIMIT_2FA_RESEND_WINDOW_SECONDS` | 3600 | `app` |
| `RATE_LIMIT_API_SEND_MAX` | 30 | `app` |
| `RATE_LIMIT_API_SEND_WINDOW_SECONDS` | 300 | `app` |
| `BACKEND_SERVICE_ID` | blank (off) | `app` + `worker` (worker also calls `dispatch_message()`) |
| `BACKEND_SERVICE_SECRET` | blank (off) | `app` + `worker` |

**Required deployment action:** run `make db-migrations-show` to review, then
`make db-migrations-apply` against the real shared database before deploying this code — the app
does not auto-apply migrations on startup, and `ellsms_2fa_codes`/`ellsms_rate_limits`/
`ellsms_password_verifiers` must exist before login/2FA/rate-limiting will work correctly (rate
limiting fails *open*, i.e. silently permissive, if its table is missing — not a hard failure, but
not the intended protection either). No other manual step is required; all new env vars have safe
defaults and existing `.env` files continue to work unmodified (missing vars fall back to the
defaults above).

## 17. Rollback Considerations

- **Code rollback** (reverting this phase's commits) is safe on its own: none of the new columns/
  tables are read by the pre-Phase-2 code, so a rollback to the previous application version
  continues to work against a post-migration database unmodified.
- **Migration rollback** is *not* provided as automatic down-migrations (consistent with this
  project's existing `db/ellsms_extra.sql` convention — no down-migration tooling exists anywhere
  in this codebase). If a full rollback of the schema is ever needed:
  - `ellsms_rate_limits` and `ellsms_password_verifiers` can be dropped freely — nothing outside
    this phase's own code reads them.
  - `ellsms_2fa_codes`'s dropped plaintext `code` column **cannot be un-dropped with its data
    intact** — this was a deliberate, documented one-way change (single-use, 5-minute-lived
    challenges, never data worth preserving). Rolling back to pre-Phase-2 application code against
    a post-migration database would need the `code` column re-added (empty) for that old code path
    to not error, since it wasn't designed to expect the column missing.
- **Session cookie changes** (`secure`, `use_strict_mode`, idle/absolute timeout) degrade
  gracefully: a session created under the old code and read by the new code (or vice versa) does
  not force a mass logout — missing timeout-tracking keys default to "now," not "expired."
- **Rate limiting** fails open by design (section 8) — even a botched migration apply or a
  misconfigured DB does not lock users out; it silently disables the protection and logs the
  failure, which is itself a signal to watch for after deployment.

## 18. Phase 3 Readiness

This phase did not implement, design in detail, or begin Phase 3 (wallet/credit atomicity
redesign), per explicit instruction. Recommendation: **Phase 3 can begin** once the team is ready —
the two CRITICAL findings that would have been the more urgent blocker are now fixed, and nothing
in this phase's remaining open items (payment transactionality, TD-003/TD-004) blocks starting
wallet-redesign design work; if anything, TD-003/TD-004 are exactly what Phase 3 exists to address.
Two items worth the team's attention before or during Phase 3 planning, though not blockers:

- The backend-side verification contract for HMAC service authentication (section 9) needs
  backend-team buy-in and implementation — worth raising in parallel, not sequentially blocking.
- The password-hashing coordinated cutover (section 3) similarly needs backend-team coordination
  and is independent of Phase 3's wallet scope.
