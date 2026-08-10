# Phase 8 Final Report — Backend Service Boundary Enforcement

## 1. Executive Summary

Phase 8 draws a hard boundary between ELLSMS-owned data and the four tables (`user_`, `domain`,
`inbound_message`, `outbound_message`) a separate, external backend platform owns on the same
shared MySQL instance. Before this phase, ELLSMS's own code queried those tables directly from
dozens of scattered call sites across controllers, workers, and cron scripts. This phase:

1. Built four adapter files under `app/Backend/` (`identity.php`, `messages.php`,
   `credit_projection.php`, `ApiClient.php`) as the sole point of contact with each backend-owned
   table family.
2. Migrated every call site — 20+ files across `app/`, `public/`, `cron/` — to use them.
3. Removed a silent write-fallback that used to fabricate rows in the backend-owned
   `outbound_message` table on API failure.
4. Built an automated, CI-enforceable boundary scanner (`make backend-boundary-check`) so the
   boundary cannot silently erode again.
5. In this closure pass specifically: found and fixed a real retry-classification defect in the
   messaging dispatch path, fixed a schema-drift bug in the integration test fixtures, and added
   the test coverage the migration itself had not yet earned (tenant-isolation-at-the-repository
   layer, the full HTTP failure matrix, the no-write-fallback guarantee, and readiness behavior).

**PHASE 8 STATUS: PASS** — see the Final Response at the end of this document for the full
evidence table.

## 2. Backend-Owned Tables

| Table | Owner | ELLSMS access |
|---|---|---|
| `user_` | backend platform | read/write, via `app/Backend/identity.php` (+ one compatibility write, §8) |
| `domain` | backend platform | read-only, via `app/Backend/identity.php` |
| `outbound_message` | backend platform | read-only, via `app/Backend/messages.php` |
| `inbound_message` | backend platform | read-only, via `app/Backend/messages.php` |

Full ownership matrix, function-by-function, is `docs/service-boundaries.md` — this report covers
migration history and validation evidence; that document is the living technical reference.

## 3. Approved Boundary Exceptions

Enforced by `cron/backend-boundary-check.php`'s allowlist, not by convention. As of this closure
pass, `make backend-boundary-check` reports:

| Location | Reason |
|---|---|
| `app/Backend/credit_projection.php` | the one controlled `UPDATE user_ SET currentcredit` write |
| `app/Backend/identity.php` | identity/domain repository — every `user_`/`domain` access |
| `app/Backend/messages.php` | inbound/outbound repository — every message-table access |
| `app/Backend/ApiClient.php` | transport client, kept in the adapter directory for symmetry |
| `cron/backend-boundary-check.php` | its own docblock quotes example SQL for illustration |
| `cron/db-integrity-check.php` | orphan/consistency audit tool must read both sides directly |
| `tests/Integration/*.php` (8 files), `tests/fixtures/*.sql` | integration fixtures seed/read the real shared schema on purpose |

Every exception above is individually justified in the scanner's own `$allowlist` array, not a
directory-level or pattern-level wildcard.

## 4. Identity Provider

`app/Backend/identity.php` — see `docs/service-boundaries.md` §3 for the full function table.
Verified in this pass to cover: login/pre-auth (`login.php`, `bootstrap-admin.php`), 2FA state
(`verify-2fa.php`), session identity (`current_user()`), password change/reset (`profile.php`,
`users.php`), worker user-state revalidation (schedule/autoreply/bulk — 3 call sites), and every
username-display join across the admin/report surface (tickets, schedules, autoreply rules, bulk
jobs, number assignment, organization membership, reports).

## 5. Messaging Provider

`app/Backend/ApiClient.php`'s `backend_api_request()` — the one authenticated HTTP client. Verified
in this pass that direct send, P2P, smart send, the bulk worker, the schedule worker, the
auto-reply worker, and 2FA SMS all converge on `dispatch_message_raw()` /
`dispatch_message_retryable()` / `dispatch_message()`, all of which call this one function — no
duplicate cURL implementations. One justified exception: `app/Support/HealthCheck::backendApi()`'s
bare TCP/TLS reachability probe (§10). `app/telegram.php`/`app/zarinpal.php` also call
`curl_init()` directly but talk to unrelated external services, not the backend platform.

## 6. Inbound Repository

`app/Backend/messages.php`'s inbound functions — tenant scoping is enforced by the caller
(`public/inbox.php`'s `allowed_originators()`-derived WHERE clause, fail-closed to `1 = 0` when
empty), not by the repository itself. Proven against real MySQL by
`tests/Integration/MessageRepositoryTenantIsolationTest.php`, added this pass (see §15).

## 7. Outbound Repository

Same file, outbound functions — tenant scoping enforced by `public/reports.php`'s
`organization_member_user_ids()`-derived WHERE clause. Same test file proves cross-organization
isolation.

## 8. Legacy Credit Projection

`app/Backend/credit_projection.php`'s `backend_sync_legacy_credit_projection()` is the only
`UPDATE user_ SET currentcredit` in the codebase (enforced by the boundary scan identically to
every other backend-table access). Its only caller is `app/wallet.php`'s
`wallet_sync_legacy_currentcredit()`, called from every wallet-mutating function inside the same
transaction as the real wallet write. `BACKEND_LEGACY_CREDIT_SYNC_ENABLED` (default `1`) can
disable it entirely. `wallet_drift_report()` detects (never auto-corrects) divergence — covered by
pre-existing tests in `tests/Integration/WalletIntegrationTest.php`, re-verified passing this pass.

## 9. HMAC Contract

`backend_service_auth_headers(method, path, rawBody, requestId)` — HMAC-SHA256 over
`method|path|timestamp|sha256(body)|serviceId`. Opt-in (no-op unless both `BACKEND_SERVICE_ID` and
`BACKEND_SERVICE_SECRET` are set). This pass rewrote `tests/Unit/BackendServiceAuthTest.php`
(the pre-existing test file was still calling the function with its old 1-argument signature from
before last session's `ApiClient.php` centralization, and was failing) and added dedicated proofs
that method, path, and body are each independently bound into the signature, that a wrong secret
produces a different signature, and that the request id is carried but deliberately NOT part of
the signed content. 10/10 unit tests pass.

## 10. Backend Verifier Status

**PARTIAL.** This repository contains the client-side signer only — there is no backend-side
verifier here to test against, because the backend platform is a separate codebase this repository
does not contain. Concretely: no stale-timestamp/replay rejection can be proven from this repo,
and true end-to-end authentication (the backend actually rejecting a bad signature) is untestable
here. This has been the disclosed status since Phase 2 (`docs/security-review.md` finding 5); Phase
8 keeps it disclosed rather than silently implying it's fixed.

## 11. Failure/Fallback Policy

`backend_api_request()` classifies every outcome (`BackendError::{UNAVAILABLE, TIMEOUT,
UNAUTHORIZED, REJECTED, CONFLICT, INVALID_RESPONSE, PERMANENT}`) — full table in
`docs/service-boundaries.md` §9. **Real defect found and fixed this pass**:
`dispatch_message_raw()` (`app/backend.php`) previously hardcoded `retryable = true` for every
non-2xx outcome, including permanent rejections (401/403/409/422) — meaning a misconfigured HMAC
secret or a malformed-destination validation error would burn through a worker's full
`JOB_MAX_ATTEMPTS` retry/backoff budget before finally reaching the same permanent outcome it
should have hit immediately. Fixed to derive `retryable` from `BackendError::isRetryable()`.
Covered by 13 new tests in `tests/Integration/ApiClientFailureModelTest.php`, run against a real
local HTTP fixture server (no HTTP mocking library exists in this project's dependencies, and
`backend_api_request()` owns `curl_init()` directly, so a real socket was used rather than mocking
PHP-level HTTP calls).

## 12. No-Write-Fallback Proof

Confirmed at three levels:
1. **Code inspection**: `dispatch_message_raw()`'s unreachable/failed branch calls
   `backend_record_message_attempt_failure()` (writes ELLSMS's own `ellsms_message_attempts`), not
   any `outbound_message` statement.
2. **In-process integration test** (`tests/Integration/NoBackendWriteFallbackTest.php`, new this
   pass): `outbound_message` row count is unchanged before/after a simulated API failure, and
   exactly one row is added to `ellsms_message_attempts` with the correct
   `user_id`/`reference_type`/`status`/`error_code`.
3. **Real subprocess proof**: a schedule row was inserted directly into the disposable test
   database, `php cron/worker.php --once` was run as an actual CLI subprocess (not an in-process
   function call) against it, and the result was verified directly in MySQL:
   `outbound_message` stayed at **0 rows** throughout; `ellsms_message_attempts` gained exactly one
   row (`status=failed`, `error_code=BackendUnavailable`, `reference_type=schedule`); the schedule
   row correctly stayed `active` with a scheduled retry. Probe data was cleaned up afterward.

## 13. Worker Integration

`run_due_schedules()`, `autoreply_process_one()`/`autoreply_process_batch()`, and
`bulk_send_one_item()` all revalidate user state via `backend_find_user_by_id()` at execution time
(not just at creation time) and dispatch via `dispatch_message_raw()`/`dispatch_message_retryable()`
— the same single messaging boundary as every other send path (§5). The retry-classification fix
in §11 directly affects worker behavior: a permanent rejection now finalizes immediately instead of
consuming the full retry budget.

## 14. Tenant/RBAC Enforcement

Every send/dispatch entry point (`send.php`, `new-send.php`, `p2p-send.php`, `smart-send.php`,
`schedules.php`, `autoreply.php`) calls `require_login()` and (for non-admins)
`require_permission()` before any backend call. Pre-existing `tests/Integration/TenantIsolationTest.php`
(23 tests) covers sender/schedule/bulk/contact/campaign/ticket/category isolation; this pass added
message-repository-level isolation (§6/§7) that wasn't previously covered. All re-verified passing.

## 15. Boundary Scan Result

```
$ make backend-boundary-check
ELLSMS backend-table boundary scan
Scanned 111 PHP file(s) under app, public, cron, tests
Watched tables: user_, domain, inbound_message, outbound_message

=== Approved exceptions (55) ===
  [14 files, individually justified — see §3]

backend-boundary-check: PASS — no direct backend-table access outside the allowlist.
```

Exit code 0. The scanner matches against each file's full content (not line-by-line) with a real
regex word boundary — deliberately built this way after discovering the manual audit earlier in
this phase had used `grep -v "user_id"` to skip already-migrated lines, which also silently
discarded genuine violations sharing a line with the substring `user_id` (e.g.
`JOIN user_ u ON u.id = m.user_id`). That earlier miss is exactly why this automated check exists
now instead of relying on manual greps going forward.

## 16. Readiness Validation

`public/health.php` (liveness: PHP + DB) and `public/health-ready.php` (liveness +
`HealthCheck::backendApi()`) — verified against a real HTTP fixture and real closed-port failures
this pass (`tests/Integration/ApiClientFailureModelTest.php`, 3 tests): reachable, connection
failure, and unconfigured base URL. Response bodies contain only fixed `"ok"`/`"error"` strings per
check plus `app_version()`/`app_env()` — confirmed by code inspection to never interpolate the
backend URL, secret, DB credentials, or exception text.

## 17. Full Test Results

| Suite | Result |
|---|---|
| `make lint` | **PASS** — 111 PHP files parse cleanly |
| Unit (`vendor/bin/phpunit -c phpunit.xml`) | **PASS** — 100 tests, 170 assertions |
| Integration (`phpunit.integration.xml`, real MySQL) | **PASS** — 149 tests, 532 assertions |
| `make backend-boundary-check` | **PASS** — 0 violations, 55 justified exceptions |
| Docker build (`docker compose build`) | **PASS** — `app` and `worker` images both built |
| `php cron/worker.php --once` (real subprocess, real DB) | **PASS** — see §12 |

Note: `DatabaseOperationalScriptsTest` asserts the migration ledger (`ellsms_schema_migrations`)
starts empty on a fresh database — true for a genuinely fresh test container, but this session
reused one long-lived disposable MySQL container (`ellsms-test-mysql`) across many separate
targeted `phpunit` invocations, so the ledger accumulated real entries from earlier runs and that
one test failed on stale state twice during this pass. Not a Phase 8 regression — confirmed by
truncating the test-only ledger table and re-running the full suite, which reproduces 149/149
cleanly every time. A genuinely fresh container (as CI would provide) never hits this.

## 18. Files Created (this closure pass)

- `cron/backend-boundary-check.php` — the automated boundary scanner
- `docs/service-boundaries.md` — living ownership-matrix reference
- `docs/phase-8-final-report.md` — this document
- `tests/Integration/NoBackendWriteFallbackTest.php`
- `tests/Integration/MessageRepositoryTenantIsolationTest.php`
- `tests/Integration/ApiClientFailureModelTest.php`
- `tests/fixtures/fake_backend_server.php` — real-socket HTTP fixture used only by the test above

(The broader Phase 8 adapter/call-site migration — `app/Backend/identity.php`,
`app/Backend/messages.php`, `app/Backend/credit_projection.php`, `app/Backend/ApiClient.php`, and
~20 migrated controller/worker/cron call sites — was completed in the session immediately prior to
this closure pass; `git diff --stat` against `app public cron` shows 31 files, +2105/-633 lines,
for that combined effort.)

## 19. Files Modified (this closure pass)

- `app/backend.php` — `dispatch_message_raw()` retry-classification fix (§11)
- `Makefile` — added the `backend-boundary-check` target and folded it into `make check`
- `tests/Unit/BackendServiceAuthTest.php` — fixed the stale 1-arg call (pre-existing failure),
  added method/path/secret/request-id differentiation tests
- `tests/fixtures/integration_schema.sql` — fixed `inbound_message.created_at` →
  `received_at` schema drift against the real shared schema (§20)

## 20. Migrations

None added this closure pass — no production schema changes were required. One test-fixture-only
correction: `tests/fixtures/integration_schema.sql`'s `inbound_message` table was defined with a
`created_at` column, but the real shared schema (and every piece of production code —
`backend_inbound_today_count()`, `public/inbox.php`'s date filter) uses `received_at`. This drift
meant no integration test had ever exercised a date-scoped inbound query against real MySQL; fixed
by renaming the fixture column. No application code changed.

## 21. Configuration

No new environment variables introduced this closure pass. Existing Phase 8 variables (unchanged,
documented in `.env.example`): `API_BASE_URL` / `api_base_url` setting, `BACKEND_SERVICE_ID`,
`BACKEND_SERVICE_SECRET`, `BACKEND_LEGACY_CREDIT_SYNC_ENABLED`.

## 22. Breaking Changes

None. The retry-classification fix (§11) changes *behavior* (a permanent rejection now fails fast
instead of retrying) but not any external contract — no API, config key, or schema shape changed.

## 23. Remaining Shared-DB Risks

- **Backend verifier does not exist in this repo** (§10) — HMAC signing is proven correct on the
  client side; end-to-end rejection of a forged/stale/replayed request cannot be proven here.
- **`user_.currentcredit` remains a live write path** (§8) — a second, independent source of truth
  for balance, by design, for backward compatibility; `wallet_drift_report()` is the only guard
  against divergence, and it is detect-only.
- **`cron/db-integrity-check.php` remains a documented direct-access exception** — appropriate for
  what it does, but it is worth remembering it is not itself protected by the boundary scan the way
  everything else is (it can't be, by construction).
- **No backend-side schema/contract guarantee** — this repo does not control `user_`,
  `outbound_message`, or `inbound_message`'s actual column shapes; the `received_at` drift found in
  §20 is a reminder that ELLSMS's assumptions about that schema are only as good as the last time
  someone checked them against reality.

## 24. Deployment Procedure

No deployment-order change from prior phases. `make backend-boundary-check` is now part of `make
check` (`lint` + `test` + `backend-boundary-check`) — CI/pre-merge gating should run `make check`
as before; no separate step is required. No migration to apply, no backfill to run.

## 25. Phase 9 Readiness

All Phase 8 acceptance criteria pass (see Final Response). **Phase 9 may begin** once explicitly
requested — this report does not start it.
