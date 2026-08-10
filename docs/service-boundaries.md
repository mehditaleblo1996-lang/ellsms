# ELLSMS — Backend Service Boundaries (Phase 8)

ELLSMS shares one MySQL database with an external backend platform. A handful of tables are
**backend-owned** — the backend platform's own code (not in this repository) writes them, and in
some cases is the only writer at all. Before Phase 8, ELLSMS's own code queried these tables
directly from dozens of scattered call sites. Phase 8 draws a hard boundary: every one of those
tables is now read (and, for the one case that needs it, written) through exactly one adapter file
per table family, under `app/Backend/`. Nothing else may touch them directly — enforced by
`cron/backend-boundary-check.php` (`make backend-boundary-check`), a static scanner that fails the
build if a direct reference appears anywhere outside the approved list.

This document is the ownership matrix and the contract those adapters implement. It is a live
reference, not a point-in-time report — see `docs/phase-8-final-report.md` for the migration
history and validation evidence.

## 1. Backend-owned tables

| Table | Written by | Read by ELLSMS through |
|---|---|---|
| `user_` | the backend platform (account creation, password, active/deleted state) — **and** ELLSMS's own legacy compatibility write, see §4 | `app/Backend/identity.php` |
| `domain` | the backend platform | `app/Backend/identity.php` (`backend_list_domains()` — the only read anywhere) |
| `outbound_message` | the backend platform's `/api/messages/send` endpoint only | `app/Backend/messages.php` |
| `inbound_message` | the backend platform's own `/mo` (inbound SMS) and delivery-report endpoints only | `app/Backend/messages.php` |

ELLSMS never writes `outbound_message` or `inbound_message` at all (see §7, Invariant E). The one
exception across all four tables is `user_.currentcredit`, described in §4.

## 2. Approved boundary exceptions

Everything else touching one of these four tables directly is a violation `make
backend-boundary-check` will fail the build on. Four kinds of exception are pre-approved (see the
`$allowlist` in `cron/backend-boundary-check.php` for the authoritative, machine-enforced list):

1. **The adapter files themselves** — `app/Backend/identity.php`, `app/Backend/messages.php`,
   `app/Backend/credit_projection.php`, `app/Backend/ApiClient.php`.
2. **`cron/db-integrity-check.php`** — the orphan/drift audit tool. It must read both sides of the
   boundary directly; routing it through the adapters it exists to cross-check would make it
   structurally incapable of catching an adapter bug.
3. **Integration test fixtures** (`tests/Integration/*.php`, `tests/fixtures/*.sql`) — they seed
   and read the real shared schema on purpose, to prove the adapters work against real MySQL, not
   against a mock of themselves.
4. **`cron/backend-boundary-check.php`'s own docblock**, which quotes example SQL for illustration.

Any new exception must be added to that allowlist explicitly, with a reason — there is no wildcard
or directory-level opt-out beyond what's listed there.

## 3. Identity provider — `app/Backend/identity.php`

The ONE place `user_` (and the `domain` lookup) is read or written from. Every function does
exactly what the call site it replaced used to do — Phase 8 moved the SQL, it did not change
behavior.

| Function | Used by |
|---|---|
| `backend_find_user_by_id()` | `current_user()`, admin user-management lookups, worker user-state revalidation (schedule/autoreply/bulk) |
| `backend_find_user_for_login()` | `login.php`, `bootstrap-admin.php` |
| `backend_find_user_login_state_by_id()` | `verify-2fa.php` |
| `backend_user_password_hash()` / `backend_update_user_password()` | `profile.php` (self-service), `users.php` (admin reset) — the only two places `user_.password` is ever written from ELLSMS |
| `backend_find_user_id_by_username()` | `organizations.php` (add member), `users.php` (grant access) |
| `backend_usernames_by_ids()` / `backend_users_by_ids()` | every admin-table/report/list page that displays a username next to an ELLSMS-owned row (tickets, schedules, autoreply rules, bulk jobs, number assignment, organization membership, reports) |
| `backend_list_users_summary()` | reports.php's admin filter dropdown |
| `backend_list_domains()` | users.php's account-creation dropdown — the only `domain` read anywhere |
| `backend_panel_access_users()` | autoreply.php/numbers.php assignment dropdowns |

Deliberately does not touch `ellsms_meta`, `ellsms_organization_memberships`, or any other
ELLSMS-owned table — those were never a boundary problem.

## 4. Legacy credit projection — `app/Backend/credit_projection.php`

`user_.currentcredit` is the one field ELLSMS still writes on a backend-owned table — a
compatibility projection for any part of the backend platform that still reads that column
directly instead of ELLSMS's own wallet ledger (`ellsms_wallet_accounts` /
`ellsms_wallet_transactions`, ELLSMS's actual source of truth for balance).

- `backend_sync_legacy_credit_projection(PDO $db, int $userId, int $availableBalance)` is the ONLY
  function that executes `UPDATE user_ SET currentcredit = ?` anywhere in this codebase — enforced
  by the boundary scan the same as every other table.
- Its only caller is `wallet_sync_legacy_currentcredit()` in `app/wallet.php`, itself called from
  every wallet-mutating function (credit, debit, reserve, commit, release, manual adjustment) —
  always inside the same transaction as the real wallet write, never a separate one.
- `BACKEND_LEGACY_CREDIT_SYNC_ENABLED` (default `1`) can disable the write entirely (logged, not
  silently dropped). The wallet ledger's own correctness never depends on this flag either way.
- Drift between the two is detectable, never auto-corrected: `wallet_drift_report()` /
  `make wallet-audit` compares `ellsms_wallet_accounts.available_balance` against
  `user_.currentcredit` for every account and reports any mismatch.

## 5. Message repositories — `app/Backend/messages.php`

The ONE place `outbound_message`/`inbound_message` are queried. Business filtering (date range,
tenant/organization scope, status, free text) stays in the calling controller exactly as before
Phase 8 — what moved here is the SQL execution itself, so tenant scoping is the CALLER's
responsibility (see §6), not something the repository enforces on its own.

**Outbound**: `backend_outbound_count()`, `backend_outbound_daily_counts()`,
`backend_outbound_summary()`, `backend_outbound_rows()`, `backend_outbound_export_rows()`,
`backend_outbound_sent_count_for_user()` / `_for_users()` (batch), `backend_outbound_scan()`
(analytics.php's row-capped full scan).

**Inbound**: `backend_inbound_count()`, `backend_inbound_today_count()`, `backend_inbound_rows()`,
`backend_inbound_export_rows()`.

Two functions are deliberately SYSTEM-LEVEL, unscoped by tenant, and must never be called from an
ordinary tenant-facing controller:
- `backend_scan_new_inbound_messages()` — the auto-reply worker's cursor scan (every new inbound
  message, matched against every active rule; the resulting reply is authorized separately, at
  dispatch time, through the normal messaging boundary).
- `backend_scan_autoreply_retry_due_inbound()` — the auto-reply worker's lease-reclaim scan.

## 6. Tenant scoping (enforced by callers, proven by repository tests)

- `public/inbox.php` builds its inbound WHERE clause from `allowed_originators($user)` —
  fail-closed: a user with zero allowed originators gets `destination IN ()` collapsed to `1 = 0`,
  never an unscoped read. `can_view_inbound_message()` (`app/authorization.php`) is the equivalent
  single-message check.
- `public/reports.php` builds its outbound WHERE clause from `organization_member_user_ids()` (or
  the single legacy user id, pre-tenant-backfill).
- `tests/Integration/MessageRepositoryTenantIsolationTest.php` proves both against real MySQL: a
  second organization's inbound/outbound rows never appear in the first's scoped read, and a user
  with no allowed originators is scoped to nothing, not everything.

## 7. Messaging provider — `app/Backend/ApiClient.php`

The ONE authenticated HTTP client for the backend platform's REST API — `backend_api_request()`.
Owns base-URL resolution, connect/request timeouts, HMAC request signing, request-id propagation,
JSON parsing, and error classification. Every backend HTTP call in this codebase funnels through
it: `dispatch_message_raw()` (direct send, P2P, smart send, bulk worker, schedule worker,
auto-reply worker, 2FA SMS all converge here) and `backend_create_account()`.

**Justified exception**: `app/Support/HealthCheck::backendApi()` issues its own raw `curl_init()`
call for `public/health-ready.php`'s readiness probe. This is deliberate — it's a bare TCP/TLS
reachability check (`CURLOPT_NOBODY`, ignores the HTTP status entirely), not an authenticated
business call, and needs neither HMAC signing nor JSON parsing. `app/telegram.php` (Telegram bot
API) and `app/zarinpal.php` (ZarinPal payment gateway) also call `curl_init()` directly — those are
unrelated external services, not the backend platform, and out of this boundary's scope entirely.

**Invariant E — no write fallback**: before Phase 8, an unreachable backend API caused ELLSMS to
write a fabricated `send_failed` row directly into `outbound_message` (a backend-owned table) as a
fallback. That fallback is gone. On any transport failure or non-2xx response,
`backend_record_message_attempt_failure()` (`app/Backend/messages.php`) records the attempt in
ELLSMS's OWN `ellsms_message_attempts` table instead — visible locally
(`cron/jobs-status.php`/`make jobs-status`) without ever fabricating backend history. Proven by
`tests/Integration/NoBackendWriteFallbackTest.php` and, end-to-end, by an actual
`php cron/worker.php --once` run against a real disposable database (see
`docs/phase-8-final-report.md` §12).

## 8. HMAC authentication contract

`backend_service_auth_headers(string $method, string $path, string $rawBody, string $requestId)`
— opt-in (returns `[]`, byte-identical to unsigned behavior, unless both `BACKEND_SERVICE_ID` and
`BACKEND_SERVICE_SECRET` are configured). When configured, it emits:

```
X-Ellsms-Service-Id: <service id>
X-Ellsms-Timestamp: <unix time>
X-Ellsms-Request-Id: <per-request id, for log correlation only>
X-Ellsms-Signature: HMAC-SHA256(method + "\n" + path + "\n" + timestamp + "\n" + sha256(body) + "\n" + serviceId, secret)
```

Method, path, timestamp, and body are all bound into the signature — a captured signature cannot
be replayed against a different method or endpoint, and a tampered body invalidates it. The
request id is carried for correlation but is **not** part of the signed content (it identifies the
request for tracing, it is not an anti-replay nonce). All of this is proven directly against the
real function in `tests/Unit/BackendServiceAuthTest.php`.

### Backend verifier status: **PARTIAL**

This repository contains only the **client-side signer**. There is no backend-side verifier here —
the backend platform is a separate codebase this repository does not contain. Concretely, that
means:

- Nothing in this repo enforces a signature-freshness window (stale-timestamp rejection) or
  replay protection — that is the verifying side's responsibility, and it does not exist here to
  test.
- End-to-end authentication (a request the backend actually rejects for a bad/stale/reused
  signature) cannot be proven from this repository alone.

This has been the disclosed status since Phase 2 (`docs/security-review.md` finding 5) and remains
disclosed, not silently implied as fixed, by Phase 8.

## 9. API failure classification

`backend_api_request()` normalizes every outcome to one of `BackendError::{UNAVAILABLE, TIMEOUT,
UNAUTHORIZED, REJECTED, CONFLICT, INVALID_RESPONSE, PERMANENT}`:

| HTTP / transport outcome | Class | Retryable (`BackendError::isRetryable()`) |
|---|---|---|
| connection failure / timeout | `UNAVAILABLE` / `TIMEOUT` | yes |
| 5xx | `UNAVAILABLE` | yes |
| 401 / 403 | `UNAUTHORIZED` | no |
| 409 | `CONFLICT` | no |
| 400 / 404 / 422 | `REJECTED` | no |
| 2xx with an unparseable body | `INVALID_RESPONSE` | no |

`dispatch_message_raw()` (`app/backend.php`) derives its own `retryable` return value from this
classification via `BackendError::isRetryable()`. Phase 8's closure pass found and fixed a real
defect here: the function previously hardcoded `retryable = true` for every non-2xx outcome,
including permanent rejections, so a 422/401/409 would burn through a worker's full
retry/backoff budget (Phase 4's `JOB_MAX_ATTEMPTS`) before finally landing on the same permanent
outcome it should have reached immediately. Fixed and covered by
`tests/Integration/ApiClientFailureModelTest.php` (a real HTTP fixture server, since curl talks to
a real socket and there is no HTTP mocking library in this project's dependencies).

## 10. Readiness

- `public/health.php` — liveness only: PHP + database. Reachable even when the backend API is
  down.
- `public/health-ready.php` — liveness plus `HealthCheck::backendApi()`, a bare reachability probe
  of the configured backend base URL.
- Neither endpoint's response body ever includes the backend URL, the service secret, DB
  credentials, or exception detail — only fixed `"ok"`/`"error"` strings per check, plus
  `app_version()`/`app_env()`. Full exception detail always still goes to `Logger` for internal
  investigation, never to the HTTP response.

## Related documents

- `docs/phase-8-final-report.md` — this phase's migration history and validation evidence.
- `docs/technical-debt.md` — the original phase register (note: its own "Phase 8" entry, TD-017
  through TD-023, is a different, earlier-numbered item — code-duplication cleanup — and is
  unrelated to this backend-boundary work; the naming collision is historical, not a typo here).
- `docs/job-queue-architecture.md` — the claim/lease/retry/backoff model `BackendError::isRetryable()`
  feeds into.
- `docs/wallet-architecture.md` — the wallet ledger `credit_projection.php` mirrors into
  `user_.currentcredit`.
