# ELLSMS Public API (v1)

Phase 12. A small, versioned, tenant-scoped REST API for customer integrations — separate from the
web session used by the panel UI and separate from the internal backend-platform HMAC scheme (Phase
2/8). See `docs/webhooks.md` for the companion webhook delivery system and
`docs/phase-12-final-report.md` for the full acceptance-criteria record.

**Status by default: disabled.** Every `/api/v1/*` route returns `503 service_unavailable` unless
the operator sets `API_ENABLED=1`. This is a deliberate safe default for existing installs — see
"Activation" below.

## Base path and versioning

```
https://<your-domain>/api/v1/
```

- Every route lives under `/api/v1`. There is no unversioned path.
- **Compatibility policy:** additive changes (new fields, new endpoints, new optional request
  parameters) may ship within `v1` without notice. Removing or renaming a field, or changing a
  field's meaning, requires a new version (`/api/v2`) — never a silent breaking change to `v1`.
  Clients must ignore unknown response fields.
- Deprecations (when they happen) will be documented here with a removal timeline before anything
  is actually removed.

## Authentication

Every request must carry:

```
Authorization: Bearer ellsms_<environment>_<prefix>_<secret>
```

- The key is **never** accepted from a query string, cookie, or any other location — only this
  header.
- Authentication is completely independent of the web session cookie used by the panel UI
  (Invariant C), and independent of the internal backend-platform HMAC scheme used for
  service-to-service calls between ELLSMS and its connected SMS gateway (Invariant L) — no key
  material or verification logic is shared between the three.
- A missing, malformed, wrong, revoked, or expired key all return the same generic
  `401 unauthenticated` — the response never reveals which specific condition failed.
- Every API key belongs to exactly one organization (Invariant A) and grants nothing outside it
  (Invariant B). A key acts, for wallet/messaging purposes, on behalf of the user who created it
  (see "Key format and lifecycle" below) — this codebase's wallet model is strictly per-user (Phase
  3), not per-organization, and this phase does not change that.

### Key format and lifecycle

Format: `ellsms_{live|test}_{12-hex-char prefix}_{secret}`. The prefix is a public lookup id (not
secret, indexed); the secret is 256 bits of random data. Only a SHA-256 hash of the secret is ever
stored — the raw key is shown exactly once, at creation or rotation, and cannot be recovered
afterward. (This is a deliberately different design from this codebase's user-password hashing,
which uses Argon2id — see `app/ApiKeys.php`'s docblock for why a keyed/fast hash is the right choice
for a high-entropy API secret verified on every request, versus a slow hash for a low-entropy
human password verified at login.)

Manage keys at **Panel → Integration → API Keys** (`/api-keys.php`), gated by the
`api_keys.manage` organization permission (owner/admin by default; a member cannot create, rotate,
or revoke a key). Available actions:

- **Create** — choose a name, environment (`live`/`test`), and scopes. The raw secret is shown once.
- **Revoke** — takes effect immediately; the very next request with that key gets `401`.
- **Rotate** — revokes the current secret and issues a brand-new one under the same name/scopes, no
  overlap window. Update every integration using the old key before rotating.
- **Expire** — an optional `expires_at` may be set at creation; an expired key behaves exactly like
  a revoked one (generic `401`, nothing more specific).

## Scopes

A key's scopes control **what it may call**; the organization permission above controls **who may
create a key at all** — these are two separate, deliberately independent layers (see
`app/Support/ApiScopes.php`).

| Scope | Grants |
|---|---|
| `messages:send` | `POST /messages` |
| `messages:read` | `GET /messages/{id}` |
| `bulk:write` | `POST /bulk-jobs` |
| `bulk:read` | `GET /bulk-jobs/{id}` |
| `contacts:read` | `GET /contacts`, `GET /contacts/{id}` |
| `contacts:write` | `POST /contacts`, `PATCH /contacts/{id}`, `DELETE /contacts/{id}` |
| `balance:read` | `GET /balance` |
| `webhooks:read` | `GET /webhooks`, `GET /webhooks/{id}` |
| `webhooks:write` | `POST /webhooks`, `PATCH`, `DELETE`, rotate-secret, test |

An unrecognized scope string is rejected outright at key-creation time — never silently dropped, and
never accepted as a partial match. A request against an endpoint the key wasn't granted returns
`403 forbidden`.

## Rate limiting

Enforced per API key (sustained + a short burst window) and per organization (a higher aggregate
ceiling, catching one org spraying requests across many keys), plus a generous per-source-IP
ceiling — see `app/Api/RateLimit.php`. Configurable via `API_RATE_LIMIT_PER_MINUTE` (default 60) and
`API_RATE_LIMIT_BURST` (default 15). A rate-limited request gets:

```
429 Too Many Requests
Retry-After: <seconds>
```

A revoked/invalid key never consumes an active key's quota — rate limiting for an unauthenticated
request only ever uses the IP dimension. `X-Forwarded-For` is honored only from a configured
trusted proxy (`TRUSTED_PROXY_IPS`, same mechanism the rest of the app already uses) — spraying fake
forwarded-for values from an untrusted source has no effect.

## Idempotency

Required (not merely supported) for `POST /messages` and `POST /bulk-jobs` — both can have real
financial/messaging side effects if executed twice.

```
Idempotency-Key: <your-own-unique-string, 1-200 chars, [A-Za-z0-9_.:-]>
```

- The same key + the same request body → the exact original response is replayed byte-for-byte, no
  second execution.
- The same key + a **different** request body → `409 conflict`.
- A second, genuinely concurrent request with the same key while the first is still running → the
  server waits briefly for the first to finish and replays its result; if it doesn't finish quickly
  enough, `409 conflict` asking the caller to retry.
- Missing the header on an endpoint that requires it → `400 invalid_request`.
- Idempotency records are retained for `API_IDEMPOTENCY_TTL_HOURS` (default 48) — see
  `cron/idempotency-prune.php`. Design your retry window accordingly.

This is enforced by a real database-level lock (a `UNIQUE` constraint), not an in-process cache — it
holds correctly across multiple app server processes/containers.

## Errors

Every error response has the same shape:

```json
{
  "error": {
    "code": "forbidden",
    "message": "This API key does not have the required scope for this action.",
    "request_id": "a1b2c3d4e5f6a1b2"
  }
}
```

Validation errors additionally include `fields`:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Request validation failed.",
    "fields": { "mobile": ["invalid_format"] },
    "request_id": "a1b2c3d4e5f6a1b2"
  }
}
```

| HTTP | code |
|---|---|
| 400 | `invalid_request` |
| 401 | `unauthenticated` |
| 402 | `subscription_inactive` (Phase 13) |
| 403 | `forbidden`, `feature_not_available` (Phase 13) |
| 404 | `not_found` |
| 409 | `conflict`, `resource_limit_reached` (Phase 13) |
| 413 | `payload_too_large` |
| 415 | (unsupported `Content-Type` on a write endpoint) |
| 422 | `validation_failed` |
| 429 | `rate_limited`, `quota_exceeded` (Phase 13) |
| 500 | `internal_error` |
| 503 | `service_unavailable` |

An error message is always a safe, generic, non-leaking string — never a raw exception message, SQL
fragment, file path, or stack trace. `request_id` is also echoed as the `X-Request-Id` response
header; quote it when contacting support.

## Request correlation

Every response carries `X-Request-Id`. You may optionally send your own `X-Request-ID` request
header (bounded length, `[A-Za-z0-9_.-]` only) for your own log correlation — it does not replace
the server-generated id shown in error responses.

## Pagination

Collection endpoints (`GET /contacts`) use cursor pagination:

```
GET /api/v1/contacts?limit=50&after=1042
```

- `limit` — default 50, hard maximum 200.
- Response includes `"meta": {"next_cursor": "1092", "limit": 50}` — pass `next_cursor` as the next
  page's `after`. `next_cursor` is `null` on the last page.
- Every list query is scoped to the caller's own organization before pagination is ever applied —
  there is no way to page into another organization's rows.

## Request limits

- `Content-Type: application/json` is required for every JSON write endpoint.
- Body size is capped at `API_MAX_BODY_BYTES` (default 256KB), checked before parsing.
- `POST /bulk-jobs` items are capped at `API_MAX_BULK_ITEMS` (default 5000).
- `POST /messages` destinations are capped at 100 per call — for larger blasts use
  `POST /bulk-jobs`, which is queued rather than synchronous.

## Endpoints

### `GET /me`
Returns the authenticated key's own metadata (scopes, environment, organization id, and the acting
user it sends/spends on behalf of).

### `GET /organization`
Returns the caller's own organization (id, name, slug, status). Never any other organization's.

### `POST /messages` — scope `messages:send`, idempotent
```json
{ "originator": "5000", "destinations": ["989121234567"], "content": "Hello" }
```
Synchronous — sends through the same `dispatch_message()` domain service the panel's own Send page
uses (Invariant K), so wallet reservation/commit/release and originator authorization are
identical. Returns a final status, not `202` (the destination count cap above is what keeps this a
one-request-lifetime operation). `originator` may be omitted to use the organization's configured
default. Response:
```json
{ "data": { "id": "482", "status": "sent", "sent_count": 1, "total_count": 1, "message": "..." } }
```
`status` is one of `sent`, `partially_sent`, `failed`. This id is the API's OWN resource id
(`ellsms_api_messages`), independent of the backend platform's own message records.

### `GET /messages/{id}` — scope `messages:read`
Returns the same resource shape as above by id, scoped to the caller's organization.

### `POST /bulk-jobs` — scope `bulk:write`, idempotent
```json
{
  "type": "p2p",
  "title": "October campaign",
  "originator": "5000",
  "items": [{ "mobile": "989121234567", "content": "Hi!" }]
}
```
`type` is one of `p2p`, `smart`, `gradual` — for `gradual`, `throttle_count` and `throttle_minutes`
(integers ≥ 1) are also required. Queued through the exact same `bulk_queue_job()` service and
worker (`cron/worker.php`'s `run_bulk_send_pass()`) the panel UI uses — no separate queue path.
Response: `{"data": {"id": "91", "status": "pending", "total_rows": 1, "message": "..."}}`.

### `GET /bulk-jobs/{id}` — scope `bulk:read`
Status summary: `{"id", "type", "title", "status", "sent_rows", "failed_rows", "total_rows", "created_at"}`.
There is no per-item listing endpoint in this version (STEP 2 allows a reduced v1 surface) — a
status summary is intentionally the entire read surface, so no destination/content data is exposed
in bulk via the API without a deliberate future decision to add it.

### `POST /messages/preview` — scope `messages:send`
### `POST /bulk-jobs/preview` — scope `bulk:write`

Read-only cost estimation using the **same request schema** as the corresponding create endpoint, so
you can preview and then send the identical payload. Returns eligible-recipient counts, encoding and
segment analysis (including a per-recipient distribution for personalized bulk), the estimated
credit cost, wallet balance before/after, and quota impact.

**These endpoints mutate nothing** — no message, no job, no reservation, no quota consumption, and
no Idempotency-Key is taken (a preview is repeatable by definition, so no `Idempotency-Key` header
is needed or honored).

Any `estimated_cost` / `unit_price` / `segments` present in the request body is **ignored entirely** —
every figure is computed server-side. The preview is advisory: the real send recomputes and reserves
atomically, so a balance or quota that disappears in between causes the send to fail safely rather
than the preview to have guaranteed anything.

They reuse `messages:send` / `bulk:write` rather than a new scope, because a preview reveals send
capability, pricing, balance and quota — a key that cannot send must not be able to preview.

**Pricing is server-owned, and stating it is an error.** These endpoints — and the real
`POST /messages` and `POST /bulk-jobs` — REJECT (422 `validation_failed`, not silently ignore) any of
`provider_id`, `provider`, `route_id`, `route`, `operator_id`, `operator`, `unit_price`, `price`,
`price_per_segment`, `cost`, `estimated_cost`, `message_type`. Route selection is not exposed to the
customer API in any form in this phase, and the message type is decided server-side from the send
context (an OTP tariff must not be reachable by simply claiming the type). Rejecting rather than
dropping is deliberate: a client sending these has a wrong mental model of who owns pricing.

The `pricing` object carries a per-operator/provider/route `groups` breakdown. `credits_per_segment`
is populated only when every priced recipient shares one rate; when operators differ it is `null` and
`unit_price_min_millicredits`/`unit_price_max_millicredits` plus `groups` carry the truth, because a
single averaged unit price would be a number no caller is actually charged. `currency` is the unit
the cost is in (`credit`); `rial_currency` labels the display-only Rial conversion.

If any recipient cannot be priced with the current tariff configuration, the preview returns **422**
rather than a partial estimate — pricing fails closed (`docs/sms-pricing.md`).

Full reference, including the response shape and the segmentation rules: `docs/cost-preview.md`;
the pricing model itself: `docs/sms-pricing.md`.

### Contacts — scopes `contacts:read` / `contacts:write`
Standard CRUD over the organization's own contact list (the same table `/contacts.php` uses):
- `GET /contacts?limit=&after=`
- `POST /contacts` — `{"mobile": "...", "name": "...", "group": "..."}`
- `GET /contacts/{id}`
- `PATCH /contacts/{id}` — any subset of `mobile`/`name`/`group`
- `DELETE /contacts/{id}`

### `GET /balance` — scope `balance:read`
```json
{ "data": { "available": 4200, "reserved": 100, "total": 4300, "unit": "credits" } }
```
Reads through `WalletService` (`wallet_balance()`), never `user_.currentcredit` directly.

### Webhooks — scopes `webhooks:read` / `webhooks:write`
Full CRUD plus lifecycle actions — see `docs/webhooks.md` for the complete reference:
- `GET /webhooks`, `POST /webhooks`, `GET /webhooks/{id}`, `PATCH /webhooks/{id}`, `DELETE /webhooks/{id}`
- `POST /webhooks/{id}/rotate-secret`
- `POST /webhooks/{id}/test`

## Plan enforcement (Phase 13)

If this deployment has billing enabled (`BILLING_ENABLED=1`), the organization's subscription plan is
enforced on every request, **in addition to** — never instead of — the API key's own scopes. A key
may legitimately hold `messages:send` while the organization's plan doesn't include API access at
all; both checks must pass.

Checked in this order, before any handler runs:

1. **Subscription must be serviceable.** A suspended, cancelled, or expired subscription returns
   `402 subscription_inactive`.
2. **Plan must include the public API.** Otherwise `403 feature_not_available`. Webhook routes
   additionally require the webhooks entitlement — a plan can include API access without webhooks.
3. **Rate limit is plan-aware.** The effective per-key rate is `min(system limit, plan limit)` — a
   plan can only ever *lower* the operator-configured ceiling, never raise it. A plan change takes
   effect on the very next request; nothing is cached.

Then, per endpoint:

| Condition | Response |
|---|---|
| Message allowance for the period exhausted | `429 quota_exceeded` with `Retry-After` |
| API key / webhook endpoint count at the plan cap | `409 resource_limit_reached` |
| `POST /bulk-jobs` items exceed the plan cap | `422 validation_failed` (the effective cap is `min(API_MAX_BULK_ITEMS, plan)`) |
| Bulk sending not in the plan | `403 feature_not_available` |

`quota_exceeded` deliberately uses **429** so existing client back-off logic for rate limits handles
period-quota exhaustion too, rather than needing a separate code path. Retry after the period resets,
or upgrade the plan.

No error response ever reveals which plan the organization is on, or what the internal limit values
are — only that a limit was reached. See `docs/plans-and-entitlements.md`.

Subscriptions themselves are **not** manageable through this API in v1 — plan changes go through the
web UI (`/billing.php`).

## Explicitly out of scope for this API

Manual wallet adjustment, platform-admin operations, KYC documents, private support tickets,
organization ownership transfer, raw backend tables, arbitrary SQL-like filtering, internal worker
controls, and backup/restore are never exposed here, in any version.

## Auditing

Key creation/revocation/rotation and webhook endpoint changes are recorded in `ellsms_audit_log`
(same table/mechanism every other admin-adjacent action in this codebase already uses). Every API
request is logged (`api.request.completed`) with method, path, status, key prefix (never the raw
key), organization id, and duration — never the request/response body or message content.

## Activation (operator checklist)

1. Set `API_ENABLED=1`.
2. If you plan to use webhooks, generate `WEBHOOK_MASTER_KEY` (`openssl rand -base64 32`) and set
   it — required before any webhook endpoint can be created.
3. Run `make config-check` — it fails closed on a missing/malformed `WEBHOOK_MASTER_KEY` whenever
   `API_ENABLED=1`, and on `WEBHOOK_REQUIRE_HTTPS=0` in production.
4. Deploy, then verify: create a test key at `/api-keys.php`, call `GET /api/v1/me`.
5. See `docs/production-runbook.md` for the full rollout sequence.

## OpenAPI

A machine-readable description matching this document lives at `docs/openapi-v1.yaml`.
