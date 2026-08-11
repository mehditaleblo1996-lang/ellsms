# SMS gateway connector builder — final report

**Date:** 2026-08-11
**Status:** implemented, tested, documented. The live SMS transport is **unchanged by default**.

---

## What was built

Admin-configurable SMS gateways: an endpoint, its parameters, an authentication scheme, and a mapping
for reading the provider's answer — defined as database configuration rather than code. Plus an
optional delivery-status connector with a polling worker, a versioned in-process config cache, an
encrypted secret vault, and a migration that registers the existing REST integration as a gateway
with byte-identical requests.

The full design is in [`sms-gateway-connectors.md`](sms-gateway-connectors.md). This report covers
what was verified, what was decided, and what is not finished.

---

## The two hard acceptance criteria

### 1. Legacy parity — byte level

`tests/Integration/GatewayParityTest.php` sends the legacy request **and** the migrated gateway's
request through a real socket to a receiver that records exactly what arrived, then compares the
recordings — including JSON key order, the numeric typing of `originator`, the `destinations` array,
and unescaped Persian content.

```
{"sender_user_id":0,"originator":5000435800,"destinations":["989121234567","989351112233"],"content":"سلام test"}
```

Identical on both paths. Comparing built structures instead would only have proven that two functions
in this repo agree with each other.

The HMAC timestamp and request id cannot be identical between two separate calls, so they are
excluded from the byte comparison and verified separately: each signature is re-derived from what
that request actually carried and must match. That is a stronger check than equality.

An operator can run the same comparison: `make sms-gateway-simulate TO=… COMPARE=1`, which prints
both requests, sends neither, and exits non-zero if they differ.

### 2. Performance budget

1000 sends through the real hot path produce, by counter assertion:

| counter | value |
|---|---|
| `compile` | **1** |
| `config_load` | **1** |
| `secret_decrypt` | **1** |
| `cache_hit` | 999 |
| `reload` | 0 |

Counters rather than wall-clock: a timing assertion would be flaky and could not distinguish "fast
because cached" from "fast because the machine was idle". A separate test bumps `config_version` mid
process and asserts exactly **one** reload — a configuration change reaches a running worker without
a restart, and costs one recompile.

---

## Verification

| | |
|---|---|
| `make lint` | 258 files, clean |
| Unit suite | **300 tests, 730 assertions — pass** |
| Integration suite | **517 tests, 3667 assertions — pass** (51 of them gateway tests) |
| `sms-gateway-backfill` (dry run + apply) | ran; idempotent on re-run |
| `sms-gateway-integrity-check` | ran with and without a gateway; exit 0, zero critical |
| `sms-gateway-status` | ran; reports transport state, compile health, secrets-by-name |
| `sms-gateway-simulate --compare` | ran; reports IDENTICAL, exit 0 |
| `sms-status-poll` | ran |

New test files: `GatewayParityTest` (5), `GatewayDispatchTest` (10), `GatewaySecurityTest` (20),
`GatewayStatusPollTest` (9), `GatewayAdminHttpTest` (7, real server and real sessions). Two new HTTP
fixtures: a recording receiver and a fake status API.

---

## Decisions worth knowing about

**The transport is off by default and rollback is one variable.** STEP 48 re-points the live SMS path,
which is the one change here that can stop a production system from sending. It is therefore an
explicit operator action taken *after* the request has been proven identical, not something a
migration switches on. `SMS_GATEWAY_TRANSPORT=0` returns every send to the legacy client immediately,
with no configuration to undo.

**A route with no gateway falls back to the legacy client rather than refusing.** Mid-rollout a route
may legitimately not have a gateway yet, and refusing would turn incomplete configuration into an
outage. Every fallback logs `gateway.dispatch.falling_back_to_legacy`, and the integrity check counts
them, so "quietly still on the old path" is visible rather than assumed.

**`ellsms_hmac` is a named scheme, not a described one.** The existing integration signs requests with
a canonical string this platform's backend already verifies. Rather than letting an admin describe a
signing algorithm — which is configuration becoming code — it is a first-class scheme implemented in
code that configuration merely *selects*, naming which secrets hold the credentials.

**Two new parameter data types exist purely for parity.** `numeric` (a JSON number when the value is
all digits, a string otherwise) and `string_list` (comma-separated → JSON array) reproduce exactly
what the legacy client sends. Without them, parity would have been approximate.

**No credential moved into the database as a side effect of a schema change.** The migrated gateway
references `BACKEND_SERVICE_ID`/`BACKEND_SERVICE_SECRET` through an allowlisted environment-secret
mechanism; they stay in the environment, out of backups.

**The status connector has no configurable success rule.** "2xx with a parseable body" is the only
sensible reading of a delivery-status answer, and a knob there would let a failed poll be treated as
a delivery report.

**A configured poll delay of `0` means zero.** An earlier version silently substituted the 30-second
default for it, which made the field lie; fixed, with the default now applying only when the
connector has no value at all.

**Secrets memoize in `$GLOBALS`, not a function static.** A static memo would make key rotation
invisible until process restart — true in production, but it would also make the rotation path
untestable, and an untestable security path is one nobody can verify. `gateway_cache_reset()` now
drops the key alongside the connectors that were compiled with it.

---

## Not finished / known limitations

1. ~~**No DNS pinning on gateway endpoints (TD-072).**~~ **CLOSED 2026-08-11** — the connection is now
   pinned to the validated address via `CURLOPT_RESOLVE`, with TLS still verified against the
   configured hostname. See [`sms-gateway-connectors-closure-report.md`](sms-gateway-connectors-closure-report.md).
2. **No delivery-status connector ships configured**, because the existing integration has no
   delivery API. The worker, mapping and monotonicity guarantee all exist and are tested; they have
   nothing to poll until a provider with a status API is configured. Inventing an endpoint would have
   been fabrication.
3. **`SMS_GATEWAY_MASTER_KEY` is an operational prerequisite**, not application behaviour. Carrying it
   between hosts is the operator's job; a restore without it is detected and reported CRITICAL rather
   than silently degrading (`backup-and-disaster-recovery.md` §26).
4. ~~**Batch mode applies the first destination's operator overrides to the whole request.**~~
   **FIXED 2026-08-11** — the operator is resolved per destination and the batch is partitioned by
   effective configuration. A gateway with no operator overrides still sends one request, so legacy
   parity is unaffected. See the closure report.
5. **Delivery status is polled, not received.** A webhook receiver can be added later without
   redesign — it would write through the same `gateway_status_record()`.
6. ~~**Provider message ids are persisted for bulk items only.**~~ **FIXED 2026-08-11** — accepted
   direct sends now record their transport identity on an `ellsms_message_attempts` row, and the
   status poller reads both sources with strict gateway affinity. See the closure report.
7. **Nothing is committed.** The working tree still carries this feature plus the three previous ones
   (impersonation is staged; pricing, profile and gateway files are not). Commit strategy is still an
   open question from an earlier turn.

Two pre-existing test issues surfaced while running the suite repeatedly and are recorded in
`technical-debt.md`: `SubscriptionEffectiveSlotConcurrencyTest` deadlocks intermittently under load
(unrelated to this work), and `DatabaseOperationalScriptsTest` requires an empty migration ledger, so
a second suite run against the same container fails it until cleared. Both passed in the final clean
run.

---

## Explicitly not implemented (per the specification's exclusions)

No smart routing. No cheapest-route or cheapest-gateway selection. No provider health selection. No
automatic failover. No gateway choice exposed to the customer API. No execution of pasted `curl`. No
arbitrary admin code. No plaintext secret storage. No per-SMS config query, decrypt, or mapping
compilation. No Redis introduced for config caching. No Telegram work. No other feature started.
