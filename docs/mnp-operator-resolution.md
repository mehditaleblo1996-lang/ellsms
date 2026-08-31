# Operator resolution strategy seam (issue #9, backlog)

## Decision, restated

MNP (mobile number portability) lookup is **not required now**. This document and the code it
describes exist to satisfy the issue's actual ask: keep a clean extension point so a real MNP lookup
can be added *later* without rewriting provider routing (issue #8) or pricing (`docs/sms-pricing.md`).

## Audit finding

There is exactly **one** real operator-detection mechanism in this codebase:
`sms_resolve_operator()` (`app/Sms/Pricing.php`) — prefix matching against the admin-configured
`ellsms_sms_operator_prefixes` catalog. `detect_operator()` (`app/bootstrap.php`, used by
`analytics.php`) already delegates to it, falling back to a static hardcoded prefix map only for an
install whose pricing tables aren't migrated yet. There was no second, duplicate detector to
reconcile.

## What changed (no behavior change)

`sms_resolve_operator()`'s signature and return shape are **untouched** — every existing caller
(pricing, issue #8's routing, analytics) keeps working identically, proven by the full existing test
suite passing unchanged. Internally, it now dispatches through a named strategy
(`app/Sms/OperatorResolution.php`):

```
sms_resolve_operator()  ──delegates to──>  operator_resolve()  ──dispatches by strategy──>  operator_resolve_via_prefix()   [today, always]
                                                                                          `─>  operator_resolve_via_mnp()      [stub, unreachable]
```

- `operator_resolution_strategy()` — reads `OPERATOR_RESOLUTION_STRATEGY` (`.env`), defaults to
  `prefix`. Setting it to `mnp` before a real implementation exists does **not** silently pretend to
  support it: it logs `operator_resolution.mnp_not_implemented` once per process and falls back to
  `prefix` — the issue's own "prefix detection remains the fallback" criterion, enforced in code, not
  just documentation.
- `operator_resolve_via_mnp()` is a stub that throws — it exists only to prove the seam is real and
  is never reachable through the real dispatch path today (`operator_resolution_strategy()` never
  returns `'mnp'`).

## Cache/failure contract, defined before activation

Per the issue's explicit acceptance criterion ("cache/failure behavior is defined before
activation"):

- **Cache TTL**: `OPERATOR_RESOLUTION_CACHE_TTL_SECONDS` (default 300s) — currently inert (the
  prefix strategy is a local table lookup, already covered by `sms_pricing_cached()`'s own
  request-lifetime cache), but a future network-calling MNP strategy is expected to honor this
  value rather than inventing its own caching policy.
- **Failure fallback**: `operator_resolve()` catches any `Throwable` from a non-prefix strategy and
  falls back to `operator_resolve_via_prefix()`, emitting `operator_resolution.fallback` (tagged
  `from`/`to`) and an `operator_resolution.mnp_failed_falling_back_to_prefix` error log. A future MNP
  strategy's timeout/network-error handling has an established pattern to follow, so a real network
  outage degrades to "prefix-accurate" rather than becoming a send failure.

## What a future MNP implementation needs to do

1. Replace `operator_resolve_via_mnp()`'s body with the real lookup, returning the same shape
   (`operator_id`/`operator_code`/`operator_name`/`operator_source`/`matched_prefix`), with
   `operator_source` presumably `'mnp'` instead of `'prefix'`.
2. Remove the "not implemented" guard in `operator_resolution_strategy()` so `OPERATOR_RESOLUTION_STRATEGY=mnp`
   is honored.
3. Nothing in pricing, routing, or analytics needs to change — they all call `sms_resolve_operator()`,
   which is untouched.

## Tests

`tests/Unit/OperatorResolutionTest.php` proves: the default strategy is `prefix`; requesting `mnp`
(or any unrecognized value) before a real implementation exists always falls back to `prefix`; the
stub is provably unreachable through the real dispatch path yet still throws when called directly
(proving it is genuinely a stub, not silently working); the cache TTL default and override both
resolve correctly. Backward compatibility is proven by the full existing pricing/routing/analytics
test suite passing unchanged (`tests/Integration/SmsPricingTest.php`,
`tests/Unit/OperatorDetectionTest.php`, `GatewayDispatchTest.php`, `GatewayParityTest.php`).
