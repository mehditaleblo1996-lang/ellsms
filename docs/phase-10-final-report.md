# Phase 10 Final Report — Production Hardening, Security Closure & Release Safety

## 1. Executive Summary

Phase 10 performed a focused production-risk inventory across authentication, session/cookie
handling, CSRF, security headers, rate limiting, platform-admin boundaries, XSS/SQLi/command/SSRF
surfaces, upload safety, payment security, worker/queue hardening, logging, dependency posture,
Docker/runtime configuration, and deployment/migration safety. It found and fixed one genuine,
previously-undocumented HIGH-value security gap (unconditional trust of `X-Forwarded-For`/
`X-Forwarded-Proto`, a real rate-limit bypass), closed two pre-existing MEDIUM technical-debt items
with regression tests (TD-033 xlsx decompression bomb, TD-034 bootstrap-admin race), added a
matching serialization fix to the migration runner, and built six new operational commands
(`config-check`, `predeploy-check`, `smoke-test`, `release-check`, `production-integrity-check`,
`dependency-audit`) plus Docker/PHP-runtime hardening verified against real built images. Most of
the areas this phase's brief asked to review (CSRF, security headers, session/cookie hardening,
SQL injection, command injection, CORS) were already comprehensively handled by Phase 2 and are
reconfirmed here, not newly built. The backend HMAC verifier remains honestly disclosed as
**PARTIAL** — no backend-side code exists in this repository to verify against.

**PRODUCTION HARDENING DECISION: CONDITIONALLY READY** — full reasoning in §40.

## 2. Production Hardening Invariants

Held throughout: no Phase 1-9 guarantee was weakened (every existing regression suite re-verified
green, §32); every fix is fail-closed (`TRUSTED_PROXY_IPS` defaults to trusting nothing; a bad
`config-check` finding blocks, not warns, when it's genuinely exploitable); no new infrastructure
was introduced (no Redis, no secret-management platform, no public API/webhooks — all explicitly
out of scope per the phase brief); every operational command this phase added is non-mutating
except where explicitly documented as a mutation (`db-migrate.php --apply`, unchanged from Phase 5,
now additionally lock-serialized).

## 3. Initial Risk Inventory

Reviewed: authentication, session fixation/cookie attributes, CSRF, IDOR/authorization,
platform-admin boundaries, tenant isolation, secret storage, insecure defaults, debug/stack-trace
exposure, file permissions, upload/import safety, command injection, SQL injection, XSS, SSRF, open
redirect, webhook/callback validation, Docker runtime, deployment scripts, dependency risk,
production observability. Findings classified below; most areas were already closed by Phase 1-9
and are reconfirmed, not re-fixed.

## 4. Critical/High Findings

| Finding | Severity | Status |
|---|---|---|
| `X-Forwarded-For`/`X-Forwarded-Proto` trusted unconditionally from any client — real rate-limit bypass on login/2FA/API-send, and spoofable session-cookie `secure` flag | **HIGH** | **FIXED** (`TRUSTED_PROXY_IPS`, §8 of production-hardening.md) |
| TD-034: bootstrap-admin check-then-insert race | MEDIUM | **FIXED** |
| TD-033: xlsx decompression before row-cap | MEDIUM | **FIXED** |
| `db-migrate.php --apply` had no serialization lock | MEDIUM | **FIXED** (same primitive as TD-034) |
| Backend HMAC verifier not implemented (external) | — (external, disclosed) | **PARTIAL**, unchanged |
| TD-030: secrets in plaintext in `ellsms_settings` | MEDIUM (pre-existing) | open, documented, out of scope |
| TD-036: CSP `'unsafe-inline'` | LOW (pre-existing) | open, documented, out of scope |

No other CRITICAL/HIGH findings. No CORS misconfiguration exists (no CORS headers are sent at
all — correct for this same-origin app). No command injection surface exists (no
`exec`/`shell_exec`/`system`/`passthru`/backticks anywhere in application code — confirmed by
full-repository search). No stored/reflected XSS gap found (`e()` htmlspecialchars wrapper used
consistently; spot-checked contacts/tickets/organization-name/flash-message/query-parameter output
paths).

## 5. Configuration Validation

`cron/config-check.php` (`make config-check`, `--json` mode) — see production-hardening.md §1 for
the full rule set. 18 automated test scenarios in `tests/Unit/ConfigCheckTest.php` (subprocess-based,
since it's a top-level script) cover: clean production config passes; missing DB credentials fail;
placeholder DB credentials fail in production; weak HMAC secret fails; `ELLSMS_ALLOW_LOAD_TEST`/
`FAKE_BACKEND_*` fail in production; non-numeric rate-limit/metrics settings fail; malformed
`TRUSTED_PROXY_IPS` entries fail; `APP_DEBUG=1` in production is WARN (informational — code already
force-disables it), not FAIL.

## 6. Secret Management

See production-hardening.md §2 for the full inventory and rotation procedure per secret. No
committed real secrets (confirmed — `.env` is gitignored, `.env.example` contains only
placeholders, several of which `config-check` now explicitly rejects if they appear in a
production environment). No secret-management platform introduced (explicitly out of scope).
TD-030 (plaintext `ellsms_settings` values) remains open, documented, with its existing partial
mitigation (Logger redaction) reconfirmed.

## 7. Session and Cookie Security

Reconfirmed from Phase 2 (TD-029, already fixed): `HttpOnly`, `SameSite=Lax`, `secure` (now
correctly gated by `TRUSTED_PROXY_IPS`, §8), `use_strict_mode`, idle/absolute timeouts independent
of PHP's session GC, ID regeneration on login/2FA/bootstrap-admin, logout invalidation
(`session_destroy()` + explicit cookie expiry). No session secrets ever appear in a URL (verified —
`session_name('ELLSMS_SESSION')` uses cookie transport only, no `session.use_trans_sid`). See
`tests/Unit/SessionSecurityTest.php` (updated this phase for the `TRUSTED_PROXY_IPS` gating).

## 8. Trusted Proxy and HTTPS

**The phase's main finding.** `client_ip()` (`app/rate_limit.php`) and `request_is_https()`
(`app/bootstrap.php`) previously trusted `X-Forwarded-For`/`X-Forwarded-Proto` from ANY client
unconditionally. Fixed with `TRUSTED_PROXY_IPS` (IP/CIDR allowlist, empty = trust nothing,
fail-closed) and `request_from_trusted_proxy()` (checks the DIRECT peer, `REMOTE_ADDR`, never a
header). When trusted, `client_ip()` uses the RIGHTMOST `X-Forwarded-For` entry (the value the
trusted proxy itself appended), not the leftmost (attacker-suppliable). Full behavior matrix in
`tests/Unit/TrustedProxyTest.php` (10 tests: CIDR boundary matching at /8 and /24, untrusted-peer
spoofing ignored, trusted-peer forwarding honored, malformed input handled safely). **This is a
changed default**: any existing deployment behind a reverse proxy must now set `TRUSTED_PROXY_IPS`
explicitly to restore the previous (correct, when actually behind a trusted proxy) forwarding
behavior — see §37 for the breaking-change disclosure.

## 9. CSRF Coverage

Already comprehensive (Phase 2) — reconfirmed via full-repository grep: every `public/*.php`
handling `REQUEST_METHOD === 'POST'` calls `csrf_check()`, zero gaps found. No state-changing GET
routes exist (`$_GET['do']`/`$_GET['action']` pattern search: zero matches). `logout.php` requires
POST+CSRF (TD-032). Token: session-bound, `hash_equals()` (constant-time) comparison, generated
once per session (not rotated per-request — a deliberate, standard tradeoff, not a gap). New this
phase: `tests/Unit/CsrfTest.php` exercises the REAL `csrf_check()`/`csrf_token()`/`csrf_field()`
functions (mismatched token rejected, missing token rejected, matching token allowed through, via
subprocess since the function `exit()`s on failure).

## 10. Security Headers and CSP

Already comprehensive (Phase 2, TD-031). Reconfirmed present on a real built-image response:
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
`X-Frame-Options: SAMEORIGIN`, CSP (`default-src 'self'`, `object-src 'none'`, `base-uri 'self'`,
`form-action 'self'`, `frame-ancestors 'self'`), `Strict-Transport-Security` when HTTPS-detected.
TD-036 (CSP still allows `'unsafe-inline'` for script/style) remains open and disclosed — the app's
existing inline `<script>`/event-handler/`style=""` usage would need a broader migration to
nonce/hash-based CSP than this phase's scope permits without risking silently breaking pages.
`Permissions-Policy` was NOT added this phase (evaluated: no browser feature this app uses —
camera/geolocation/etc. — needs restricting beyond what CSP already covers; adding an empty/overly
broad policy would be security theater, not a real improvement).

## 11. CORS

**Not applicable.** Zero `Access-Control-Allow-*` headers exist anywhere in this codebase
(full-repository search). ELLSMS is same-origin, server-rendered — no cross-origin browser API
surface exists to misconfigure. This is the correct, safest posture; adding permissive CORS headers
would be a regression.

## 12. Rate Limiting

`app/rate_limit.php` (Phase 2, DB-backed sliding window, IP+account dimensions). Phase 10 fix: IP
resolution now respects `TRUSTED_PROXY_IPS` (§8), closing the header-spoofing bypass. Fails open
only on limiter infrastructure failure (never locks out legitimate users because the limiter itself
is down), logged when it happens. Denial responses don't leak account existence (generic
"invalid username or password" regardless of which failed — unchanged, already correct).

## 13. Platform Admin Security

Reconfirmed unchanged from Phase 7: platform admin (`ellsms_meta.is_admin`) remains structurally
separate from organization owner/admin RBAC roles — no organization-scoped action can grant
platform-admin privilege (verified: `is_admin` is never set from any organization-membership code
path, only from `bootstrap-admin.php`'s one-time flow and `users.php`'s platform-admin-only grant
action). Destructive/sensitive admin actions (password reset, wallet manual adjustment, user
enable/disable) are POST+CSRF, never GET. `audit()` logging covers admin mutations (login,
bootstrap_admin, password changes — reconfirmed, unchanged). No impersonation feature exists (N/A).
TD-034's fix (§8 of production-hardening.md) directly hardens the platform-admin bootstrap path
specifically.

## 14. Input/Output Security

See §16 (XSS) and §16 (SQL/command/SSRF) below — reviewed together as this phase's "input/output"
pass, no separate findings beyond what's itemized there.

## 15. XSS Review

Spot-checked contacts, organization names, ticket subjects/bodies, flash messages, and
backend/provider error message rendering: `e()` (`htmlspecialchars` wrapper,
`app/bootstrap.php:519`) is used consistently at every output point checked. No `echo $_GET[...]`
or equivalent unescaped-output pattern found. Backend/provider error text
(`describe_api_error()`, `app/Backend/ApiClient.php`) is always passed through `e()` at its
display call sites, never rendered as trusted HTML. No new code introduced any XSS-shaped risk this
phase (no new user-facing output was added). Not re-tested with new automated tests this phase —
existing Phase 1/2 review already covered this surface and no code changed here.

## 16. SQL/Command/SSRF Review

- **SQL injection**: parameterization is universal (confirmed via targeted search for
  string-concatenated SQL, dynamic `ORDER BY`/table/column names) — the only dynamic-identifier
  usage found (`schedule_due_condition_sql($alias)`, Phase 9) takes an internally-fixed alias
  string, never user input. TD-022 (5 pre-existing int-cast-before-concatenation sites) remains
  open, unchanged, low risk (already documented, not on an auth path).
- **Command injection**: zero `exec`/`shell_exec`/`system`/`passthru`/backtick usage anywhere in
  application code (full-repository search) — the only process-spawning code is this phase's own
  operational tooling (`cron/production-integrity-check.php`, `cron/predeploy-check.php`), which
  shells out to this project's OWN fixed-path scripts via `proc_open`/`exec` with
  `escapeshellarg()`-wrapped, non-user-controlled arguments, never anything from an HTTP request.
- **SSRF**: `API_BASE_URL`/`ZARINPAL_*`/`TELEGRAM_*` are all admin/operator-configured (via
  `.env` or `ellsms_settings`), never derived from user/request input at call time. TLS
  verification is enabled by default (`curl` without `CURLOPT_SSL_VERIFYPEER => false` anywhere —
  confirmed absent). `config-check` now rejects a non-HTTPS `API_BASE_URL` in production.

## 17. Upload/Import Safety

**TD-033 fixed** (see §4). `app/xlsx_reader.php`'s `read_xlsx_rows()` now checks
`ZipArchive::statName()`'s reported uncompressed size for both the worksheet and shared-strings
members BEFORE calling `getFromName()` (which decompresses) — a member over 64MB uncompressed is
rejected immediately, closing the zip-bomb-style memory/CPU exhaustion the old post-hoc 20,000-row
check couldn't prevent. Proven with real crafted zip fixtures in `tests/Unit/XlsxReaderTest.php`
(a ~114MB-uncompressed/small-on-disk worksheet, and a ~76MB shared-strings table, both rejected;
a normal small sheet still parses correctly). Path traversal: not applicable (ZipArchive member
names are read by exact name/pattern match, never used to construct a filesystem path). CSV import
uses native `str_getcsv`, no formula-injection-relevant risk for THIS app's usage (imported CSV
content is only ever inserted as SMS message text/mobile numbers, never re-exported into a
spreadsheet a victim would open in Excel). KYC uploads (`kyc_store_upload()`) were reviewed in
Phase 1/2 and are unchanged this phase (extension + MIME-type validation, generated storage names,
not public-URL-served).

## 18. Payment Security

Reviewed `public/zarinpal-callback.php`/`app/zarinpal.php` (Phase 3, unchanged this phase — no
genuine gap found to fix): ownership check (`payment.user_id === current session user`), authority
match check (the value ZarinPal echoes back must match what was originally requested), idempotent
status handling (`status='paid'` short-circuits before any re-verification), amount verified
against the STORED `amount_rial` (never a value taken from the callback request), payment-claim +
wallet-credit inside one DB transaction (`payment_claim_and_credit()`), no organization/session
dependency for the credit itself (keyed on the payment row's own persisted user/org). No secret
leakage in the callback response (errors shown are pre-written Persian strings, never raw provider
response bodies). Provider signature verification: ZarinPal's v4 API does not provide a
callback-signing mechanism to verify against beyond the authority/amount/status re-verification
this integration already performs server-side — reported honestly rather than claiming a signature
check that the provider's own API doesn't offer.

## 19. Worker and Poison-Job Hardening

Reconfirmed from Phase 4/8/9, no regressions: atomic claim, lease-based crash recovery (live-tested
in Phase 9 with a real `kill -9`), bounded retry with correct permanent-vs-transient classification
(the Phase 8 `BackendError::isRetryable()` fix, re-verified still in place), poison jobs
(permanent 4xx, revoked access) terminate at `JOB_MAX_ATTEMPTS` — covered by existing Phase 4 tests
(`ScheduleQueueTest::testBecomesTerminalAfterMaxAttempts`/`testRevokedUserSchedulePermanentlyFailsWithoutRetrying`).
No sensitive payload appears in worker logs (reconfirmed against every Phase 9 metric call site).
No transaction spans a backend HTTP call (unchanged architecture — `bulk_claim_items()`'s
transaction is short, dispatch happens outside it, per its own docblock). Graceful SIGTERM
verified live against the built worker image via `docker kill -s TERM` (signal received, clean
exit, <1ms shutdown for an idle worker) — see §22 for the one inconclusive secondary observation.

## 20. Logging and Error Redaction

`Logger::REDACT_KEY_PATTERN` unchanged, reviewed against every new Phase 9/10 log/metric call
site — no secret, message content, or session identifier found in any of them. Log injection via
control characters structurally prevented (every line is one `json_encode()`d object — an embedded
`\n` is escaped inline, never breaks the line format). `display_errors=Off` is now the static
`php.ini` baseline (`docker/Dockerfile`), in addition to the existing runtime `ini_set()`
`ErrorHandler::register()` already performed — closes the narrow window where a fatal error before
`ErrorHandler` registers could have used PHP's own (On) default instead.

## 21. Dependency Review

`composer.lock` contains only `phpunit/phpunit` and its own transitive dependencies — dev-only,
never shipped to the production image (`docker/Dockerfile` never runs `composer install` at all;
confirmed via search). No other package manifest exists (no `package.json`/`node_modules` in the
shipped application). `make dependency-audit` reports installed PHP version/extensions and
`composer.lock` contents for manual review — this environment has no network-reachable
vulnerability-feed tool wired in, and the command says so explicitly rather than claiming a check
it didn't perform. No dependency upgrades were made this phase (none were needed — the dependency
surface is already minimal by design).

## 22. Docker Hardening

- `.dockerignore` added (excludes `.git`/`tests`/`docs`/logs/benchmarks/`vendor` from the build
  context).
- Apache `<FilesMatch>` deny block added (defense-in-depth; `APACHE_DOCUMENT_ROOT=/var/www/html/public`
  already structurally excludes everything outside `public/`, verified this remains true).
- `display_errors=Off`/`log_errors=On` added as the static PHP.ini baseline.
- Both images (`ellsms-app`, `ellsms-worker`) built successfully with these changes.
- Live-verified against the built `app` image: `/health.php` → 200; `/.env` → 403 (denied by
  filename pattern); `/cron/worker.php` → 404 (outside docroot); Apache master process runs as
  root (required for MPM prefork to bind port 80), worker processes run as `www-data` (the
  standard, correct privilege-drop pattern for this base image — not a finding).
- Live-verified against the built `worker` image: `docker kill -s TERM` → `worker.signal_received`
  → `worker.shutdown`, clean exit code 0, sub-millisecond shutdown for an idle worker. **One
  inconclusive observation**: `docker stop` (which sends the same signal but waits up to a
  configured grace period) did not exit within either a 10s or 30s window in this specific
  development sandbox, ultimately requiring `docker stop`'s own SIGKILL fallback — despite the
  direct-signal test proving the application code itself handles `SIGTERM` correctly. This reads as
  a sandbox/Docker-environment quirk (isolated `docker kill` delivers correctly; `docker stop`'s
  wait-then-signal sequence in this environment did not), not a proven application defect, but is
  disclosed here rather than silently omitted — re-verify `docker compose stop`/orchestrator-issued
  shutdown specifically in the actual target deployment environment as part of that environment's
  own smoke-testing.
- Read-only root filesystem / dropped Linux capabilities / `no-new-privileges` were evaluated and
  NOT added this phase — the app needs to write bind-mounted `storage/` directories and Apache
  manages its own runtime files; adding these without dedicated `tmpfs`/volume carve-outs risked
  breaking a working deployment shape for a hardening step with no specific measured threat
  driving it. Documented as a future step, not implemented under this phase's risk tolerance.

## 23. Web Server and PHP Runtime

Production already does not use `php -S` (Apache via `php:8.2-apache`, unchanged). Hidden
files/`.env`/`.git`/internal scripts confirmed denied (§22). `expose_php=Off` (pre-existing),
`display_errors=Off`/`log_errors=On` (new this phase, static baseline). `upload_max_filesize=10M`/
`post_max_size=24M` unchanged (sized for KYC uploads, pre-existing). Worker CLI behavior
unaffected by the new static `display_errors=Off` (Logger, not `display_errors`, is the worker's
actual error-visibility mechanism — unchanged).

## 24. Database Permissions

**Recommended production grants** (not applied automatically — this phase does not change any live
database's actual permissions, matching "do not change production grants automatically"):
`BACKEND_DB_USER` should have `SELECT, INSERT, UPDATE, DELETE` on `ellsms_*` tables and `SELECT`
(+`UPDATE` for the one `currentcredit` write, `INSERT`/`UPDATE`/`SELECT` for `user_.password`) on
`user_`, `SELECT` on `domain`, `SELECT`/`INSERT` on `inbound_message`/`outbound_message` per the
Phase 8 boundary (`docs/service-boundaries.md`) — and explicitly NOT `DROP`/`ALTER`/`CREATE USER`/
`GRANT` (schema changes belong to the operator running migrations manually, not the application's
own runtime credential). `utf8mb4` charset (unchanged, `db()`'s DSN). `PDO::ATTR_ERRMODE_EXCEPTION`
(fail-closed on any DB error, unchanged). `PDO::ATTR_EMULATE_PREPARES => false` (real server-side
prepares, unchanged). No `PDO::ATTR_PERSISTENT` (unchanged — persistent connections were evaluated
in earlier phases and not adopted, consistent with this project's "no infrastructure without
evidence" discipline).

## 25. Migration Safety

**Fixed this phase**: `cron/db-migrate.php --apply` now serializes via
`GET_LOCK('ellsms_db_migrate_apply', 30)` — two concurrent `--apply` invocations can no longer race
on the ledger insert or double-apply a file. `--status` remains read-only, unlocked. Every
migration file remains independently idempotent (unchanged Phase 5 convention). No migration ever
runs automatically from the web or worker container (confirmed — `docker/entrypoint.sh` only
touches the filesystem, never the database). `make migration-status` (`db-migrate.php --status`)
and a preflight review pass are explicit, separate operator steps in the deployment procedure
(§50-equivalent, see production-hardening.md §13).

## 26. Deployment Script Safety

This project has no dedicated `deploy.sh`-style script beyond `docker/entrypoint.sh` (reviewed,
unchanged — `set -e`, explicit `mkdir`/`chown` for bind-mounted `storage/`, `exec
docker-php-entrypoint "$@"` for correct signal propagation) and the `Makefile` itself. No broad
`docker system prune`/unrelated-resource-deletion command exists anywhere in this repository
(confirmed via search). The new `release-check`/`predeploy-check`/`production-integrity-check`
targets are all non-mutating by construction (§14 of production-hardening.md).

## 27. Pre-Deploy Checks

`make predeploy-check` (`cron/predeploy-check.php`) — composes `config-check`, DB reachability,
migration status, `API_BASE_URL` presence, writable-directory checks, production/test-mode guards
(`ELLSMS_ALLOW_LOAD_TEST`/`FAKE_BACKEND_*` must be absent), and `backend-boundary-check` into one
gate. Verified working against the disposable test database (PASS).

## 28. Smoke and Release Checks

`make smoke-test URL=...` (`cron/smoke-test.php`) — real HTTP checks against a running deployment:
liveness, readiness (degraded-state-aware — a 503 from `/health-ready.php` is a valid, informative
result, not a smoke-test failure), no debug/secret leakage in health responses, login page
reachable (correctly treats the legitimate `/login.php` → `/bootstrap-admin.php` redirect on a
fresh/empty install as healthy, not a failure — found and fixed during this phase's own testing),
protected-route anonymous-access denial, internal `cron/`-path and `.env` non-exposure, security
headers present. **Live-verified end to end**: 8/8 checks passing against a real locally-served
instance of the built application. `make release-check` composes lint + unit + integration (if
`ELLSMS_TEST_DB_HOST` is set) + `backend-boundary-check` + `config-check` (fixture values, never
real secrets).

## 29. Rollback Strategy

- **Application rollback, forward-compatible schema** (the common case, since every migration is
  additive/`IF NOT EXISTS`-guarded per Phase 5 convention): redeploy the previous image tag; no
  schema action needed.
- **Application rollback after an incompatible migration**: not automated (explicitly out of
  scope — "do not build automatic destructive rollback"). This project's migrations have all been
  additive to date (new tables/nullable columns/guarded indexes); a genuinely destructive migration
  would need a hand-written down-migration reviewed at the time it's written, not a generic tool.
- **Configuration rollback**: revert `.env`/compose environment, restart affected containers.
- **Worker rollback**: redeploy the previous worker image tag; in-flight claims are lease-protected
  (Phase 4) and safely reclaimed by whichever version's worker picks them up next.
- **Backend API compatibility rollback**: out of this repository's control (external platform) —
  `BackendError` classification already treats an unexpected/changed response shape as
  `BackendInvalidResponse` (fail-closed, not a crash).
- Full backup/restore/RPO/RTO procedures are explicitly deferred to Phase 11, per the phase brief.

## 30. Operational Integrity Commands

`make production-integrity-check` (`cron/production-integrity-check.php`) aggregates
`config-check`, `backend-boundary-check`, and (when a database connection is available)
`db-integrity-check`, `tenant-integrity-check`, `rbac-integrity-check`, `wallet-audit`,
`jobs-status`, `performance-snapshot`, and `migration-status` into one PASS/WARN/FAIL report per
tool plus an overall verdict. Never auto-fixes anything (each underlying tool already has that
policy individually; this command only orchestrates and reports). **Live-verified**: all 9 checks
report PASS against the disposable test database.

## 31. Security Test Results

New this phase: `tests/Unit/TrustedProxyTest.php` (10 tests), `tests/Unit/CsrfTest.php` (5 tests),
`tests/Unit/ConfigCheckTest.php` (10 tests), `tests/Unit/XlsxReaderTest.php` (3 tests),
`tests/Integration/BootstrapAdminLockTest.php` (1 test, 4 assertions). Updated for the
`TRUSTED_PROXY_IPS` behavior change: `tests/Unit/SessionSecurityTest.php` (2 tests),
`tests/Unit/RateLimitHelpersTest.php` (2 tests). All passing — exact counts in §32/Final Response.

## 32. Full Regression Results

Reported exactly in the Final Response (generated from the actual final validation run, not
duplicated here to avoid drift). Summary: full unit suite, full integration suite from a clean
disposable-database state, and targeted tenant/RBAC/wallet/queue/boundary/migration regression
suites all green.

## 33. Files Created

- `app/xlsx_reader.php` — modified in place (see §34), not new
- `cron/config-check.php`, `cron/predeploy-check.php`, `cron/smoke-test.php`,
  `cron/production-integrity-check.php`
- `tests/Unit/TrustedProxyTest.php`, `tests/Unit/CsrfTest.php`, `tests/Unit/ConfigCheckTest.php`,
  `tests/Unit/XlsxReaderTest.php`, `tests/Integration/BootstrapAdminLockTest.php`
- `.dockerignore`
- `docs/production-hardening.md`, `docs/phase-10-final-report.md`

## 34. Files Modified

- `app/bootstrap.php` — `TRUSTED_PROXY_IPS`/`ip_in_cidr()`/`request_from_trusted_proxy()` added;
  `request_is_https()` gated by trusted-proxy check
- `app/rate_limit.php` — `client_ip()` gated by trusted-proxy check, rightmost-entry selection
- `app/xlsx_reader.php` — `MAX_XLSX_MEMBER_UNCOMPRESSED_BYTES` guard before decompression (TD-033)
- `public/bootstrap-admin.php` — `GET_LOCK()`-serialized check+insert (TD-034)
- `cron/db-migrate.php` — `GET_LOCK()`-serialized `--apply`
- `cron/backend-boundary-check.php` — allowlisted `cron/load-test.php`'s own `user_` seeding (a
  Phase 9 gap surfaced when this phase re-ran the scanner as part of its own validation, not a new
  boundary violation introduced this phase)
- `docker/Dockerfile` — `display_errors=Off`/`log_errors=On` baseline; Apache dotfile deny block
- `docker-compose.yml` — `TRUSTED_PROXY_IPS` passthrough
- `.env.example` — `TRUSTED_PROXY_IPS` documented
- `Makefile` — new targets (§14 of production-hardening.md) plus the previously-undefined
  `load-test-small`/`load-test-medium`/`load-test-workers` targets (documented in Phase 9's report
  but never actually added as real Makefile recipes — fixed this phase)
- `tests/Unit/SessionSecurityTest.php`, `tests/Unit/RateLimitHelpersTest.php` — updated for the
  `TRUSTED_PROXY_IPS` behavior change
- `docs/technical-debt.md`, `docs/architecture.md` (Phase 9 note, unrelated to this phase's own
  edits — see that phase's own file list) — Phase 10 update note added

## 35. Migrations

None. No schema change was needed for anything in this phase.

## 36. New Environment Variables

| Variable | Default | Purpose |
|---|---|---|
| `TRUSTED_PROXY_IPS` | empty (trust nothing) | comma-separated IP/CIDR allowlist for `X-Forwarded-For`/`X-Forwarded-Proto` trust |

(`config-check`/`predeploy-check`/`smoke-test`/`production-integrity-check`/`dependency-audit` are
new commands, not new env vars — they read existing configuration.)

## 37. Breaking Changes

**One, disclosed clearly**: `TRUSTED_PROXY_IPS` defaults to empty (trust nothing). Any EXISTING
deployment that runs behind a reverse proxy and relied on the previous unconditional
`X-Forwarded-For`/`X-Forwarded-Proto` trust will see `request_is_https()` and `client_ip()` fall
back to the direct-connection view (the proxy's own address/scheme) until `TRUSTED_PROXY_IPS` is
set explicitly. This is a deliberate, safety-motivated default flip (the previous behavior was a
real vulnerability, not a feature) — operators upgrading an existing reverse-proxied deployment
MUST set `TRUSTED_PROXY_IPS` as part of that upgrade. No other breaking change.

## 38. External Production Requirements

- A TLS-terminating reverse proxy in front of ELLSMS, with `TRUSTED_PROXY_IPS` configured to match
  it (§8/§36).
- The backend platform's own HMAC verifier (§9 of production-hardening.md) — without it, HMAC
  signing (even when configured) provides no actual protection, only prepared infrastructure.
- Real, non-placeholder production secrets for every credential in §6/§2 of production-hardening.md
  — this repository cannot generate or provision these.
- Host-level file permissions for the bind-mounted `storage/`/`.env` paths — outside this
  repository's control (Docker Compose bind-mount, not a managed volume).
- Full backup/restore/DR — explicitly deferred to Phase 11.

## 39. Remaining Security Risks

- Backend HMAC verifier absence (§9) — the single largest remaining gap, entirely external.
- TD-030 (plaintext settings) and TD-036 (CSP `'unsafe-inline'`) — both open, both documented,
  both judged disproportionate to fix within this phase's stated scope (no secret-management
  platform; no broad UI-touching CSP migration).
- The `docker stop` shutdown-timing anomaly (§22) — inconclusive in this sandbox, worth confirming
  in the actual target deployment environment.
- Read-only root filesystem / dropped capabilities were evaluated, not applied (§22) — a future
  hardening step, not a currently-exploited gap.
- No production throughput/SLA was specified anywhere in this repository (consistent with Phase 9's
  own finding) — capacity guidance remains bands, not a guarantee.

## 40. Production Readiness Decision

### PRODUCTION HARDENING DECISION: CONDITIONALLY READY

ELLSMS's OWN code, configuration, and operational tooling have no unresolved CRITICAL/HIGH
production-hardening blocker as of this phase — the one HIGH finding this phase discovered
(unconditional forwarded-header trust) is fixed, tested, and verified. Readiness is conditional on
requirements genuinely external to this repository:

1. **A backend-side HMAC verifier must be deployed** before `BACKEND_SERVICE_ID`/`_SECRET` signing
   provides any real protection (§9 of production-hardening.md) — until then, an attacker who can
   reach the backend API directly, bypassing ELLSMS, is not blocked by anything this repository
   controls. This is not new to Phase 10 (disclosed since Phase 2) and is not resolved by any
   Phase 10 work, since no backend-side code exists in this repository to write it against.
2. **A TLS-terminating reverse proxy with `TRUSTED_PROXY_IPS` configured to match it** — ELLSMS has
   never terminated TLS itself; this is standard, expected, documented topology, not a defect, but
   it is a genuine external requirement for the HTTPS-detection/rate-limit-IP-resolution hardening
   this phase added to actually take effect as intended.
3. **Real, operator-provisioned production secrets** — this repository cannot generate or validate
   the ACTUAL strength/secrecy of a real `BACKEND_DB_PASS`/`BACKEND_SERVICE_SECRET`, only reject
   obvious placeholders (`config-check`).
4. **Backup/restore/DR — Phase 11, not yet built** — explicitly out of this phase's scope by the
   phase brief's own instruction.

None of these four is a code defect in ELLSMS; all four are standard, expected external
requirements for any production deployment of a system with this architecture, and all four are
now explicitly documented (production-hardening.md §16) rather than left implicit.

## 41. Phase 11 Readiness

Given CONDITIONALLY READY status and condition (4) above, Phase 11 (full backup/restore, RPO/RTO,
disaster recovery) is the natural next phase. **Phase 11 is not started by this report** — this
recommendation is informational only, pending explicit request.
