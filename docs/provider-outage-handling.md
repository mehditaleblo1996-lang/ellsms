# Provider outage handling (issue #10)

**Superseded by `docs/provider-health-model.md` (issue #16)**: the binary `healthy`/`outage` model
described below was upgraded in place into the full `UP`/`DEGRADED`/`DOWN`/`UNKNOWN` model with
active checks. This file is kept as the historical record of issue #10's own audit/decisions (which
still hold — messages stay queued, no automatic substitution, manual-only switching); see the newer
doc for the current state model and thresholds.

## Agreed behavior

When the selected provider is unavailable/degraded: messages stay queued, the admin is notified,
and switching providers is manual only — the system must never silently reroute.

## Audit finding

Three of the four behaviors were already true by construction, unrelated to any new code:

- **Messages remain queued** — the existing retry/backoff model (`docs/job-queue-architecture.md`)
  already keeps a failed send `pending`/`processing` with backoff until it succeeds or exhausts
  `JOB_MAX_ATTEMPTS`; nothing about provider health changes this.
- **No automatic provider substitution** — route/provider selection (`sms_pricing_route_for_sender()`,
  issue #8) has never compared price or provider health, and still doesn't; see
  `tests/Integration/ProviderHealthTest.php::testOutageNeverChangesWhichRouteOrProviderASendResolvesTo`.
- **Switching is manual only** — the admin routing UI (`public/sms-pricing.php`,
  `public/sms-gateways.php`) is the only way a route/gateway assignment ever changes; nothing added
  here writes to those tables.

What was actually missing: **admin visibility into provider health, and an alert.** No health state
was persisted anywhere, and no outage ever produced a notification.

## What was added — a minimal, real seam

`app/Sms/ProviderHealth.php` — a persisted per-provider consecutive-failure counter
(`ellsms_provider_health_state`), tracking exactly two provider identities:

- `legacy_backend` — the single legacy REST API path (`dispatch_message_raw()`)
- `gateway:<id>` — one row per configured SMS gateway (issue #8's routing target)

**This is deliberately not the full system** two other backlog issues will build:

- **Not issue #16's health model** (active + passive checks, `UP`/`DEGRADED`/`DOWN`/`UNKNOWN`) — this
  only observes real dispatch attempts (passive), with two states (`healthy`/`outage`), no active
  probing.
- **Not issue #15's alerting system** (Telegram + Email, severity, acknowledgement, configurable
  escalation) — this fires a plain structured log line (`provider_health.outage_detected` /
  `.recovered`, always) plus a Telegram message if `telegram_configured()` (`app/telegram.php`, the
  channel this codebase already had wired for admin-relevant notifications) — no severity levels, no
  acknowledgement, no escalation policy.

Both are real, working, minimal implementations that #15/#16 can build on rather than replace.

## Behavior

- **Failure** (`provider_health_record_failure()`): only called for a *reachability* failure — the
  legacy path when the API couldn't be reached at all (not when it responded and rejected the
  message), the gateway path when every group's HTTP execute failed at the network/5xx-classified
  level (not when the gateway responded and rejected destinations). Increments a per-provider
  counter; at `PROVIDER_HEALTH_OUTAGE_THRESHOLD` (default 5) consecutive failures, status becomes
  `outage` and an alert fires — but only once per `PROVIDER_HEALTH_ALERT_COOLDOWN_SECONDS` (default
  900) for the *same ongoing* outage, so a sustained outage (every message failing) sends one alert,
  not one per failed message.
- **Success** (`provider_health_record_success()`): resets the counter to 0 and status to `healthy`;
  if the provider was in `outage`, fires exactly one recovery alert.

## Admin visibility

- `cron/jobs-status.php` — new "Provider health" section (and `provider_health` key in `--json`
  output): every tracked provider's status, consecutive-failure count, and last error.
- `public/sms-gateways.php` — new "سلامت ارسال" (send health) column per gateway.
- Both read the same `provider_health_snapshot()` — one source of truth, no separate dashboard logic
  to drift from the tracking logic.

## Tests

`tests/Integration/ProviderHealthTest.php` — below-threshold stays healthy and silent; crossing the
threshold fires exactly one alert; further failures within the cooldown don't repeat it; a success
while in outage fires recovery and resets the counter; a success while already healthy never fires a
spurious recovery; **the hard acceptance criterion** that health state never changes route/provider
resolution; the snapshot reflects every tracked provider. Full unit suite (477 tests) and the
directly affected integration suites (gateway dispatch/parity, API client failure classification)
all re-verified passing, 0 new failures.

"Resuming/switching provider does not lose or silently duplicate messages" is the queue's own
crash-recovery guarantee, already proven in `tests/Integration/BulkWorkerCrashRecoveryTest.php`
(issue #6) — not duplicated here, since a provider outage and a worker crash exercise the identical
claim/retry machinery from the queue's point of view.
