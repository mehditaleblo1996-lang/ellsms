# Full provider health model (issue #16)

## Upgraded in place from issue #10

Issue #10 built a minimal binary `healthy`/`outage` seam: a per-provider consecutive-failure
counter, one rate-limited alert on crossing a threshold, one on recovery. This issue upgrades that
seam **in place** — same table (`ellsms_provider_health_state`), same `provider_key` vocabulary
(`legacy_backend`, `gateway:<id>`) — into the full model, rather than building a second, parallel
health system.

## States

`UNKNOWN` → `UP` → `DEGRADED` → `DOWN`, with hysteresis in both directions. A transition requires
**consecutive** evidence in one direction, never a single data point:

- `UNKNOWN` → `UP` after `PROVIDER_HEALTH_UP_MIN_SUCCESSES` (default 5) consecutive successes.
- `UP`/`UNKNOWN` → `DEGRADED` after `PROVIDER_HEALTH_DEGRADED_THRESHOLD` (default 3) consecutive
  failures/timeouts, **or** when a success streak's exponential-moving-average latency exceeds
  `PROVIDER_HEALTH_DEGRADED_LATENCY_MS` (default 3000ms) — "elevated latency," not just outright
  failure.
- `DEGRADED` → `DOWN` after `PROVIDER_HEALTH_DOWN_THRESHOLD` (default 5) further consecutive
  failures/timeouts.
- `DOWN` → `DEGRADED` after `PROVIDER_HEALTH_RECOVERY_MIN_SUCCESSES` (default 2) consecutive
  successes — recovery *evidence*, never a jump straight back to `UP` on one lucky request.
- `DEGRADED` → `UP` after the full `PROVIDER_HEALTH_UP_MIN_SUCCESSES` streak, only once the latency
  average is back under the degraded threshold too.

All five thresholds are environment-configurable (`app/Sms/ProviderHealth.php`'s
`provider_health_*_threshold()`/`provider_health_*_min_successes()` functions), never hardcoded.
Proven with the alternating-noise test (`ProviderHealthTransitionsTest::testNoFlappingUnderAlternatingSuccessFailureNoise`):
strictly alternating success/failure never accumulates enough *consecutive* evidence to cross any
threshold greater than 1, so it can never flap the state.

**"Do not use a single failed request as an automatic DOWN state"**: with the default thresholds
(and any threshold > 1), one failure moves at most `UP`/`UNKNOWN` → nothing (below threshold) or, at
the extreme, `→ DEGRADED` — never straight to `DOWN`, which additionally requires the provider to
already be `DEGRADED`. An operator who explicitly sets both thresholds to 1 gets one-failure-to-DOWN
as a deliberate configuration choice, not a hardcoded default.

## Inputs

- **Passive** (the primary signal): every real dispatch attempt reports through
  `provider_health_record_success()` / `_failure()` / `_timeout()` (`app/Sms/ProviderHealth.php`),
  wired into both transports — `app/backend.php`'s legacy path and
  `app/Sms/GatewayTransport.php`'s gateway path (`gateway_send_for_dispatch_group()`). A `TIMEOUT`-
  classified error (`BackendError::TIMEOUT`) is tracked as a **timeout**, distinct from a generic
  failure, so an admin can tell "provider is slow/unresponsive" from "provider is actively
  rejecting requests" even though both currently drive the same DEGRADED/DOWN ladder. Zero extra
  requests — this rides on real traffic.
- **Active** (`cron/provider-health-check.php`, `provider_health_active_check_one_pass()`): a
  bounded-timeout TCP connect to each active gateway's own configured send-endpoint host, on a
  configurable interval (`PROVIDER_HEALTH_CHECK_INTERVAL_SECONDS`, default 60s) and timeout
  (`PROVIDER_HEALTH_CHECK_TIMEOUT_SECONDS`, default 3s). Deliberately a TCP-level liveness check,
  never an authenticated business-logic API call: some providers charge per API call or rate-limit
  aggressively, so a periodic synthetic "status" request could itself become a cost/abuse concern,
  and not every gateway even configures a status connector (`GatewayStatusPollTest`'s own
  established finding — "most gateways genuinely have no delivery API"). A TCP check works
  uniformly and costs the provider nothing. **Bounded concurrency**: strictly sequential, one
  gateway at a time — the simplest possible bound, and this script touches no shared resource
  (worker claim tables, connection pools) the real send workers use, so it can never contend with
  or overload them.

Every state row records which kind of check most recently updated it (`last_check_source`:
`passive`/`active`).

## CRITICAL invariant: health never changes routing

Unchanged from issue #10, re-verified explicitly for every one of the four states:
`ProviderHealthTest::testOutageNeverChangesWhichRouteOrProviderASendResolvesTo` drives a provider to
`DOWN` and proves `sms_pricing_route_for_sender()` (issue #8's routing) resolves identically before
and after. Nothing in this file writes to any routing table, and nothing in the routing code reads
this file's state.

## Visibility

- **Admin UI**: `public/sms-gateways.php`'s per-gateway health column, `public/queue-cancellation.php`'s
  per-provider health column in the cancel-by-provider table — both now show all four states via
  the shared `provider_health_status_label()` helper (label + color), not just healthy/outage.
- **Diagnostic**: `cron/jobs-status.php`'s "Provider health" section — status, consecutive
  failures/timeouts, average latency, and check source per provider.
- **Prometheus/Grafana**: `provider_health.transition` (bounded labels: `provider_key`, `to`
  status, `source`) and `provider_health.alert` (bounded labels: `provider_key`, `type`) are
  already emitted as structured metrics (`app/Support/Metrics.php`); issue #14 is what gives these
  a real scrape/dashboard surface.

## Tests

- `tests/Unit/ProviderHealthTransitionsTest.php` — the pure hysteresis state machine
  (`provider_health_next_state()`): every transition, no-single-failure-to-DOWN, no-flapping under
  alternating noise, elevated-latency degrading a success streak, timeout tracked separately, DOWN
  never jumping straight to UP.
- `tests/Integration/ProviderHealthTest.php` — the real persisted path: exactly one alert on
  reaching DOWN (not on merely degrading, not per failed message), cooldown suppression, exactly one
  recovery alert reaching UP, and the routing-independence proof above.
- `tests/Integration/ProviderHealthActiveCheckTest.php` — the active probe: success, fast failure
  (closed port), bounded timeout against an unroutable address, recording through the same state
  machine tagged `active`, and a clean no-op pass with zero configured gateways.
