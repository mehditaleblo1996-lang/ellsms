# ELLSMS — Phase 12 Final Report

## Public API, API Keys, Webhooks, Idempotency & Integration Platform

## 1. Executive Summary

Phase 12 adds a small, versioned, tenant-scoped public REST API (`/api/v1/*`) and a signed webhook
delivery system, both **disabled by default** (`API_ENABLED=0`). API authentication (bearer API
keys) is fully independent of the panel's web session and of the internal backend-platform HMAC
scheme (Invariant L — no shared key material or logic). Every write endpoint reuses existing domain
services (`dispatch_message()`, `bulk_queue_job()`, `wallet_balance()`) rather than duplicating
financial/messaging logic (Invariant K). Idempotency is enforced by a real database `UNIQUE`
constraint, proven under genuine two-process concurrency (STEP 18's hard acceptance criterion).
Webhook delivery includes fail-closed SSRF URL validation, AES-256-GCM secret encryption,
HMAC-SHA256 signing, and a dedicated retry/dead-letter worker isolated from the SMS worker. All 20
acceptance criteria are met. **Production readiness: CONDITIONALLY READY** — see §43.

## 2. Public API Invariants

| # | Invariant | Status |
|---|---|---|
| A | Every API key belongs to exactly one organization | MET — `ellsms_api_keys.organization_id NOT NULL`, FK'd |
| B | An API key grants nothing outside its organization | MET — every handler scopes every query by `organization_id`; proven in `PublicApiHttpTest` cross-tenant tests |
| C | API key auth is distinct from web session auth | MET — `app/Api/Auth.php` never touches `$_SESSION` |
| D | API scopes are explicit and fail closed | MET — `ApiScopes::normalize()` rejects any unrecognized scope at write time |
| E | Raw API keys never stored after creation | MET — only a SHA-256 hash persists; proven in `ApiKeyLifecycleTest` |
| F | Revocation takes effect promptly | MET — no cache; every auth call re-reads `status` from the DB; proven immediately-after-revoke in tests |
| G | Idempotency where duplicate execution could cause harm | MET — required (not optional) on `POST /messages`, `POST /bulk-jobs` |
| H | No secrets/stack traces/SQL/paths in responses | MET — `ApiResponse` envelope is the only response path; `ErrorHandler`-equivalent try/catch at the front controller |
| I | Webhook signatures verifiable and replay-resistant | MET — HMAC-SHA256 + timestamp tolerance window, documented reference verifiers in PHP and Node.js |
| J | Webhook retries never create duplicate event identities | MET — `event_uuid` stable across retries; proven in `WebhookDeliveryTest` |
| K | Public API actions use existing domain services | MET — no parallel wallet/queue logic |
| L | Public API and internal Backend HMAC never share keys | MET — entirely separate files, entirely separate secrets (`WEBHOOK_MASTER_KEY`/API secrets vs. `BACKEND_SERVICE_SECRET`) |

## 3. API Versioning

`/api/v1` only, no unversioned path. Additive-only compatibility policy documented in
`docs/public-api.md`. No `v2` work performed or implied.

## 4. API Routes

`GET /me`, `GET /organization`, `POST /messages`, `GET /messages/{id}`, `POST /bulk-jobs`,
`GET /bulk-jobs/{id}`, `GET|POST /contacts`, `GET|PATCH|DELETE /contacts/{id}`, `GET /balance`,
`GET|POST /webhooks`, `GET|PATCH|DELETE /webhooks/{id}`, `POST /webhooks/{id}/rotate-secret`,
`POST /webhooks/{id}/test`. Routed by `app/Api/Router.php`, dispatched from the single front
controller `public/api/index.php`.

## 5. Authentication Model

`Authorization: Bearer ellsms_{live|test}_{prefix}_{secret}` only — never a query string, never a
cookie. `app/ApiKeys.php::api_key_authenticate()` re-validates status/expiry/organization on every
single request (no cache). A missing/malformed/wrong/revoked/expired key is uniformly `401` —
verified in `PublicApiHttpTest`.

## 6. API Key Format

`ellsms_{environment}_{12-hex-char prefix}_{secret}`. Prefix: 6 random bytes, hex — public,
indexed, not secret. Secret: 32 random bytes, base64url — 256 bits of entropy.

## 7. API Key Storage

Only `key_prefix` (plaintext lookup) and `secret_hash` (hex SHA-256 of the secret) are stored.
**Deliberate departure from `password_hash()`/Argon2id** (used for user login passwords elsewhere
in this codebase): Argon2id is intentionally slow to defend a low-entropy human password against
offline brute force; an API secret already carries 256 bits of CSPRNG entropy, so a slow hash buys
no additional security and would materially hurt this hot path's latency. See `app/ApiKeys.php`'s
docblock for the full reasoning.

## 8. Key Lifecycle

Create (name, environment, scopes; raw secret shown once) / List / Revoke (immediate) / Rotate
(revoke + reissue, no overlap window — documented as a possible future enhancement, not implemented
this phase) / optional `expires_at`. UI: `/api-keys.php`, gated by `Permissions::API_KEYS_MANAGE`
(owner/admin by default — inherited automatically through the existing `role_permissions()`
"everything except KYC" mechanism, zero RBAC code changes needed).

## 9. Scope Catalog

`messages:send`, `messages:read`, `bulk:write`, `bulk:read`, `contacts:read`, `contacts:write`,
`balance:read`, `webhooks:read`, `webhooks:write` — `app/Support/ApiScopes.php`. Deliberately
separate layer from organization RBAC (`Permissions::API_KEYS_MANAGE`/`WEBHOOKS_MANAGE`) — RBAC
gates who may create/rotate/revoke a key; scopes gate what an issued key may call.

## 10. Tenant Isolation

Every handler resolves `organization_id` exclusively from the authenticated principal, never from
request input. Cross-tenant id access returns `404` (not `403`, to avoid confirming existence) —
proven for contacts read/delete and organization lookup in `PublicApiHttpTest`; proven for API-key
ownership at the business-logic layer in `RbacApiManagementTest`.

## 11. Rate Limiting

Per-API-key (sustained `API_RATE_LIMIT_PER_MINUTE`=60 default + burst `API_RATE_LIMIT_BURST`=15/10s),
per-organization (5× the per-key ceiling), and per-IP (10× — via the existing trusted-proxy-aware
`client_ip()`), built entirely on the existing `app/rate_limit.php` DB-backed sliding window — no
Redis. `429` + `Retry-After`. A revoked/invalid key only ever consumes the IP bucket (verified: it
never reaches the per-key/org buckets). Forged `X-Forwarded-For` from an untrusted peer has zero
effect — proven in `ApiRateLimitHttpTest`.

## 12. Request Validation

`app/Api/Request.php`: `Content-Type: application/json` required on writes (`415` otherwise), body
size capped at `API_MAX_BODY_BYTES` (256KB default) checked via `Content-Length` AND actual bytes
read (a lying header doesn't bypass the cap), malformed JSON → `400`. Every field explicitly
type/format-checked per handler — no raw request array ever reaches a domain function unvalidated.

## 13. Idempotency

`app/Idempotency.php`. `Idempotency-Key` required on `POST /messages` and `POST /bulk-jobs`.
Concurrency primitive: `UNIQUE(organization_id, endpoint, idempotency_key)` — the first INSERT
wins atomically; a loser either replays the winner's exact stored response, gets `409 conflict` (a
different request body under the same key), or `409` (still in-flight after an 8-second poll
window). A crashed claim (never completed) self-heals after 120 seconds via a reclaim path — proven
in `IdempotencyKeyTest`/`IdempotencyConcurrencyTest`.

## 14. Concurrent Idempotency Result

**HARD ACCEPTANCE CRITERION — PASSED.** `tests/Integration/IdempotencyConcurrencyTest.php`, two
genuinely separate OS subprocesses (own MySQL connections) fire the identical Idempotency-Key at
~0ms apart (one deliberately does 300ms of simulated work to widen the race window). Result: exactly
one row written to the database (proven by direct `COUNT(*)`, not by trusting either subprocess's
self-report), the losing process reported `action=replay`, and **both processes received a
byte-identical response body**. A second test proves a different request body under the same key is
rejected as `conflict` and never executes. 2 tests, 21 assertions, both green.

## 15. Message API

`POST /api/v1/messages` — synchronous, through `dispatch_message()` unchanged (reservation/commit/
release, originator authorization all identical to the panel Send page). `dispatch_message()`'s
return signature gained three trailing elements (`sentCount`/`totalCount`/`parts`) — purely
additive; verified every pre-existing call site (`send.php`, `new-send.php`, `send_2fa_code()`)
still only destructures the first two elements via PHP list-assignment, which silently ignores
extras. Result recorded in the API's own `ellsms_api_messages` table (never a direct read/write
against backend-owned `outbound_message`). `GET /messages/{id}` reads that same table, org-scoped.

## 16. Bulk API

`POST /api/v1/bulk-jobs` — thin validation layer over the existing `bulk_queue_job()`, same queue,
same worker (`run_bulk_send_pass()`), same reservation/lease/retry guarantees as a web-created job.
`GET /bulk-jobs/{id}` returns a status summary only (STEP 2's allowed scope reduction) — no
per-item listing endpoint in v1.

## 17. Contacts API

Full CRUD over `ellsms_contacts`, strictly `organization_id`-scoped (a deliberately stricter rule
than the web UI's own legacy `organization_id IS NULL` fallback — an API key only ever exists for
an already-created organization, so there's no legacy case to honor). Cursor pagination
(`limit`/`after`, max 200, default 50).

## 18. Balance API

`GET /balance` reads `wallet_balance()` — never `user_.currentcredit` directly. An API key acts, for
wallet purposes, on behalf of its creating user (`created_by_user_id`) — this codebase's wallet
model is strictly per-user (Phase 3); this phase does not introduce a new per-organization wallet.

## 19. Error Format

`{"error": {"code", "message", "request_id", ["fields"]}}` — one shape, `app/Api/Response.php`, no
exceptions. Code table: `invalid_request` 400, `unauthenticated` 401, `forbidden` 403, `not_found`
404, `conflict` 409, `payload_too_large` 413, `validation_failed` 422, `rate_limited` 429,
`internal_error` 500, `service_unavailable` 503 (plus an unlisted `415` for content-type mismatch —
documented, not in the original code table since HTTP defines it separately). Verified: no error
message ever contains a file path, `.php`, or `SELECT` — `ApiResponseFormatTest`.

## 20. Pagination

Cursor-based (`limit`+`after`), hard max 200, tenant filter always applied before the query runs —
`GET /contacts` verified in `PublicApiHttpTest`.

## 21. Request Correlation

`X-Request-Id` on every response (mirrors the error body's `request_id`); an optional caller-
supplied `X-Request-ID` is validated (length/charset) and otherwise ignored, never trusted blindly.

## 22. API Audit and Metrics

`api.request.completed` logged for every request (method, path, status, key prefix — never the raw
key, organization id, duration). `api_key.created/revoked/rotated` and
`webhook.endpoint.created/updated/secret_rotated/deleted` recorded via the existing `audit()`
mechanism. Auth failures logged with a coarse category (`no_credentials`/`invalid_credentials`),
never granular enough to help an attacker distinguish a valid prefix from an invalid one externally.
Message/webhook payload content is never logged.

## 23. Webhook Architecture

`ellsms_webhook_endpoints` / `ellsms_webhook_events` (the outbox) / `ellsms_webhook_deliveries`
(one row per event×endpoint attempt lifecycle, claim/lease/retry columns mirroring Phase 4's bulk-
item shape exactly). `app/Webhooks.php` owns all business logic; `cron/webhook-worker.php` owns the
actual HTTP delivery loop, as a dedicated container/process separate from the SMS worker.

## 24. Event Catalog

`message.sent`, `message.failed`, `bulk.completed`, `bulk.failed`, `payment.credited` —
`app/Support/WebhookEvents.php`. `message.sent`/`failed` cover API-initiated sends only (see §42);
`bulk.*`/`payment.credited` cover the underlying action regardless of origin (panel or API).

## 25. Endpoint Security/SSRF

Fail-closed at creation AND immediately before every delivery attempt (DNS-rebinding-aware):
`https://` required in production, no embedded credentials, no `localhost`/`.local`, every resolved
A/AAAA record checked against a blocklist covering loopback/RFC1918/link-local (including
`169.254.169.254`)/CGNAT/documentation/multicast ranges for both IPv4 and IPv6 (including
IPv4-mapped IPv6). No redirects ever followed. 16 dedicated unit tests
(`WebhookSsrfValidationTest`), plus real end-to-end proof in `WebhookDeliveryTest` (the SSRF check
itself is bypassed only via a test-only, non-production-activatable env flag documented in §41).

## 26. Secret Protection

Webhook signing secrets: AES-256-GCM envelope encryption under `WEBHOOK_MASTER_KEY` (32 bytes,
required once `API_ENABLED=1`, `config-check` FAILs without it), fresh random nonce per encryption,
GCM auth tag rejects tampered ciphertext. 6 unit tests including wrong-key and tampered-ciphertext
rejection (`WebhookSecretEncryptionTest`). API key secrets: never stored at all, only a SHA-256
hash (see §7).

## 27. Signature Protocol

`X-ELLSMS-Event-ID` / `X-ELLSMS-Timestamp` / `X-ELLSMS-Signature` headers.
`signature = hex(HMAC_SHA256(secret, timestamp + "." + raw_body))`. Reference verifiers in PHP and
Node.js in `docs/webhooks.md`, backed by the identical function ELLSMS itself uses
(`webhook_signature_verify()`), so doc and implementation cannot drift apart. Proven end-to-end
against a real HTTP receiver in `WebhookDeliveryTest` — the delivered signature verifies correctly
using only the secret returned at endpoint-creation time.

## 28. Replay Protection

Timestamp tolerance window (documented default 300s), constant-time comparison
(`hash_equals()`), stable `event_id` across retries so a receiver can de-duplicate even within the
tolerance window.

## 29. Delivery/Retry/Dead-Letter

Retryable: 408/425/429/5xx/timeout/connection-failure. Permanent: 400/401/403/404/410/422/SSRF-
blocked. Bounded exponential backoff (reuses the existing `job_retry_backoff_seconds()` — same
schedule as the SMS job queue). `WEBHOOK_MAX_ATTEMPTS` (default 8) before a still-retryable
delivery dead-letters; a permanent failure fails immediately on attempt 1. Endpoint auto-disables
after 20 consecutive **terminal** failures (never a single transient blip). All proven against a
real receiver process in `WebhookDeliveryTest`: successful delivery+signature, retryable
reschedule, permanent immediate-fail, exhausted-retries dead-letter, response truncation, stable
event identity across a real retry, and abandoned-lease crash recovery — 7 tests, 81 assertions.

## 30. Webhook Observability

`make webhooks-status` (queue depth by status, disabled endpoints + reasons — never message
content), `make webhook-retry-failed ID=` (preserves event identity), panel endpoint status display.

## 31. Migrations

`db/migrations/2026_08_05_public_api.sql` — six new tables (`ellsms_api_keys`,
`ellsms_idempotency_keys`, `ellsms_webhook_endpoints`, `ellsms_webhook_events`,
`ellsms_webhook_deliveries`, `ellsms_api_messages`). Every FK is ELLSMS-owned-to-ELLSMS-owned, no
new reference to a backend-owned table. `CREATE TABLE IF NOT EXISTS`, natively idempotent. Not
auto-applied by any code path (standing project rule, unchanged).

## 32. Configuration

New variables (all documented in `.env.example`, all with safe defaults, `API_ENABLED=0` and
`WEBHOOK_ALLOW_PRIVATE_TARGETS=0` being the two safety-critical ones): `API_ENABLED`,
`API_RATE_LIMIT_PER_MINUTE`, `API_RATE_LIMIT_BURST`, `API_MAX_BODY_BYTES`, `API_MAX_BULK_ITEMS`,
`API_IDEMPOTENCY_TTL_HOURS`, `WEBHOOK_MASTER_KEY`, `WEBHOOK_TIMEOUT_SECONDS`,
`WEBHOOK_MAX_ATTEMPTS`, `WEBHOOK_MAX_RESPONSE_BYTES`, `WEBHOOK_REQUIRE_HTTPS`,
`WEBHOOK_ALLOW_PRIVATE_TARGETS`, `WEBHOOK_DELIVERY_RETENTION_DAYS`, `WEBHOOK_EVENT_RETENTION_DAYS`.
`cron/config-check.php` extended: FAILs on a missing/malformed `WEBHOOK_MASTER_KEY` whenever
`API_ENABLED=1`, on `WEBHOOK_REQUIRE_HTTPS=0` in production, and on
`WEBHOOK_ALLOW_PRIVATE_TARGETS=1` in production (mirrors the existing `ELLSMS_ALLOW_LOAD_TEST`
pattern exactly).

## 33. Operational Commands

`cron/api-keys-status.php`, `cron/webhooks-status.php`, `cron/webhook-retry-failed.php`,
`cron/webhook-prune.php` (dead-letter rows excluded unless `--include-dead-letter`),
`cron/idempotency-prune.php`, `cron/webhook-worker.php` (`--once` supported) — wired into the
Makefile with matching `make` targets and `make help` text.

## 34. API Documentation/OpenAPI

`docs/public-api.md`, `docs/webhooks.md` (narrative reference, matches implementation exactly —
verified against the actual route table and handler code while writing this report), and
`docs/openapi-v1.yaml` (machine-readable, every route/schema/response code above appears there).

## 35. Security Test Results

Every STEP 44/45 checklist item has a real, passing test: missing/malformed/wrong/revoked/expired
key (401), key-in-query-string rejected, missing/crafted scope (403), cross-tenant read/delete
(404, real DB proof the row is untouched), rate limit (429+Retry-After), forged
X-Forwarded-For (no bypass), oversized body (413), invalid JSON (400), content-type mismatch (415),
idempotency conflict/concurrent-duplicate (409/hard acceptance criterion), raw secret never
persisted, webhook SSRF (16 unit tests), webhook signature correctness (real HTTP proof), webhook
replay identity (stable across a real retry), webhook retry classification (real HTTP proof for
both retryable and permanent), webhook secret rotation, response-body truncation (real HTTP proof),
no sensitive log leakage (redaction pattern already covers `authorization`/`token`/`secret`).

## 36. Full Test Results

- **Lint:** 185 PHP files, clean.
- **Unit:** 232 tests, 446 assertions, 0 failures (163 pre-Phase-12 + 69 new: 65 across 9 new test
  files + 4 new methods added to the existing `ConfigCheckTest`).
- **Integration:** 221 tests, 897 assertions, **0 failures** in the final clean-ledger run (a prior
  run in the same session hit the single pre-existing, previously-documented
  `DatabaseOperationalScriptsTest` migration-ledger-staleness artifact — the same limitation
  Phase 8/9/10/11 already disclosed, not a Phase 12 regression — resolved by truncating
  `ellsms_schema_migrations` before the final run, the same known/accepted operational quirk of
  this long-lived shared test database). 52 new integration tests across 6 new files, all passing,
  including the hard concurrency acceptance criterion.
- **Backend boundary check:** PASS — no new file added to the allowlist; Phase 12 introduces zero
  new direct references to `user_`/`domain`/`inbound_message`/`outbound_message`.
- **Docker build:** `app`, `worker`, and the new `webhook-worker` service all build cleanly.
  Real end-to-end verification against a live Apache container + real test database: unauthenticated
  request → `401`; authenticated request with a real key → `200` with correct balance data; unknown
  route → `404`; `/health` unaffected. See §41 for a real bug this surfaced and fixed.

## 37. Files Created

Migration: `db/migrations/2026_08_05_public_api.sql`. App: `app/ApiKeys.php`, `app/Idempotency.php`,
`app/Webhooks.php`, `app/Support/ApiScopes.php`, `app/Support/WebhookEvents.php`,
`app/Api/{Response,Request,Auth,RateLimit,Router}.php`,
`app/Api/Handlers/{Meta,Messages,BulkJobs,Contacts,Balance,Webhooks}.php`. Front controller:
`public/api/index.php`. Web UI: `public/api-keys.php`, `public/webhooks.php`. Worker:
`cron/webhook-worker.php`. Operational scripts: `cron/api-keys-status.php`,
`cron/webhooks-status.php`, `cron/webhook-retry-failed.php`, `cron/webhook-prune.php`,
`cron/idempotency-prune.php`. Tests: 9 new `tests/Unit/*.php`, 6 new `tests/Integration/*.php`,
`tests/fixtures/fake_webhook_receiver.php`, `tests/fixtures/idempotency_concurrent_worker.php`.
Docs: `docs/public-api.md`, `docs/webhooks.md`, `docs/openapi-v1.yaml`,
`docs/phase-12-final-report.md`.

## 38. Files Modified

`app/bootstrap.php` (require the five new Phase 12 files), `app/backend.php` (`dispatch_message()`
additive return values; bulk-completion webhook emission), `app/zarinpal.php` (payment-credited
webhook emission), `app/maintenance.php` (JSON-shaped 503 for `/api/v1/*` during maintenance),
`app/Support/Permissions.php` (+4 permission constants), `app/views/header.php` (conditional
integration nav), `cron/config-check.php` (+9 findings), `.env.example`, `docker-compose.yml`
(+webhook-worker service, +env vars), `docker/Dockerfile` (rewrite rule — see §41), `Makefile`
(+11 targets), `README.md`, `docs/architecture.md`, `docs/technical-debt.md`,
`docs/production-hardening.md`, `tests/Unit/ConfigCheckTest.php` (baseline fixture + 4 new tests).

## 39. Breaking Changes

None to any existing behavior. `dispatch_message()`'s return signature grew from 3 to 6 elements —
verified additive-only (every existing call site destructures only the first two via list-
assignment, which PHP silently tolerates for a longer array). No existing route, permission
default, or configuration default changed meaning.

## 40. Deployment Procedure

1. `make config-check` / `make predeploy-check` (both already pass with the API left off).
2. Apply `db/migrations/2026_08_05_public_api.sql` (`make db-migrations-apply`).
3. Generate `WEBHOOK_MASTER_KEY` (`openssl rand -base64 32`), set alongside `API_ENABLED=1`.
4. `make config-check` again — now validates the new webhook config.
5. Deploy app + worker + webhook-worker containers.
6. Create a test API key at `/api-keys.php`, call `GET /api/v1/me`.
7. Send a real idempotent test request (`POST /api/v1/contacts`).
8. Configure a test webhook endpoint, call `POST /webhooks/{id}/test`, verify signed delivery.
9. Monitor `make webhooks-status` / `make api-keys-status` / application logs.

`API_ENABLED=0` remains the shipped default — no existing install is affected merely by pulling
this code.

## 41. Rollback Considerations

The migration is purely additive (six new tables) — rolling back application code without rolling
back the migration is safe (the tables simply go unused). Disabling `API_ENABLED` instantly and
completely deactivates the entire feature with no other action needed. No existing table was
altered.

**A real bug found and fixed during this phase's own Docker verification** (not a design flaw
disclosed as debt, an actual defect caught and closed before sign-off): the initial Apache rewrite
rule for `/api/v1/*` was written as a separate `<Directory>` block in its own `conf-enabled/*.conf`
file, following the same pattern as the pre-existing health-check rewrite. Empirically, Apache 2.4
on this image does **not** reliably merge `RewriteRule` directives across multiple separate
`<Directory "${APACHE_DOCUMENT_ROOT}">` blocks declared in different included files — the second
block's rules were silently never evaluated, despite `apachectl -t` reporting valid syntax and both
files loading (confirmed via `LogLevel rewrite:trace8`). Fixed by consolidating every rewrite rule
for this docroot into the single existing `<Directory>` block (renamed `ellsms-rewrites.conf`).
Verified against a real running container + real database: `401` for no credentials, `200` with a
real key, `404` for an unknown route, `/health` unaffected.

## 42. Remaining API Risks

- `message.sent`/`message.failed` webhook events fire only for API-initiated sends, not
  panel-UI-initiated ones (disclosed in `docs/technical-debt.md`, deliberate v1 scope decision).
- API key rotation has no overlap window — an integration must be updated to the new secret before
  the old one is revoked, or it will see `401` in between.
- `WEBHOOK_ALLOW_PRIVATE_TARGETS` exists purely to make real webhook delivery integration-testable
  (every locally reachable test receiver is, by definition, inside the SSRF blocklist) — it is
  off by default, non-activatable in production (`app_env() !== 'production'` check baked into the
  function itself, independent of the env var), and `config-check` additionally FAILs if it's ever
  set with `APP_ENV=production`. Documented explicitly rather than silently present.
- No organization-level wallet — an API key spends against its creating user's personal wallet
  (matches this codebase's existing strictly-per-user wallet model; not a new limitation Phase 12
  introduces, just an explicit consequence of not inventing new financial semantics).

## 43. Remaining External Conditions

Everything in this repository is implemented and tested. External, operator-side prerequisites for
a live production rollout of the public API specifically (beyond Phase 11's already-disclosed
backup-schedule/encryption-key items, which remain unchanged):
- Generate and securely store a real `WEBHOOK_MASTER_KEY`, separate from every other secret in the
  deployment.
- Decide and communicate a real rate-limit policy per customer tier if `API_RATE_LIMIT_PER_MINUTE`'s
  default (60/min) isn't right for your integrators.
- If webhooks will be used at real customer scale, monitor `make webhooks-status` operationally —
  the auto-disable-at-20-consecutive-failures threshold is a safety net, not a substitute for
  active monitoring.

**PRODUCTION READINESS DECISION: CONDITIONALLY READY** — same standing as every prior phase: all
repository-controlled work is complete, tested (lint clean, 232 unit + 221 integration tests with
one disclosed pre-existing environment artifact, real Docker verification), and documented; the
conditions above are genuinely external and cannot be satisfied from within this repository.

## 44. Phase 13 Readiness

Phase 12 is complete and closed. Per this phase's own governing instructions, **Phase 13 must not
begin automatically** — it requires an explicit new instruction.
