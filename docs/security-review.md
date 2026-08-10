# ELLSMS — Security Baseline Review

This is a review, not a remediation — nothing in this document has been changed as part of
writing it. It supersedes nothing in `PATHFINDER-2026-07-26/` or `docs/flows/`; it re-verifies
the security-relevant subset of those findings directly against the current code (line numbers
below are current as of this review, not carried over from the earlier audit) and adds a few new
ones (backend API authentication, session cookie flags, secrets-at-rest). Every finding cites the
exact file/line it's grounded in. Where a control is implemented correctly, that's stated
explicitly rather than left implied — a security review that only lists problems is as misleading
as one that misses them.

A full authentication-model migration (replacing the shared SHA-256 scheme, adding real rate
limiting infrastructure, etc.) is explicitly out of scope for this phase — see each finding's
"Recommended future fix" for what that later work would involve.

> **Phase 2 update (2026-07-27).** This review was the input to Phase 2 (Authorization,
> Authentication & Security Hardening), which remediated most CRITICAL/HIGH/MEDIUM findings below.
> Per that phase's own ground rules, **no historical finding text below has been deleted or
> rewritten** — each finding now has its original "Current implementation"/"Risk" text intact,
> followed by a dated "**Phase 2 update**" block recording what changed, its status
> (FIXED / PARTIALLY FIXED / OPEN), and the exact code/tests that back that status. See
> `docs/phase-2-final-report.md` for the full phase report (test counts, migrations, env vars,
> rollback notes). One new finding (15) was added for a gap discovered *during* Phase 2 remediation
> (background workers not revalidating sender-line authorization), not present in the original
> STEP-1-era review.
>
> **Phase 3 update (2026-07-28).** Finding 6 (payment atomicity), explicitly deferred by Phase 2, is
> now FIXED — see that finding's own "Phase 3 update" block and `docs/phase-3-final-report.md` /
> `docs/wallet-architecture.md` for the full wallet ledger/reservation system this phase built.
>
> **Phase 10 update (2026-08-02).** Production Hardening, Security Closure & Release Safety — see
> `docs/phase-10-final-report.md` and `docs/production-hardening.md`. Finding 5 (backend API
> authentication) remains **PARTIALLY FIXED**, unchanged — the client-side HMAC signer this repo
> implements still has no backend-side verifier to check against; see
> `docs/production-hardening.md` §9 for the honest, undiluted status and the deployment
> requirement/checklist for whoever operates that side. One new HIGH-severity issue was found and
> fixed during this phase's own risk inventory (not present in the original review, since it wasn't
> introduced until later phases added `app/rate_limit.php`): `client_ip()`/`request_is_https()`
> trusted `X-Forwarded-For`/`X-Forwarded-Proto` from any client unconditionally, a real rate-limit
> bypass — closed with `TRUSTED_PROXY_IPS` (fail-closed by default; see `docs/production-hardening.md`
> §4). Two pre-existing MEDIUM items from `docs/technical-debt.md` (TD-033 upload zip-bomb,
> TD-034 bootstrap-admin race) were also fixed this phase with regression tests.

## Summary

| # | Area | Original severity | Status |
|---|---|---|---|
| 1 | Authorization — `inbox.php` cross-tenant IDOR | **CRITICAL** | **FIXED** (Phase 2) |
| 2 | Admin authorization — `users.php` unscoped account control | **CRITICAL** | **FIXED** (Phase 2) |
| 3 | Rate limiting — absent across every auth/abuse surface | **HIGH** | **FIXED** (Phase 2) |
| 4 | Password hashing — unsalted SHA-256 | **HIGH** | **PARTIALLY FIXED** (Phase 2) |
| 5 | Backend API authentication — no credential on outbound calls | **HIGH** | **PARTIALLY FIXED** (Phase 2) |
| 6 | Payment callback — credit increment not transactional | **HIGH** | **FIXED** (Phase 3) |
| 7 | 2FA — attempt counter and code validity are session-resettable | **MEDIUM** | **FIXED** |
| 8 | Session security — missing `secure` cookie flag, no absolute lifetime | **MEDIUM** | **FIXED** |
| 9 | Secrets handling — plaintext at rest in `ellsms_settings` | **MEDIUM** | OPEN (unchanged — different threat model than the STEP 15 repo secrets scan) |
| 10 | SQL injection — raw-interpolated queries (not currently exploitable) | **LOW** | OPEN (unchanged, out of this phase's scope) |
| 11 | CSRF — `logout.php` has no token check | **LOW** | **FIXED** |
| 12 | XSS / security headers — consistent escaping, no CSP/HSTS/X-Frame-Options | **LOW** | **FIXED** |
| 13 | File upload validation — solid, minor gaps | **LOW** | **FIXED** |
| 14 | KYC file access — implemented correctly | **none — positive finding** | unchanged, still correct |
| 15 | Sender/originator authorization not revalidated at send/execution time | *(discovered during Phase 2)* | **FIXED** |

---

## 1. Authorization — `inbox.php` cross-tenant data leak

**Severity: CRITICAL**

**Risk.** Any authenticated non-admin user can read the full inbound-message history of every
other user/tenant on the shared platform, including message content — a direct, low-bar
confidentiality breach requiring no special privilege.

**Current implementation.** `public/inbox.php:17`:
```php
if (!is_admin() && $me['originator'] !== '') { $where[] = 'destination = ?'; $params[] = ...; }
```
This is the *only* ownership filter applied before the query at line 21. `$me['originator']`
comes from `ellsms_meta.originator`, documented in `db/ellsms_extra.sql:148-150` as a **legacy
single-line fallback** — the current model assigns numbers via `ellsms_numbers.assigned_user_id`
instead, leaving `ellsms_meta.originator` blank for any user set up under the current model. When
it's blank, the `if` condition is false and **no filter is applied at all** — the query at line 21
runs unscoped, returning every row in `inbound_message` for every user. `public/autoreply.php:9-17`
and `public/reports.php:17` both derive ownership correctly (numbers pool + fallback, and a real
`sender_user_id` foreign key, respectively) — `inbox.php` is the one page that never queries
`ellsms_numbers` at all, which is why this is the one broken implementation, not a deliberately
different design.

**Recommended future fix.** Extract one `allowed_originators(array $user): array` helper (the
correct logic already exists in `autoreply.php:9-17` — reuse it, don't reimplement) and use it to
build an `IN (...)` filter for non-admins, with **no-rows-returned as the default when the list is
empty**, never "no filter." Apply the same helper to the five other places that independently
query `ellsms_numbers` for a dropdown (`send.php`, `new-send.php`, `p2p-send.php`,
`smart-send.php`, `autoreply.php` itself) so this can't drift again.

**Phase 2 update (2026-07-27): FIXED.** `allowed_originators(array $user): array`
(`app/authorization.php`) is now the single authoritative rule — admins get `['*']`, everyone else
gets their `ellsms_numbers.assigned_user_id` rows, falling back to the legacy
`ellsms_meta.originator` only if they have no assigned numbers, and an **empty array** (not "no
filter") if they have neither. `public/inbox.php` was rewritten to fail closed: when the allowed
list is empty it appends a literal `1 = 0` WHERE clause (zero rows, with a distinct "no sender line
assigned" empty-state message) instead of silently applying no filter; when non-empty it builds a
parameterized `destination IN (?, ?, ...)` clause. The same helper now backs `send.php`,
`new-send.php`, `p2p-send.php`, `smart-send.php`, and `autoreply.php` (closing the exact "apply to
the five other places" recommendation above) and `can_use_originator()` — the single check
`dispatch_message()` runs before *every* send path, including at background-execution time for
scheduled/bulk/auto-reply jobs (see finding 15). Verified by
`tests/Integration/AuthorizationIntegrationTest.php` (admin sees `['*']`, assigned-number scoping,
legacy-originator fallback, zero-originator user gets zero access) — proving User A cannot see User
B's messages and a zero-sender user gets zero rows, not just an empty-looking page.

## 2. Admin authorization — `users.php` unscoped account control

**Severity: CRITICAL**

**Risk.** Any account with ELLSMS admin access (a role intended to be scoped to *ELLSMS panel*
administration) can read and modify **any** account on the shared multi-tenant backend platform —
including ones that were never granted ELLSMS access at all — via password reset, credit
adjustment, and KYC data. This defeats the entire purpose of the `ellsms_meta.panel_access` gate.

**Current implementation.** The GET edit view, `public/users.php:162-170`, loads any `user_` row
by `$_GET['edit']` with a `LEFT JOIN ellsms_meta` and **no `WHERE panel_access = 1`** — contrast
with the correctly-scoped listing query at `users.php:179-186`, which does filter on it. The
mutating actions keyed off the same unchecked `$id` compound this: `toggle_2fa` (line 49),
`originator` (61), `credit` (67-71, `UPDATE user_ SET currentcredit = ...`), `password` (74-83,
`UPDATE user_ SET password = ...`), and `kyc_save` (85-108) all act on `$id` with **no
`panel_access` check and no self-protection check** — only `revoke` (line 37) and `toggle_admin`
(line 43) even check `$id !== $me['id']`. An ELLSMS admin can therefore enumerate `?edit=1,
2, 3...` and reset the password of any backend account system-wide, whether or not it was ever
meant to be reachable from this panel.

**Recommended future fix.** Add one `resolve_target_user(int $id): array` gate — 404 unless the
target has an `ellsms_meta` row with `panel_access = 1` — and route every `$id`-scoped action
(including the GET edit view) through it before acting, replacing the current per-branch,
inconsistent ad hoc checks (2 of 7 have any check at all today).

**Phase 2 update (2026-07-27): FIXED.** `resolve_ellsms_managed_user(int $targetId): ?array`
(`app/authorization.php`) is exactly the gate recommended above — it joins `user_` to
`ellsms_meta` and returns `null` unless `panel_access = 1`. `public/users.php`'s POST handler was
restructured so every mutating action (`revoke`, `toggle_admin`, `toggle_2fa`, `originator`,
`credit`, `password`, `kyc_save`) and the GET edit view now resolve the target through this helper
first and no-op with a flash error if it returns `null`; `revoke`/`toggle_admin` additionally call
`can_demote_or_revoke($me, $id)` to block self-lockout/self-demotion. `grant` and `create_account`
remain the two narrowly-scoped, explicitly-documented exceptions (an admin must be able to grant
*initial* panel access to a not-yet-managed backend account — that's the one legitimate case where
acting on an unmanaged `user_` row is correct, not a gap). Every action now also logs via
`Logger::info()` (`user.grant_access`, `user.revoke_access`, `user.toggle_admin`,
`user.credit_adjusted`, `user.password_reset`, etc. — see finding 16 in `docs/phase-2-final-report.md`
for the full audit-logging list). Verified by `tests/Unit/AuthorizationHelpersTest.php`
(self-lockout logic) and `tests/Integration/AuthorizationIntegrationTest.php`
(`resolve_ellsms_managed_user()` returns `null` for both a non-existent id and an existing account
without `panel_access`).

## 3. Rate limiting — absent across every auth/abuse surface

**Severity: HIGH**

**Risk.** Credential stuffing against the login form, brute-forcing `url_send.html` (which
additionally exposes a working error-code oracle, see below), and unlimited scripted spam of the
public contact form (which relays straight to a real Telegram chat) are all currently unthrottled
beyond a flat per-request delay that does not scale with concurrency.

**Current implementation.** A repo-wide search for any rate-limit/lockout/throttle mechanism
(`rate.?limit|throttle|lockout|failed_attempts`, excluding the unrelated bulk-send pacing feature
which happens to reuse the word "throttle") returns nothing. The only mitigation anywhere is a
fixed `usleep(400000)` per failed attempt — `public/login.php:17`, `public/sms/url_send.html:57`,
`public/bootstrap-admin.php:16` — which is per-request, not per-account or per-IP, so it slows one
serial attacker but not a parallelized one. `public/sms/url_send.html` is the most exposed
instance: it's explicitly designed for unauthenticated, non-session, third-party callers
(`url_send.html:8-12`), has a distinguishable error code for "wrong credentials" (`-2`) versus
every later-stage failure (`-3` through `-6`, all only reachable once the password check already
passed — `url_send.html:56-78`), making it a usable oracle for confirming a guessed password
independent of whether the account can send anything. `public/contact.php` has no CAPTCHA or
per-IP/session throttle beyond `csrf_check()`.

**Recommended future fix.** A small, shared rate-limit primitive (e.g. a table or cache keyed on
`identifier + action`, checked before the password check in each of the three surfaces above) —
this is a natural extension of the infrastructure already built (`Logger`, `db_transaction()`),
not a new subsystem. `url_send.html`'s error codes could also collapse `-2`/`-3` into one value to
remove the oracle, independent of adding rate limiting.

**Phase 2 update (2026-07-27): FIXED.** `app/rate_limit.php` adds a DB-backed sliding-window
limiter (`ellsms_rate_limits`, migration `db/migrations/2026_07_27_rate_limits.sql`; no Redis, per
this phase's explicit constraint) keyed on `action:dimension:value` — every bucket combines an IP
dimension and an account/session dimension (never IP alone, since NAT means many users share one
IP; never session alone, since an attacker can just start a fresh session), matching the phase's
"never rely solely on IP/session" requirement. Wired into: `public/login.php` (`login:ip:*` and
`login:username:*`, default 10/900s), `public/verify-2fa.php` (`2fa_verify:user:*` default
10/900s, `2fa_resend:user:*` default 5/3600s), and `public/sms/url_send.html`
(`api_send:ip:*`/`api_send:username:*`, default 30/300s — closing this finding's specific
`url_send.html` callout; blocked requests get HTTP 429 with a `Retry-After` header and the new
`-8` error code). All limits are configurable via env vars with safe defaults (see
`.env.example`/`docker-compose.yml`) and fail **open** (not closed) if the limiter's own check
errors, logged via `Logger::error('rate_limit.check_failed', ...)` so a DB hiccup degrades to "no
rate limiting" rather than locking every user out — a deliberate, documented choice
(`app/rate_limit.php`'s own docblock). Verified by `tests/Unit/RateLimitHelpersTest.php` (bucket
key shape, IP resolution, config floor) and `tests/Integration/RateLimitIntegrationTest.php`
(allowed-under-threshold, blocked-over-threshold, bucket isolation, sliding-window pruning against
real MySQL). The `-2`/`-3` oracle collapse suggested above was not implemented (out of this
phase's scope — rate limiting the endpoint was the assigned remediation, not reshaping its error
codes); still open as a smaller, independent hardening item.

## 4. Password hashing — unsalted SHA-256

**Severity: HIGH**

**Risk.** If the shared database is ever read (backup exposure, a different vulnerability, an
insider) every account's password becomes crackable at GPU speed — SHA-256 with no salt and no
work factor is not a password hash, it's a fast general-purpose digest, and offers essentially no
resistance to rainbow-table or brute-force attacks compared to bcrypt/Argon2.

**Current implementation.** `app/bootstrap.php`, `backend_hash_password()`:
```php
function backend_hash_password(string $plain): string {
    return hash('sha256', $plain, true);
}
```
This is explicitly documented in the same file as inherited from the backend platform's own
placeholder scheme, not something ELLSMS chose, and the README's Production notes already flag it
as a known weak point requiring a **coordinated** change on both sides (ELLSMS can't unilaterally
switch hashing without breaking login against the shared `user_` table the backend platform also
authenticates against).

**Recommended future fix.** Coordinate a migration to `password_hash()`/Argon2id on both sides —
likely via a transparent upgrade-on-login path (verify against the old SHA-256 scheme once, then
re-hash with the new scheme and store that instead), rather than a one-shot mass migration that
would need every user's plaintext password, which isn't available. This is exactly the kind of
work explicitly deferred to a later phase per this project's own instructions.

**Phase 2 update (2026-07-27): PARTIALLY FIXED — supporting infrastructure only, login security
unchanged.** `backend_verify_password_and_upgrade()` (`app/bootstrap.php`) is called from
`public/login.php` in place of the old `backend_verify_password()`: it verifies against the
legacy SHA-256 scheme exactly as before (still the sole authoritative check — **this migration
does not change how login is authorized today**), then, only on success, opportunistically stores
a modern Argon2id (bcrypt fallback) verifier for that user in the new `ellsms_password_verifiers`
table (migration `db/migrations/2026_07_27_password_verifiers.sql`), wrapped in try/catch so a
missing migration never blocks login. Nothing reads this table to grant access yet. This is
explicitly **not** claimed as a fix for the underlying weak-hash risk — per this finding's own
"Recommended future fix," a real fix requires the backend platform's cooperation since it
authenticates against the same `user_.password` column independently, which this repo cannot do
unilaterally. The table exists so that by the time backend and ELLSMS teams agree on a real
rehash cutover, most active accounts already have a modern verifier ready instead of starting from
zero. Marked PARTIALLY FIXED, not FIXED, per this phase's own explicit instruction for this exact
scenario.

## 5. Backend API authentication — no credential on outbound calls

**Severity: HIGH**

**Risk.** If the backend REST API is ever reachable from anywhere other than ELLSMS itself
(network misconfiguration, another compromised container on the same Docker network, a future
change that exposes it), any caller can send SMS as an arbitrary `sender_user_id` or create
accounts via `POST /api/users/`, with zero application-level credential check at this hop — the
only thing currently preventing that is Docker network isolation, not anything in ELLSMS's own
code.

**Current implementation.** Both outbound calls to the backend API set only a content-type
header, no authentication:
```
app/backend.php:52:  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],   // backend_api_send()
app/backend.php:484: CURLOPT_HTTPHEADER => ['Content-Type: application/json'],   // backend_create_account()
```
No `Authorization` header, API key, or signature is sent or configured anywhere in this repo — a
repo-wide search for `Authorization|X-Api-Key|apikey` inside `app/backend.php` finds nothing.
`sender_user_id` in the request body (`app/backend.php:41-45`) is a plain integer supplied by
ELLSMS, trivially guessable by anyone who could reach this endpoint directly.

**Recommended future fix.** This is fundamentally a backend-platform-side change (the API would
need to accept and verify a shared secret/service token from ELLSMS), so it belongs in the same
coordinated-changes bucket as the password hashing above — flagging it now so it's on record
before a later phase, not attempting it unilaterally from the ELLSMS side.

**Phase 2 update (2026-07-27): PARTIALLY FIXED — client-side signing implemented, backend
verification not present in this repo.** `backend_service_auth_headers(string $rawBody): array`
(`app/backend.php`) is now called from both `backend_api_send()` and `backend_create_account()`,
adding `X-Ellsms-Service-Id`, `X-Ellsms-Timestamp`, and an `X-Ellsms-Signature`
(HMAC-SHA256 of `"{timestamp}\n{rawBody}"`, keyed with `BACKEND_SERVICE_SECRET`) to every outbound
request — but only when `BACKEND_SERVICE_ID`/`BACKEND_SERVICE_SECRET` env vars are set (backward
compatible: unset means the exact same unauthenticated headers as before, so nothing breaks for an
install that hasn't coordinated a rollout with the backend team yet). The documented contract for
what the backend must verify (reject unknown service id, reject if `|now - timestamp|` exceeds
~5 minutes, recompute the HMAC and compare with `hash_equals()`/constant-time comparison, reject
replays) is written in `app/backend.php`'s docblock and `docs/phase-2-final-report.md` — but **no
backend-side verification code exists in this repository** (the backend platform is a separate
codebase), so end-to-end authentication of this hop is not complete and this finding is marked
PARTIALLY FIXED, not FIXED, exactly per this phase's own instruction for this scenario. Verified
client-side by `tests/Unit/BackendServiceAuthTest.php` (headers absent when unconfigured,
well-formed and independently-recomputable HMAC when configured, tamper-evidence).

## 6. Payment callback — credit increment not transactional

**Severity: HIGH**

**Risk.** A crash between two specific statements permanently loses a customer's paid-for credit
with no automatic recovery — a real, quantifiable financial-integrity gap, distinct from (and not
mitigated by) the double-credit guard, which is solid.

**Current implementation.** `public/zarinpal-callback.php:40-44`:
```php
$claim = db()->prepare("UPDATE ellsms_payments SET status='paid', ref_id=? WHERE id=? AND status='pending'");
$claim->execute([$refId, $paymentId]);
if ($claim->rowCount() > 0) {
    db()->prepare('UPDATE user_ SET currentcredit = currentcredit + ? WHERE id = ?')
       ->execute([$payment['credits'], $me['id']]);
    ...
```
These are two independent, separately-autocommitted statements with no `db_transaction()` wrapper
(that helper now exists — see `docs/` from STEP 7 — but was deliberately not applied here per
explicit instruction not to touch the wallet in that step). If the process dies between them, the
row is permanently `status='paid'` (so the guard above will never retry it) while
`currentcredit` was never incremented, and no reconciliation job exists anywhere in this codebase
(`cron/worker.php` has zero payment-related code) to detect or fix it. The double-credit guard
itself — the atomic `WHERE id=? AND status='pending'` claim — is verified correct and not part of
this finding.

**Recommended future fix.** Wrap the claim UPDATE and the credit increment in one
`db_transaction()` call (the infrastructure for this already exists), and add a reconciliation
pass for payments still `pending` after N minutes (a customer who never returns to the callback
URL is currently invisible to the system forever). Both are explicitly future-phase items already
called out in `PATHFINDER-2026-07-26/04-handoff-prompts.md`.

**Phase 2 update (2026-07-27): OPEN — unchanged, explicitly out of scope for this phase.** Phase 2's
governing instructions explicitly excluded wallet/ledger/credit architecture changes ("no wallet
redesign, no ledger architecture" — reserved for a possible future Phase 3). This finding is
unrelated to authorization/authentication/session/2FA/rate-limiting/backend-auth, the six areas
Phase 2 was scoped to remediate, so it was deliberately left untouched rather than folded in as an
opportunistic fix. Still an accurate, live finding.

**Phase 3 update (2026-07-28): FIXED.** `payment_claim_and_credit()` (`app/zarinpal.php`) now wraps
the payment-row claim and the wallet credit in ONE `db_transaction()` — either both happen or
neither does, closing exactly the crash-between-two-statements gap this finding describes. The
wallet's own `idempotency_key` (`payment_credit:{paymentId}`) is a second, independent guard against
ever double-crediting the same payment. The "customer paid but never returned to the callback URL"
half of this finding is also closed: `cron/payments-reconcile.php` (`make payments-reconcile`)
recovers both that case (stale `pending` rows) and a new `verification_failed` state (STEP 14 —
split out from a bare `failed` specifically so a transient `zarinpal_verify()` failure is retryable
instead of a permanent dead end). See `docs/wallet-architecture.md` for the full design and
`tests/Integration/PaymentIntegrationTest.php` (duplicate-claim idempotency, `verification_failed`
retryability) and `tests/Integration/WalletConcurrencyTest.php` (a genuine cross-process
concurrency test proving concurrent debits against one account cannot both succeed).

## 7. 2FA — attempt counter and code validity are session-resettable

**Severity: MEDIUM**

**Risk.** An attacker who already has valid credentials (e.g. a leaked/reused password from
another breach) faces a weaker practical brute-force limit on the 6-digit 2FA code than the
"5 wrong attempts" figure suggests, because both the attempt counter and the set of currently-valid
codes reset every time the login step is repeated.

**Current implementation.** `public/verify-2fa.php:34-37`: the attempt counter lives in
`$_SESSION['twofa_attempts']`; hitting 5 clears the session and redirects to `/login.php`
(`verify-2fa.php:36`) rather than locking the account. Re-submitting the password on `login.php`
calls `send_2fa_code()` again (`login.php:26`), which starts an entirely fresh session state —
`twofa_attempts` is gone, so the counter restarts at 0. Separately, `verify_2fa_code()`
(`app/backend.php`) matches against **any** unconsumed, unexpired code for the user
(`ORDER BY id DESC LIMIT 1` over `WHERE ... consumed = 0 AND expires_at > NOW()`), and
`send_2fa_code()` never invalidates a previous code when issuing a new one — so pressing "resend"
multiple times leaves several codes simultaneously valid rather than just the latest one. Neither
of these is exploitable without the password already being known, which is the actual first line
of defense (see finding 4 on that layer's own weakness) — the 2FA layer is a weaker second factor
than "5 attempts total" implies, not that it can be bypassed outright.

**Recommended future fix.** Move the attempt counter (and the resend cooldown) to a
per-account/per-mobile record rather than per-session, so restarting login doesn't reset either
one, and have `send_2fa_code()` mark prior unconsumed codes for that user as consumed/invalid when
issuing a new one.

**Phase 2 update (2026-07-27): FIXED.** Migration `db/migrations/2026_07_27_2fa_hardening.sql`
adds `code_hash` (SHA-256, plaintext `code` column dropped), a durable per-challenge `attempts`
column, and `superseded_at`. `send_2fa_code()` (`app/backend.php`) now marks every prior
unconsumed, non-superseded code for the user as superseded before issuing a new one — exactly the
"mark prior codes invalid" recommendation above — so at most one code is ever valid at a time.
`verify_2fa_code()` was rewritten: the attempt counter lives on the database row, not
`$_SESSION['twofa_attempts']`, so it survives a session restart or a repeated login exactly as
recommended; once a specific challenge hits `TWOFA_MAX_ATTEMPTS` (5) it's permanently dead
(logged as a distinct `auth.2fa.lockout` event) regardless of session state, and a NEW login
naturally issues a fresh challenge with its own zero attempt count — paired with the new
per-user `2fa_verify`/`2fa_resend` rate limits (finding 3) for the cross-challenge ceiling.
Verification also now sets `consumed = 1` on success, making a code replay-proof. Never logs the
actual code value anywhere. Verified by `tests/Integration/TwoFactorIntegrationTest.php`: hash
storage (never plaintext), wrong code, attempt exhaustion, expiry, replay-after-success, and
resend-supersedes-prior-code, all against a real MySQL table.

## 8. Session security — missing `secure` cookie flag, no absolute lifetime

**Severity: MEDIUM**

**Risk.** If the panel is ever reachable over plain HTTP (misconfigured reverse proxy, a
transitional deployment step, a direct connection bypassing the intended TLS termination), the
session cookie has no application-level instruction preventing it from being sent unencrypted —
the app relies entirely on infrastructure-level HTTPS enforcement with no defense-in-depth of its
own. Separately, there is no maximum session lifetime independent of PHP's default garbage
collection, so a session can in principle persist indefinitely.

**Current implementation.** `app/bootstrap.php`:
```php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_name('ELLSMS_SESSION');
session_start();
```
`httponly` (blocks JS access, mitigating session-cookie theft via XSS) and `samesite=Lax`
(mitigates some CSRF vectors) are both set correctly. `secure` is not set, and no
`session.gc_maxlifetime`/absolute-expiry logic is configured anywhere in the codebase. This is
consistent with the project's own Production notes, which already instruct putting the panel
behind HTTPS via a reverse proxy — the gap is the lack of an app-level backstop if that's ever
misconfigured, not an assumption that HTTPS isn't intended.

**Recommended future fix.** Add `'secure' => true` to `session_set_cookie_params()` once HTTPS is
confirmed in the target deployment (this would break local HTTP-only dev unless guarded by
`app_env()`), and consider an absolute session lifetime (e.g. re-require login after N hours
regardless of activity) for admin sessions specifically, given they can reset other users'
passwords per finding 2.

**Phase 2 update (2026-07-27): FIXED.** `app/bootstrap.php` now sets `'secure' => request_is_https()`
dynamically (checks `$_SERVER['HTTPS']` then `X-Forwarded-Proto`, so it self-adjusts behind a
reverse proxy instead of needing a hardcoded `true` that would break local HTTP dev — addressing
this finding's own caveat about that), plus `ini_set('session.use_strict_mode', '1')` (rejects
uninitialized session IDs) alongside the pre-existing `httponly`/`samesite=Lax`. Both an idle
timeout (`SESSION_IDLE_TIMEOUT_SECONDS`, default 1800s) and an absolute lifetime
(`SESSION_ABSOLUTE_TIMEOUT_SECONDS`, default 43200s/12h — exactly the "absolute session lifetime"
recommended above) are now enforced, tracked via `$_SESSION['_last_activity']`/`$_SESSION['_created_at']`;
the absolute clock resets specifically at `session_mark_authenticated()` (called at login and 2FA
success, i.e. `session_regenerate_id(true)` time), not at first anonymous visit, so a long-lived
anonymous browsing session can't count against a user the moment they log in. Missing
legacy-session keys default to "now" rather than "already expired," so existing sessions aren't
force-logged-out on deploy. `logout.php` also now fully clears the session cookie (`setcookie(...,
time()-42000, ...)`) after `session_destroy()`. Verified by `tests/Unit/SessionSecurityTest.php`
(HTTPS detection, both timeout floors/defaults, and the idle-vs-absolute expiry boundary math).

## 9. Secrets handling — plaintext at rest in `ellsms_settings`

**Severity: MEDIUM**

**Risk.** If the shared database is ever exposed (an improperly-secured backup, a different
vulnerability elsewhere), the ZarinPal merchant ID and Telegram bot token are readable in plain
text, with no encryption-at-rest layer.

**Current implementation.** `db/ellsms_extra.sql:43-46`: `ellsms_settings.svalue` is a plain
`TEXT` column, no encryption. `public/settings.php:70,112` render these values back into ordinary
`<input type="text" value="...">` fields (not masked) — acceptable given the page is
`require_admin()`-gated (`settings.php:3`) and intended for admins to see/edit their own
configuration, but confirms the values are never obscured anywhere, consistent with the DB storing
them in the clear. Separately confirmed clean: no secret is ever committed to git (`.env` is
gitignored, `.env.example` contains placeholders only, verified against the full git history in
the STEP 1 audit) — this finding is specifically about at-rest storage in the database, not the
source tree.

**Recommended future fix.** If this project ever needs to defend against DB-dump exposure
specifically (a real but narrower threat model than the app itself being compromised), encrypt
these specific `ellsms_settings` values with an application-level key kept outside the database
(e.g. derived from an env var), decrypting only at the point of use in `zarinpal.php`/`telegram.php`.
Low priority relative to findings 1–6 above since it requires a separate compromise (DB access) as
a precondition.

**Phase 2 update (2026-07-27): OPEN — unchanged.** This finding is about at-rest encryption of
`ellsms_settings` values in the database, a different concern from the STEP 15 repo/source-tree
secrets scan (which re-confirmed clean during Phase 2: no committed secrets, `.env` gitignored,
`.env.example` placeholders only, including the newly-added `BACKEND_SERVICE_ID`/
`BACKEND_SERVICE_SECRET`/rate-limit/session env vars). Adding an application-level encryption
layer for these specific columns was not part of Phase 2's scope (authorization, authentication,
sessions, 2FA, rate limiting, backend service auth) and remains a future item.

## 10. SQL injection protection — raw-interpolated queries (not currently exploitable)

**Severity: LOW**

**Risk.** None demonstrated today — every interpolated value found is `(int)`-cast immediately
before use. This is flagged as a style/consistency gap and latent risk, not a proven
vulnerability, per this review's own instruction not to claim one without evidence.

**Current implementation.** The overwhelming majority of this codebase uses `PDO::prepare()` with
bound parameters throughout — confirmed across all six subsystem audits performed for the STEP 1
architecture review. Four call sites depart from that pattern by building SQL via string
interpolation instead: `public/schedules.php:12`, `public/autoreply.php:50` and `:56`,
`public/p2p-send.php:22`, `public/smart-send.php:22` — e.g.
`db()->exec("UPDATE ellsms_schedule SET status='cancelled' WHERE id={$id} AND status IN
('active','processing'){$own}")`. In every one of these, `$id` is cast with `(int)` and `$own` is
built entirely from hardcoded string fragments and an `(int)`-cast id, before interpolation — there
is no unsanitized user input reaching any of these five statements today.

**Recommended future fix.** Convert these five statements to `prepare()`/`execute()` with bound
parameters purely for consistency and to remove the latent risk that a future edit adds an
un-cast field to one of these strings without noticing the pattern it's breaking.

**Phase 2 update (2026-07-27): OPEN — unchanged.** Not part of Phase 2's scope; none of these five
call sites are on an authorization/authentication/session/2FA/rate-limit/backend-auth path, so
they were left as-is rather than opportunistically touched. Still a style/consistency gap only, per
the original finding's own risk assessment.

## 11. CSRF protection — `logout.php` has no token check

**Severity: LOW**

**Risk.** A third-party page can force-terminate a logged-in victim's session (e.g. via
`<img src="https://.../logout.php">`) without their intent — a session-availability annoyance, not
a data-integrity or confidentiality issue, since logging someone out changes no data and reveals
nothing.

**Current implementation.** `public/logout.php` (6 lines total) performs `session_destroy()`
unconditionally on any GET request, with no `csrf_check()` call — the only state-changing action
anywhere in the app without one. Every other mutating endpoint reviewed across this project (and
the earlier six-subsystem audit) consistently calls `csrf_check()` first.

**Recommended future fix.** Require POST + `csrf_check()` for logout, matching every other
state-changing action in the app; trivial, low-risk fix whenever convenient.

**Phase 2 update (2026-07-27): FIXED.** `public/logout.php` now requires POST + `csrf_check()`,
matching every other state-changing endpoint. A GET request shows a safe confirmation page with a
POST form instead of immediately destroying the session — a backward-compatible transition for any
existing bookmarked/linked GET logout URL, rather than a hard break. `app/views/header.php`'s
logout link was changed from an `<a href="/logout.php">` to a `<form method="post">` +
`csrf_field()` + submit button to match. Logout now also logs `Logger::info('auth.logout', ...)`.

## 12. XSS / security headers — consistent escaping, but no defense-in-depth layer

**Severity: LOW**

**Risk.** No stored or reflected XSS was found anywhere in this codebase across the STEP 1
six-subsystem review (tickets, autoreply, contacts, public site, reporting, send flows) — output
escaping is applied consistently via `e()` (a plain `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`
wrapper). The residual risk is structural: there is no templating engine enforcing
escape-by-default, and no browser-side defense-in-depth (CSP, `X-Frame-Options`,
`Strict-Transport-Security`) backstops a future missed `e()` call or a clickjacking attempt.

**Current implementation.** A repo-wide search for `Header set|Header always|Content-Security-Policy
|X-Frame-Options|Strict-Transport-Security` across `docker/`, `app/`, and `public/` returns
nothing — despite `a2enmod headers` being enabled in `docker/Dockerfile:9` (the Apache module is
loaded but never actually used to set a security header anywhere). The one place a
response-level security header *is* set is `public/kyc-photo.php:43`
(`X-Content-Type-Options: nosniff`), which is correct and worth using as the template for the rest.

**Recommended future fix.** Add a small set of standard headers (`X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY` or `SAMEORIGIN`, `Strict-Transport-Security` once HTTPS is confirmed, and a
baseline `Content-Security-Policy`) at the Apache config level (the same `<Directory>` block
pattern already used in `docker/Dockerfile` for the health-endpoint rewrite) so it applies
site-wide without touching every page.

**Phase 2 update (2026-07-27): FIXED.** `send_security_headers()` (`app/bootstrap.php`, called
right after `ErrorHandler::register()` on every request) sets exactly this set at the application
level rather than the Apache config level (equivalent effect, applies site-wide without touching
every page, and travels with the app regardless of web server): `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN` (plus
`frame-ancestors 'self'` in the CSP as the modern equivalent), a full `Content-Security-Policy`,
and `Strict-Transport-Security` gated on `request_is_https()` (inert and harmless over plain HTTP
regardless, per RFC 6797). The CSP was written only after grepping the actual codebase for inline
script/style usage (5 files with inline `<script>`, 15 with inline event-handler attributes, 21
with inline `style=`, zero external CDN dependencies) rather than shipping a maximally strict
policy that would break the app — `script-src`/`style-src` still include `'unsafe-inline'` as a
result, which is real, disclosed remaining debt (`docs/technical-debt.md`), not an oversight.

## 13. File upload validation — solid, with minor gaps

**Severity: LOW**

**Risk.** The KYC upload path is well-implemented; two small inconsistencies exist elsewhere, and
there is no malware/content scanning anywhere (a defense-in-depth gap common to lightweight
self-hosted apps, not a proven exploit path).

**Current implementation.** `kyc_store_upload()` (`app/bootstrap.php`) validates upload error
state, enforces an 8MB cap, checks MIME type via `mime_content_type()` with an
extension-based fallback if that function is unavailable, allow-lists exactly
`jpg|png|webp|pdf`, and generates a random filename (`bin2hex(random_bytes(8))`) rather than
trusting the client-supplied name — solid. `public/slides.php`'s own upload validator uses the
same shape but, unlike `kyc_store_upload()`, has no extension-based fallback when
`mime_content_type()` is unavailable, rejecting the upload outright instead — an inconsistency
between two structurally-identical helpers, lower severity since `slides.php` is
`require_admin()`-gated. `public/number-categories.php`'s `.txt` upload validates size only, not
content/extension server-side (mitigated by only ever being read via `file_get_contents()` and
line-parsed, never executed or served) — also admin-only.

**Recommended future fix.** Bring `slides.php`'s validator in line with `kyc_store_upload()`'s
fallback behavior for consistency. If malware scanning is ever a requirement (e.g. compliance),
that's a new dependency (a ClamAV sidecar or similar) and a deliberately separate, larger decision
— not part of this baseline.

**Phase 2 update (2026-07-27): FIXED.** `slide_store_upload()`'s (`public/slides.php`) validator now
has the same extension-based MIME fallback `kyc_store_upload()` already had, instead of rejecting
outright when `mime_content_type()` is unavailable — closing exactly the inconsistency this finding
flagged. Its `RuntimeException` throws were also switched to `AppException` (the Phase 1
safe-to-display exception type) for consistent error handling with the rest of the upload paths.
Malware/content scanning remains explicitly out of scope, as originally noted.

## 14. KYC file access — implemented correctly (positive finding)

**Severity: none — reviewed and found solid**

`public/kyc-photo.php` was read in full for this review, not assumed from documentation. It:
requires login (`kyc-photo.php:3`); validates `type` against an exact allow-list before doing
anything else (line 7); enforces "viewer is either the subject or an admin" (line 11) — no
alternate code path bypasses this; looks up the filename from the database rather than trusting
`$_GET`, then **additionally** validates the filename's shape via a strict regex
(`^u\d+_[a-z_]+_[0-9a-f]{16}\.(jpg|png|webp|pdf)$`, line 29) before ever touching the filesystem,
explicitly as defense-in-depth even though the value is documented as always coming from
`kyc_store_upload()`'s own generation, never user input; serves the file with
`X-Content-Type-Options: nosniff` and `Cache-Control: private, max-age=0, no-store` (lines 43, 45)
to prevent MIME-sniffing and caching of sensitive documents. No changes recommended.

**Phase 2 update (2026-07-27): unchanged, still correct.** `kyc-photo.php` was not modified during
Phase 2 remediation — it was already the template other upload/retrieval paths should match (see
finding 13), and nothing in this phase's scope required changing it.

## 15. Sender/originator authorization not revalidated at send/execution time

**Severity: (discovered during Phase 2 remediation — no original-review number)**

**Risk.** A user's send-time authorization for a given sender line was checked, if at all, only
loosely and inconsistently across different send paths, and — more importantly — **never
re-checked when a queued/scheduled job actually executed**, only (sometimes) when it was created.
A user whose sender-line assignment or panel access was revoked *after* scheduling a send, enabling
an auto-reply rule, or queuing a bulk campaign would still have it sent by the worker later,
potentially using a sender line they were never authorized for, or after their access had already
been pulled entirely.

**Current implementation (as found during Phase 2).** `run_due_schedules()`, `autoreply_process_one()`,
and `bulk_send_one_item()` (`app/backend.php`) each fetched the owning user's `ellsms_meta` row at
execution time, but either never selected `panel_access` at all, or selected it and never actually
checked it before sending — only `active`/`deleted` were checked in some paths. None of the four
send entry points (`send.php`, `new-send.php`, `p2p-send.php`, `smart-send.php` calling
`dispatch_message()` directly) validated that the originator being used was one the requesting user
was actually authorized for at the point of dispatch.

**Phase 2 update (2026-07-27): FIXED.** `dispatch_message()` (`app/backend.php`) — the single
function every send path (direct/bulk/smart/P2P/scheduled/recurring/auto-reply/legacy API) already
funnels through — now calls `can_use_originator($user, $originator)` immediately after normalizing
the originator and before the credit check, rejecting with a clear Persian error message and
logging `Logger::warning('sms.send.rejected_unauthorized_originator', ...)` if unauthorized. Because
this check lives inside `dispatch_message()` itself rather than being duplicated at each call site,
it runs identically whether the send is happening live in an HTTP request or hours later when the
worker executes a queued job — closing the "checked at creation, not at execution" gap. Separately,
`run_due_schedules()`, `autoreply_process_one()`, and `bulk_send_one_item()` were all updated to
use `is_backend_account_active($row) && has_panel_access($row)` (both from
`app/authorization.php`) before sending, uniformly checking account-active/not-deleted AND
panel-access-still-granted — so a revoked user cannot keep sending indefinitely via a queue that
was populated before the revocation. Verified by
`tests/Unit/AuthorizationHelpersTest.php` (`is_backend_account_active()`/`has_panel_access()` fail
closed on null/inactive/deleted/no-access rows) and
`tests/Integration/AuthorizationIntegrationTest.php` (`can_use_originator()` end-to-end against a
real assigned-numbers table, including the default-originator fallback and the deny case).
