# ELLSMS Webhooks

Phase 12. Event-driven delivery for the ELLSMS Public API (`docs/public-api.md`) — subscribe an
HTTPS endpoint you control to a small catalog of business events (a message finished sending, a
bulk job completed, a payment was credited), and ELLSMS will POST a signed JSON payload to it,
with automatic retries and a dead-letter state for endpoints that stay broken.

## Event catalog

| Event | Fired when |
|---|---|
| `message.sent` | An API-initiated `POST /messages` call succeeded (fully or partially) |
| `message.failed` | An API-initiated `POST /messages` call failed outright |
| `bulk.completed` | A bulk job finished with at least one message actually sent |
| `bulk.failed` | A bulk job finished with **zero** messages sent (total failure) |
| `payment.credited` | A ZarinPal payment was successfully claimed and credited to a wallet |

This is a deliberately small, stable catalog (STEP 28) — each event is wired to a real, already-
existing domain action, never speculative. `message.sent`/`message.failed` currently cover
API-initiated sends only (`POST /api/v1/messages`); `bulk.completed`/`bulk.failed` and
`payment.credited` cover the corresponding action regardless of whether it originated from the API
or the panel UI.

### Payload shape

Every delivery body looks like:

```json
{
  "event_id": "52bbf726-b067-4443-8a5c-ea4fe11a4f8b",
  "event_type": "message.sent",
  "created_at": "2026-08-05T11:26:46+00:00",
  "organization_id": 42,
  "api_version": "v1",
  "data": { "...": "event-specific fields" }
}
```

`event_id` is a stable UUID — **retries of the same delivery reuse the identical `event_id`**
(STEP 32); it never changes across attempts. Use it for de-duplication on your side even though
ELLSMS's own retry logic never intentionally double-delivers a *new* event.

## Managing endpoints

Via the panel: **Panel → Integration → Webhooks** (`/webhooks.php`), gated by the
`webhooks.manage` organization permission (owner/admin by default).

Via the API (`webhooks:read`/`webhooks:write` scopes):

- `GET /api/v1/webhooks` — list
- `POST /api/v1/webhooks` — `{"url": "https://...", "description": "...", "event_types": ["message.sent"]}`
  → `{"data": {"id": "5", "secret": "..."}}` (secret shown once)
- `GET /api/v1/webhooks/{id}` / `PATCH /api/v1/webhooks/{id}` / `DELETE /api/v1/webhooks/{id}`
- `POST /api/v1/webhooks/{id}/rotate-secret` → new secret, shown once
- `POST /api/v1/webhooks/{id}/test` → queues one synthetic `message.sent` event to that endpoint
  only (never a caller-supplied URL/payload — always the endpoint's own already-validated URL)

## Endpoint URL requirements (SSRF policy)

Every URL is validated **at creation and again immediately before every delivery attempt**
(re-validated, since DNS can legitimately change between the two — STEP 29):

- `https://` required (`WEBHOOK_REQUIRE_HTTPS=1`, the production default — disabling it is a
  `config-check` FAIL in production).
- No embedded credentials (`https://user:pass@host/...` is rejected).
- No `localhost`/`*.localhost`/`*.local`.
- Every resolved IP (A and AAAA) is checked against a blocklist covering loopback, RFC1918 private
  ranges, link-local (including the cloud-metadata address `169.254.169.254`), CGNAT, documentation/
  reserved ranges, and multicast — for both IPv4 and IPv6, including IPv4-mapped IPv6.
- No redirects are ever followed — a 3xx response is just a response body, never a reason to
  connect somewhere new.
- A host that fails to resolve at all is rejected (not treated as "safe by default").

An endpoint that fails validation at creation time is never saved. One that somehow later resolves
into a blocked range (DNS rebinding) fails at delivery time with `error_code =
ssrf_blocked_<reason>`, classified as a **permanent** failure (not retried).

## Secret storage

The signing secret is generated with a CSPRNG at creation/rotation time, shown to you **exactly
once**, and stored **encrypted** (AES-256-GCM, envelope-encrypted under `WEBHOOK_MASTER_KEY`) — not
hashed, because delivery needs to recompute a live HMAC signature, unlike an API key's secret which
only ever needs to be verified. `WEBHOOK_MASTER_KEY` must be 32 random bytes, base64-encoded
(`openssl rand -base64 32`), set once per deployment, and never reused for anything else (never the
same value as `BACKEND_SERVICE_SECRET` or any API key material).

If you lose your copy of the secret, rotate — there is no recovery path, by design (same policy as
API keys).

## Signature verification

Every delivery carries three headers:

```
X-ELLSMS-Event-ID: 52bbf726-b067-4443-8a5c-ea4fe11a4f8b
X-ELLSMS-Timestamp: 1754392345
X-ELLSMS-Signature: 3f29a1...  (hex HMAC-SHA256, 64 chars)
```

Canonical signed string: **`"{timestamp}.{raw request body}"`** (a literal `.` joining them —
the body is the exact bytes received, before any JSON re-serialization on your end).

```
signature = hex(HMAC_SHA256(secret, timestamp + "." + raw_body))
```

### Reference verifier — PHP

```php
function verify_ellsms_webhook(string $secret, string $timestamp, string $rawBody, string $signature, int $toleranceSeconds = 300): bool {
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > $toleranceSeconds) {
        return false; // reject stale/replayed timestamps
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $signature); // constant-time comparison
}
```
(This is the exact function ELLSMS itself uses — `webhook_signature_verify()` in `app/Webhooks.php`
— kept in the app so this doc and the implementation can never drift apart.)

### Reference verifier — Node.js

```js
const crypto = require('crypto');

function verifyEllsmsWebhook(secret, timestamp, rawBody, signature, toleranceSeconds = 300) {
  if (!/^\d+$/.test(timestamp) || Math.abs(Date.now() / 1000 - Number(timestamp)) > toleranceSeconds) {
    return false;
  }
  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
  const a = Buffer.from(expected, 'hex');
  const b = Buffer.from(signature, 'hex');
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}
```

**Always use the raw request body** (before your framework parses it as JSON) for signature
verification — re-serializing parsed JSON can change whitespace/key order and produce a signature
mismatch even for a genuine delivery.

## Replay protection

- The timestamp is checked against a **tolerance window** (documented default: 300 seconds — apply
  your own if you need something tighter). A captured request replayed after this window fails
  verification.
- `X-ELLSMS-Event-ID` is stable across retries of the *same* logical delivery — track ids you've
  already processed (even within the tolerance window) if your endpoint isn't naturally idempotent,
  since a legitimate retry of a still-pending delivery carries the same id with a fresh
  timestamp/signature.

## Retry policy

| Outcome | Classification | Behavior |
|---|---|---|
| 2xx | success | Delivered, endpoint's failure streak reset |
| 408, 425, 429, 500, 502, 503, 504 | retryable | Rescheduled with exponential backoff |
| 400, 401, 403, 404, 410, 422 | permanent | Marked failed immediately, never retried |
| connection failure / timeout | retryable | Rescheduled |
| any other 5xx | retryable (default) | Rescheduled |
| any other 4xx | permanent (default) | Marked failed immediately |

Backoff uses the same bounded-exponential schedule as this codebase's SMS job queue
(`JOB_RETRY_BASE_SECONDS`/`JOB_RETRY_MAX_SECONDS`; default 30s, 1m, 2m, 4m, 8m, ...). Maximum
attempts: `WEBHOOK_MAX_ATTEMPTS` (default 8). Once attempts are exhausted, a still-retryable
delivery moves to `dead_letter`; a permanent failure moves to `failed` immediately, on the very
first attempt.

Each attempt gets a fresh timestamp/signature — only the **event id** stays constant across
retries, not the signature.

### Endpoint auto-disable

An endpoint accumulating `WEBHOOK_AUTO_DISABLE_THRESHOLD` (20) consecutive **terminal** failures
(permanent failures, or retries-exhausted deliveries — never a single transient blip) is
automatically disabled (`enabled=0`, `disabled_reason='auto_disabled_excessive_failures'`). Enabling
it again through the panel/API resets the failure counter.

## Delivery observability

- Panel: endpoint status, consecutive-failure count, last success/failure shown at `/webhooks.php`.
- `make webhooks-status` — queue depth by status, which endpoints are currently disabled and why.
  Never exposes message content.
- `make webhook-retry-failed ID=<delivery_id>` — manually requeue one `failed`/`dead_letter`
  delivery. Preserves the original `event_id` — never creates a new logical event.
- `make webhook-prune-dry-run` / `make webhook-prune` — retention cleanup (see below).

## Response handling

At most `WEBHOOK_MAX_RESPONSE_BYTES` (default 4096, floor 256) of your response body is ever
captured, and only a bounded excerpt (≤1024 chars) of that is stored — never the full body, and
never anything beyond what's needed for troubleshooting. Your response body content is otherwise
ignored; only the HTTP status code drives retry classification.

## Retention

- Delivered/permanently-failed delivery rows: `WEBHOOK_DELIVERY_RETENTION_DAYS` (default 30).
- `dead_letter` rows are **never** auto-pruned by default (`--include-dead-letter` opts in
  explicitly) — they're kept until an operator has genuinely triaged them.
- Event rows with no remaining delivery reference: `WEBHOOK_EVENT_RETENTION_DAYS` (default 90).

## Delivery worker

`cron/webhook-worker.php` — a dedicated process/container, deliberately separate from the SMS
worker (`cron/worker.php`), so a slow/unreachable customer endpoint can only ever delay other
webhook deliveries, never a scheduled send. Uses the same atomic claim/lease pattern as the Phase 4
job queue (bulk items) — safe to run multiple instances concurrently, and recovers automatically
from a crashed worker's abandoned lease.

## Plan enforcement (Phase 13)

Where billing is enabled, webhooks are a plan-gated capability:

- The organization's plan must include the `webhooks` entitlement, or every `/api/v1/webhooks*` route
  returns `403 feature_not_available` and the panel page is inaccessible.
- The number of endpoints is capped by the plan (`webhook_endpoints`); creating one past the cap
  returns `409 resource_limit_reached` from the API, or a clear message in the panel. The check is
  race-safe — two concurrent creates for the last slot cannot both succeed.
- A non-serviceable subscription (suspended/cancelled/expired) returns `402 subscription_inactive`.

**Existing endpoints are never deleted or disabled by a downgrade.** An organization that drops to a
plan with a lower endpoint cap keeps every endpoint it already has, still delivering; it simply cannot
create more until it upgrades or removes some itself. `make usage-status ORG=<id>` reports the
over-limit state explicitly. See `docs/plans-and-entitlements.md`.

## Configuration reference

| Variable | Default | Meaning |
|---|---|---|
| `WEBHOOK_MASTER_KEY` | *(required once API_ENABLED=1)* | 32-byte base64 secret-encryption key |
| `WEBHOOK_REQUIRE_HTTPS` | `1` | Reject non-HTTPS endpoint URLs |
| `WEBHOOK_TIMEOUT_SECONDS` | `10` | Per-attempt HTTP timeout |
| `WEBHOOK_MAX_ATTEMPTS` | `8` | Attempts before dead-lettering a retryable failure |
| `WEBHOOK_MAX_RESPONSE_BYTES` | `4096` | Cap on captured response bytes (floor 256) |
| `WEBHOOK_DELIVERY_RETENTION_DAYS` | `30` | Terminal delivery row retention |
| `WEBHOOK_EVENT_RETENTION_DAYS` | `90` | Orphaned event row retention |
