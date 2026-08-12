# SMS gateway status connector — batch variable closure

**Date:** 2026-08-11
**Scope:** status request variables and batched response correlation. No new features, no scheduler
changes, no transport redesign.

Follows [`sms-gateway-connectors-closure-report.md`](sms-gateway-connectors-closure-report.md); the
design is in [`sms-gateway-connectors.md`](sms-gateway-connectors.md).

---

## Root cause

The status connector was built for a one-message-at-a-time API. Everything it could express assumed a
single `provider_message_id` per request, so a real provider taking many ids per lookup —

```
POST /rest/messageState
{"username":"…","password":"…","referenceids":[7310136179845801812, 776846774851635393]}
```

— could not be configured at all. Three separate gaps, each of which alone blocks it:

1. **No plural variable.** Nothing in the status catalog could carry more than one id.
2. **No numeric-array data type.** `string_list` emits `["7310…"]`; a provider expecting numbers
   rejects quoted ids, and the obvious fix — casting — is the one thing that must never happen here.
3. **No per-item correlation.** The response mapping read a single top-level status path, so a
   multi-item answer had nowhere to go.

Underneath all three is the reason this needed care rather than a quick edit: **the ids are 19
digits.** PHP's float carries 53 bits of mantissa, so `7310136179845801812` becomes
`7310136179845801800` the instant it touches one. A status lookup for an id that is off by three at
the end returns nothing — which is indistinguishable, from the outside, from "the provider has no
record of this message". The corruption would have been invisible in exactly the way that matters.

---

## Files changed

| File | Change |
|---|---|
| `app/Sms/GatewayConnector.php` | `provider_message_ids` in the status catalog; `GATEWAY_PER_MESSAGE_STATUS_VARIABLES`; `GatewayJsonNumber`; `gateway_decimal_token()`; `gateway_integer_list()`; `gateway_json_encode_body()`; `integer_list` data type |
| `app/Sms/GatewayTransport.php` | safe JSON body encoding; `provider_message_ids` in the status context; `JSON_BIGINT_AS_STRING` response decoding; preview rendering of numeric lists |
| `app/Sms/GatewayCache.php` | per-item response mapping; batch-capability detection; additive-only status success rule |
| `app/Sms/GatewayStatus.php` | request grouping, batched polling, id-based correlation, anomaly handling |
| `public/sms-gateways.php` | status variable catalog in the UI, `integer_list`, status success rule and item-mapping fields |
| `db/migrations/2026_08_15_gateway_status_batch_parameters.sql` | `integer_list` enum value; `success_rule_json` on the status connector |
| `tests/Integration/GatewayStatusBatchTest.php`, `tests/fixtures/fake_message_state_server.php` | new |

---

## Results

**Status variable catalog** — `provider_message_id`, `provider_message_ids`, `request_id`, `sender`,
`recipient`, `operator_code`, `route_code`, `gateway_code`, `timestamp`. Still a **separate** catalog
from the send one: merging them would let a send template read `provider_message_id` (which does not
exist yet when a message is sent) and a status template read `message`. The admin UI renders its
dropdown from these same PHP constants, so the two cannot drift; backend validation stays
authoritative and re-checks every save.

**`provider_message_ids`** — holds every id in the current compatible batch. A single-message poll
populates it too, so one id yields a one-element array rather than a bare scalar.

**`integer_list`** — validated canonical decimal tokens emitted as JSON numbers. Rejects negatives,
decimals, exponents, whitespace, leading zeros, and anything wider than a signed 64-bit integer (the
far side would mangle it anyway). A malformed item is dropped with a warning rather than coerced —
turning `12.5` into `12` would ask the provider about a different message.

**Long-ID precision** — ids stay canonical decimal **strings** from the database to the wire. They are
never cast, never arithmetic operands, never floats. `gateway_json_encode_body()` encodes with unique
random placeholders and substitutes the raw tokens, which is safe precisely because every token has
already been validated as digits-only. Responses decode with `JSON_BIGINT_AS_STRING`, so a big `id`
arrives as a string in exactly the form the internal id already has. A test asserts the corrupted form
`7310136179845801800` never appears on the wire.

**Exact generated request JSON** (asserted byte-for-byte, not decoded — a decode-and-compare would
hide the very quoting difference under test):

```json
{"username":"gateway-user","password":"gateway-pass","referenceids":[7310136179845801812,776846774851635393,3717114266477167711]}
```

Secrets resolve into the request and are masked in the preview.

**Single ID** — `"referenceids":[7310136179845801812]`. Asserted *not* to be a bare number and *not*
to be a quoted string.

**Batch grouping** — never across gateways, config versions, or route/operator override sets. A
connector reading any per-message variable is never batched at all. Capped at
`SMS_GATEWAY_STATUS_REQUEST_MAX` (default 50) per request.

**Response correlation** — by id, never by position:

| situation | result |
|---|---|
| shuffled response | each id takes its own state; order irrelevant |
| missing item | row keeps its non-terminal state, attempt counted (so retries stay bounded), never given a neighbour's status |
| duplicate id | dropped entirely and treated as missing — picking first or last would look identical to a correct answer |
| unknown id | counted as a diagnostic and ignored; it cannot reach any row, because the write loop iterates over the *requested* rows |

**Provider-level failure** — `errorModel.errorCode != 0` fails the whole poll and no `states` are
read. The status success rule is **strictly additive**: the base 2xx-plus-parseable-JSON rule is
applied in code and is not configurable, so a configuration can narrow success and never widen it.
That is what made it safe to expose at all — the original reason for withholding a status success rule
was that a knob able to relax it would let a failed poll be read as a delivery report.

**Status mapping** — `{"1":"sent","2":"delivered","3":"failed"}`, unmapped → `unknown`, never
`delivered`. Note that `unknown` fills an *empty* delivery state but does not overwrite a known one:
"the provider said something we do not understand" is strictly less information than "accepted for
delivery". That is the pre-existing monotonicity rule, unchanged.

### Performance — 1000 status rows on one gateway

| counter | value |
|---|---|
| gateway config loads | **1** |
| connector compiles | **1** |
| secret decrypts | **1** |
| cache reloads | 0 |
| per-message config DB lookups | **0** |
| provider requests | **1** (at a 1000-id cap) |

---

## Verification

| | |
|---|---|
| `make lint` | **264 files, clean** |
| Unit suite | **334 tests, 778 assertions — pass** |
| Gateway suite | **99 tests, 2359 assertions — pass** |
| Integration suite | **565 tests, 4747 assertions — pass**, except the pre-existing flake below |
| New in this closure | `GatewayStatusBatchTest` (17 integration) + `GatewayNumericListTest` (34 unit) |

**The one residual failure is the pre-existing `SubscriptionEffectiveSlotConcurrencyTest` deadlock
flake** (`SQLSTATE[40001]`, roughly one run in five, reproducible in isolation). It comes from the
TD-070 subscription work, touches no gateway code, and is already in the debt register with its
prescribed fix (a bounded deadlock retry in `subscription_transition()`). Left alone here because
subscription code is outside this closure's declared scope.

---

## Breaking changes

**None.**

- `integer_list` and `success_rule_json` are additive; no existing parameter row changes type or
  meaning, and an install with no status connector is untouched.
- A status connector with no `items_path` is treated as single-message and behaves exactly as before —
  every pre-existing status test passes unmodified.
- A connector that does not use `provider_message_ids` is never batched, so its request shape is
  unchanged.
- `JSON_BIGINT_AS_STRING` only affects integers wider than 2^53, which previously decoded to floats
  and were already wrong.
- Send-path behaviour, legacy byte parity, and `SMS_GATEWAY_TRANSPORT=0` rollback are all untouched.

One internal signature changed: `gateway_status_delivered_at()` was replaced by
`gateway_status_delivered_at_value()`, which takes a correlated item rather than a whole response. It
had no callers outside the polling engine.
