# ELLSMS — Production Hardening (Phase 10)

Living reference for production configuration, secret policy, and the operational commands this
phase added. See `docs/phase-10-final-report.md` for the phase's closure narrative and evidence.

## 1. Production configuration

`make config-check` (`cron/config-check.php`) validates, read-only, without needing DB
connectivity: `APP_ENV`/`APP_DEBUG`/`APP_URL` shape, `TRUSTED_PROXY_IPS` CIDR validity, database
credentials present and not obvious placeholders, `API_BASE_URL` is HTTPS in production,
`BACKEND_SERVICE_ID`/`_SECRET` pairing and weak-value rejection, ZarinPal placeholder/sandbox
misconfiguration, and every numeric queue/rate-limit/metrics setting. Exits non-zero only on
FAIL-level findings (WARN-level findings are surfaced but non-blocking) — see the script's own
source for the exact rule set, which is the authoritative list, not this paragraph.

Run it with the deploy target's actual environment in place, as part of `make predeploy-check`.

## 2. Secret policy

| Secret | Where | Rotation |
|---|---|---|
| `BACKEND_DB_PASS` | MySQL credential | rotate via the database, update `.env`, restart both `app` and `worker` |
| `BACKEND_SERVICE_SECRET` | HMAC signing key for backend API calls (opt-in — see `docs/service-boundaries.md`) | generate a new random value, coordinate with whoever operates the backend verifier (currently PARTIAL, §9 below), update `.env`, restart |
| `ZARINPAL_MERCHANT_ID` | payment provider identity, not secret-strength but still not for `docs`/tests | rotate via ZarinPal's own panel |
| `TELEGRAM_BOT_TOKEN` | notification bot | rotate via BotFather, update `.env`, restart |
| session cookie signing | PHP's own session id generation (CSPRNG), no application secret to rotate | n/a |
| CSRF token | per-session, regenerated on session start, no rotation procedure needed | n/a |

**Never committed**: `.env` is gitignored; `.env.example` contains placeholders only —
`config-check` explicitly rejects several of those placeholders (`change_me`, `changeme`, etc.) if
they somehow end up in a production environment. No secret-management platform was introduced
(explicitly out of scope) — rotation is a manual `.env` edit + restart, documented here rather than
automated, consistent with this project's existing "no Redis, no new infrastructure without
evidence" discipline.

**TD-030 (open, unchanged this phase)**: `ellsms_settings.svalue` stores the ZarinPal merchant ID
and Telegram bot token in plaintext in the shared database. This phase did not add
encryption-at-rest for that table — doing so would require introducing key-management
infrastructure disproportionate to what "Do not implement a full secret-management platform"
allows. Existing mitigation: `Logger::REDACT_KEY_PATTERN` already redacts any log context key
matching `merchant_id`/`token`/etc. (`app/Support/Logger.php`, unchanged), so these values were
never at risk of appearing in application logs even before this phase — the residual risk is
specifically "someone with direct database read access," which is a database-access-control
question, not an application one.

## 3. Session/cookie policy

Unchanged from Phase 2 (TD-029, already fixed) — reconfirmed in production context this phase:
`HttpOnly`, `SameSite=Lax`, `secure` derived from `request_is_https()` (now gated by
`TRUSTED_PROXY_IPS` — see §4), `session.use_strict_mode=1` (rejects a client-supplied session id
the server never generated), idle timeout (default 1800s) and absolute timeout (default 43200s)
independent of PHP's own GC, session ID regenerated on login/2FA/bootstrap-admin. See
`tests/Unit/SessionSecurityTest.php`.

## 4. Trusted proxy / HTTPS detection (Phase 10, new)

`TRUSTED_PROXY_IPS` (`.env`) — comma-separated IP/CIDR allowlist. **Empty by default**, meaning
`X-Forwarded-For`/`X-Forwarded-Proto` are ignored entirely and the app trusts only the direct TCP
peer — fail-closed. Before this existed, both headers were honored from ANY client unconditionally,
which meant:
- `client_ip()` (`app/rate_limit.php`) — every IP-dimension rate-limit bucket (login, 2FA
  send/verify, API send) could be trivially bypassed by sending a fresh `X-Forwarded-For` value on
  every request.
- `request_is_https()` (`app/bootstrap.php`) — the session cookie's `secure` flag could be
  influenced by a spoofed `X-Forwarded-Proto` header from a client that was never behind any proxy.

**Any production deployment behind a reverse proxy (the documented topology) MUST set
`TRUSTED_PROXY_IPS`** to that proxy's address/CIDR, or rate limiting and HTTPS detection silently
degrade to direct-connection-only behavior (safe, but not what most deployments want). When
trusted, the RIGHTMOST `X-Forwarded-For` entry is used (the value the trusted proxy itself
appended), never the leftmost (attacker-suppliable). See `tests/Unit/TrustedProxyTest.php` for the
full behavior matrix.

## 5. CSRF

Already comprehensive before this phase (Phase 2) — every `public/*.php` handling a POST calls
`csrf_check()` (verified this phase via a full-repository grep, zero gaps found), `logout.php`
requires POST+CSRF (TD-032, already fixed), no state-changing route is reachable via a bare GET.
`csrf_check()` uses `hash_equals()` (constant-time) and fails closed (`exit()` on mismatch, never
falls through to the handler). New this phase: `tests/Unit/CsrfTest.php` exercises the REAL
function via a subprocess (it `exit()`s on failure, which can't be observed in-process) — mismatched
token, missing token, and matching token, all against the actual `csrf_check()` code.

## 6. Security headers / CSP

Already comprehensive before this phase (Phase 2, TD-031 fixed): `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN` +
`frame-ancestors 'self'`, a real CSP (`default-src 'self'`, `object-src 'none'`,
`base-uri 'self'`, `form-action 'self'`), and `Strict-Transport-Security` when the request is
detected as HTTPS. **TD-036 (open, unchanged)**: the CSP still allows `script-src`/`style-src
'unsafe-inline'` because the app uses inline `<script>`/event-handler attributes/`style=""`
widely — tightening this to a nonce/hash-based policy is a larger UI-touching change than this
phase's scope permits without risking silently breaking pages, so it stays disclosed debt rather
than a rushed fix.

## 7. CORS

**Not applicable** — ELLSMS is a same-origin, server-rendered PHP application with no
cross-origin API surface. A full-repository search found zero `Access-Control-Allow-*` headers
anywhere. This is the correct, safest posture for this application's actual shape (no CORS
misconfiguration is possible if no CORS headers are ever sent) — adding permissive CORS headers
would be a regression, not a hardening step, since nothing in this app is meant to be called
cross-origin from a browser.

## 8. Rate limiting

`app/rate_limit.php` (Phase 2) — DB-backed sliding window, IP+account dimensions checked
separately. Phase 10 fixed the one real gap: IP resolution now respects `TRUSTED_PROXY_IPS` (§4)
instead of trusting `X-Forwarded-For` unconditionally, closing the trivial bypass described there.
Fails open only on a rate-limiter infrastructure error (never locks out every legitimate user
because the limiter itself is down) — logged when that happens, so the outage is visible.

## 9. Backend HMAC verifier — status: **PARTIAL**

Unchanged since Phase 2/8, reconfirmed this phase — **do not mark this FIXED**. This repository
implements only the CLIENT-SIDE signer (`backend_service_auth_headers()`,
`app/Backend/ApiClient.php`): HMAC-SHA256 over method+path+timestamp+body-hash+service-id, opt-in
via `BACKEND_SERVICE_ID`/`BACKEND_SERVICE_SECRET`. **No backend-side verifier exists in this
repository** — the backend platform is a separate codebase this repository does not contain, so
end-to-end authentication cannot be proven or completed from here.

**Production deployment requirement**: whoever operates the backend platform must implement
verification of these headers (recompute the same HMAC over the request the backend actually
received, compare with `hash_equals()`, reject on mismatch, enforce a timestamp freshness window
to bound replay). Until that exists, an attacker who can reach the backend API directly (bypassing
ELLSMS entirely) is not blocked by anything this repository controls.

**Fail-safe configuration guidance**: leaving `BACKEND_SERVICE_ID`/`_SECRET` unset is
byte-identical to today's unsigned behavior (documented, not a new risk) — do not set only one of
the pair (`config-check` WARNs on this; it's a no-op either way per
`backend_service_auth_headers()`'s own early return).

**Verification checklist for whoever deploys the backend verifier**:
- [ ] recomputes HMAC-SHA256 over `method\npath\ntimestamp\nsha256(body)\nservice_id` using the
      shared secret
- [ ] compares with `hash_equals()` (constant-time), never `===`/`==`
- [ ] enforces a timestamp freshness window (reject requests with a stale `X-Ellsms-Timestamp`) to
      bound replay — nothing in ELLSMS's client enforces this, it MUST live on the verifier side
- [ ] rejects requests missing any of the four headers when signing is expected to be mandatory
- [ ] logs rejected requests for operational visibility

**Blocker level**: while `BACKEND_SERVICE_ID`/`_SECRET` are configured but no backend verifier
exists, signing provides **no actual protection** — it's prepared infrastructure, not an active
control. This is the single condition behind this phase's CONDITIONALLY READY decision (§40 of the
final report) that is genuinely external to ELLSMS and cannot be resolved from this repository.

## 10. Worker/queue hardening

Reconfirmed from Phase 4/8/9, unchanged guarantees: atomic claim (`UPDATE ... ORDER BY id LIMIT n`),
lease-based crash recovery (30s floor, `job_lease_seconds()`), bounded exponential retry backoff,
`BackendError::isRetryable()`-driven permanent-vs-transient classification (the Phase 8/9 fix —
verified still correct), no transaction spans a backend HTTP call, no duplicate debit under crash
(re-verified via a live `kill -9` test in Phase 9, §12 of that phase's report). Poison jobs
(permanent 4xx, unauthorized sender, revoked access) terminate at `JOB_MAX_ATTEMPTS` — never retry
forever — already covered by Phase 4's own test suite
(`ScheduleQueueTest::testBecomesTerminalAfterMaxAttempts`,
`ScheduleQueueTest::testRevokedUserSchedulePermanentlyFailsWithoutRetrying`, and equivalents for
bulk/autoreply). Recommended production configuration (Phase 9 measurements): 2-4 workers, batch
size 20 (`WORKER_BULK_BATCH_SIZE`, unchanged default).

**New this phase (TD-034 fixed)**: `public/bootstrap-admin.php`'s first-admin check-then-insert was
a genuine, if narrow (first-deploy-only), race — two concurrent submissions for two different
accounts could both pass the "no admin yet" check before either INSERT committed. Fixed with a
MySQL `GET_LOCK()` serializing the critical section; proven via
`tests/Integration/BootstrapAdminLockTest.php` (two real, separate DB connections). The same
primitive now also serializes `cron/db-migrate.php --apply` (§13 below).

## 11. Logging/error redaction

`Logger::REDACT_KEY_PATTERN` (unchanged, already comprehensive — passwords, tokens, OTP, 2FA,
national ID, KYC document fields, merchant IDs, card numbers) reviewed against every new Phase 9/10
metric call site: no metric ever logs message content, mobile numbers, HMAC signatures, or
Authorization headers — only `method`/`result`/`http`/`error_class`/`job_type`/counts. Log
injection via newline/control characters is structurally prevented: every log line is
`json_encode()`d as one JSON object, so a value containing `\n` is escaped inline (`\n` inside the
JSON string), never breaks the line format or lets a value forge a fake log entry.
`ErrorHandler::register()` (Phase 1) already ensures production never shows a stack
trace/file path/raw exception to a user — `display_errors=Off` is now ALSO the static PHP.ini
baseline (`docker/Dockerfile`), not only the runtime `ini_set()` ErrorHandler performs, so even a
fatal error before ErrorHandler registers can't leak.

## 12. Docker/runtime hardening

- `.dockerignore` added — excludes `.git`, `tests/`, `docs/`, logs, benchmarks, `vendor/` from the
  build context (image size/hygiene; these were never web-accessible regardless, since
  `APACHE_DOCUMENT_ROOT=/var/www/html/public` structurally excludes everything outside `public/`).
- Apache `<FilesMatch>` deny block added as defense-in-depth against a dotfile/backup file ever
  landing inside `public/` — verified live: a request for `/.env` against the built image returns
  **403** (matched-and-denied by filename pattern, independent of whether the file exists), a
  request for `/cron/worker.php` returns **404** (outside the docroot entirely, no alias exists).
- `display_errors=Off`/`log_errors=On` set as the static `php.ini` baseline (§11).
- Verified live: Apache's master process runs as root (required to bind port 80 under MPM
  prefork), worker processes run as `www-data` — the standard, correct privilege-drop pattern for
  a `php:*-apache` image; not a root-runtime finding.
- Verified live: `docker kill -s TERM` against the built worker image is received and handled
  gracefully (`worker.signal_received` → `worker.shutdown`, exit code 0, <1ms shutdown duration for
  an idle worker). `docker stop`'s behavior in this specific development sandbox was inconclusive
  (a 30s timeout expired before the container exited under `docker stop`, despite `docker kill`
  proving the application code itself handles the identical signal correctly) — flagged as a
  sandbox-environment anomaly to re-verify in the actual target deployment environment, not treated
  as a proven application defect, since the direct-signal test isolates and confirms the
  application-level behavior is correct.
- Compose: database is never exposed by this project's own `docker-compose.yml` (it doesn't run
  its own MySQL at all — connects to the backend platform's existing network/database, see that
  file's own header comment); `restart: unless-stopped`; `stop_grace_period: 30s` for the worker.
  Read-only root filesystem / dropped capabilities / `no-new-privileges` were NOT added this phase
  — the app container needs to write to `storage/logs`/`storage/kyc` (bind-mounted from the host)
  and Apache needs to manage its own runtime files, so a read-only root filesystem would need
  explicit `tmpfs`/volume carve-outs to avoid breaking startup; left as a documented future
  hardening step rather than risking an under-tested change to a working deployment shape.

## 13. Migration safety

`cron/db-migrate.php --apply` now serializes via `GET_LOCK('ellsms_db_migrate_apply', 30)` (Phase
10, same primitive as §10's bootstrap-admin fix) — two concurrent `--apply` runs can no longer race
on the ledger insert or on applying the same file twice. `--status` remains read-only, no lock
needed. Every migration file itself is independently idempotent (`CREATE TABLE IF NOT EXISTS`,
guarded `ALTER TABLE`) — this lock adds serialization on top of that, it doesn't replace it. No
migration ever runs automatically from the web or worker container — `make migration-status` /
`make migration-preflight` (aliases for `db-migrate.php --status` and a review pass) are explicit
operator steps, per the deployment procedure in §14 of the final report.

## 14. Operational commands (Phase 10, new)

| Command | Mutates? | Purpose |
|---|---|---|
| `make config-check` | no | STEP 3/4 — production configuration validation |
| `make predeploy-check` | no | STEP 35 — config-check + DB reachability + migration status + writable dirs + boundary-check, composed into one pre-deploy gate |
| `make smoke-test URL=https://...` | no | STEP 36 — real HTTP checks against a running deployment (liveness, readiness, login, protected-route denial, internal-file exposure, headers) |
| `make release-check` | no | STEP 44 — lint + unit + integration (if configured) + backend-boundary-check + config-check (fixture values) |
| `make production-integrity-check` | no | STEP 43 — aggregates every existing integrity/status tool into one PASS/WARN/FAIL report |
| `make dependency-audit` | no | STEP 27 — reports PHP version/extensions/composer.lock for manual review (no network-based CVE feed is queried; this project ships no runtime Composer dependencies at all — see docker/Dockerfile, which never runs `composer install`) |

## 15. Security checklist (pre-production)

- [ ] `make config-check` passes (or only WARN-level findings, all understood)
- [ ] `TRUSTED_PROXY_IPS` set if deployed behind a reverse proxy
- [ ] `BACKEND_SERVICE_ID`/`_SECRET` configured AND a backend-side verifier is deployed (§9 — until
      then, signing is prepared but not protective)
- [ ] real, non-placeholder `BACKEND_DB_PASS`/`BACKEND_SERVICE_SECRET`/`ZARINPAL_MERCHANT_ID`
- [ ] `make predeploy-check` passes
- [ ] `make smoke-test URL=...` passes against the deployed instance
- [ ] `make production-integrity-check` reports PASS (or WARN findings triaged)
- [ ] TLS termination confirmed at the reverse proxy (this app never terminates TLS itself)
- [ ] `.env` file permissions restricted on the host (this project doesn't manage host file
      permissions — operator responsibility)

## 16. External production requirements

Requirements outside this repository's control — see final report §38/§40 for how these factor
into the readiness decision:
- A TLS-terminating reverse proxy (this app has never terminated TLS itself; `request_is_https()`
  depends on either a direct HTTPS connection or a trusted proxy's header — see §4).
- The backend platform's own HMAC verifier (§9) — PARTIAL without it.
- A real production secret value for every credential in §2 — this repository cannot generate or
  provision these.
- Host-level file permissions for the bind-mounted `storage/` directories and `.env`.
- A configured backup schedule and, if used, the backup encryption key generated and stored
  separately from the backups it protects — see `docs/backup-and-disaster-recovery.md` (Phase 11).
  Backup/restore/DR tooling itself is now built and tested; installing a real schedule and
  verifying it against production-scale data remain operator actions this repo cannot perform for
  you.
- If the public API (Phase 12) is enabled: a real, separately-generated `WEBHOOK_MASTER_KEY`
  (`openssl rand -base64 32`, never reused from any other secret in this deployment) —
  `make config-check` fails closed without one whenever `API_ENABLED=1`. See `docs/public-api.md`
  and `docs/webhooks.md`.
