# SMS gateway connector — correctness closure

**Date:** 2026-08-11
**Scope:** three defects/limitations recorded when the connector builder shipped. No new features.

Follows [`sms-gateway-connectors-final-report.md`](sms-gateway-connectors-final-report.md); the design
itself is in [`sms-gateway-connectors.md`](sms-gateway-connectors.md).

---

## Closure 1 — per-recipient operator overrides

**The defect.** Batch mode resolved ONE operator (the first destination's) and applied its overrides to
the entire batch. A mixed MCI/MTN/Rightel send went out with one operator's parameters stamped on all
of it.

**The fix.** The operator is resolved per destination, and the batch is partitioned before it is sent.
Each group is one provider request carrying only destinations whose resolved configuration is
identical.

**The design decision that matters.** Grouping is by **effective configuration**, not by operator
identity — a hash of the merged parameter descriptors (key, location, value type, literal value, data
type), plus gateway, config version, route, sender and message type.

Grouping by operator id would have been simpler and wrong: it would split every mixed batch into three
requests even for a gateway with no operator overrides at all, which would have broken the migrated
legacy gateway's byte-level parity for no reason. With a signature, a gateway that has no operator
overrides produces the same signature for every operator and still sends one request.

```
100 recipients — 60 MCI, 30 MTN, 10 Rightel
  gateway WITH operator overrides    ->  3 requests
  gateway WITHOUT operator overrides ->  1 request   (legacy parity preserved)
```

Secrets never enter a grouping key: a secret contributes its key *name*, never its value.

**Per-recipient variables.** `recipient` and `operator_code` have one value per recipient and a request
carries one value per parameter, so a parameter reading either cannot be batched. Detected from the
compiled parameter set; each such destination becomes its own request.

**An unsupported operator now refuses one destination, not the whole send.** The others are
deliverable, and partial success is already modelled everywhere else.

**A second, quieter bug fixed on the way.** The group context was built with `$input + [...]`, and PHP's
`+` union operator keeps the *left* operand's value for a duplicate key — so the full recipient list
silently survived and every group received the whole batch. Caught only because the tests assert on
recorded HTTP bytes rather than on built structures. `array_merge` now.

### Results

| | |
|---|---|
| MCI / MTN / Rightel | `operatorCode` 1 / 2 / 3 — each on its own request, verified from captured HTTP |
| 1000 mixed recipients | zero cross-operator leakage; every recipient accounted for |
| First-recipient independence | three orderings (incl. `MTN, MCI, Rightel, MCI, MTN`) give identical results |
| Route override isolation | route A's override never reaches route B |
| Sender isolation | two sends from two lines carry two different `{{sender}}` values |

### Performance revalidation — 1000 mixed-operator recipients

| counter | value |
|---|---|
| gateway config loads | **1** |
| mapping compiles | **1** |
| secret decrypts | **1** |
| cache reloads | 0 |
| operator-map loads | **0 additional** — operator resolution is an in-memory longest-prefix match over the pricing engine's TTL-cached prefix table |
| outbound request groups | **3** (one per distinct configuration) |
| per-recipient DB config lookups | **0** |

Merged parameter sets are memoized per (gateway, config_version, connector, route, operator), so
partitioning costs three merges rather than a thousand — and no query.

---

## Closure 2 — direct-send provider identity

**The gap.** `provider_message_id` was persisted only on `ellsms_bulk_items`. A direct send, schedule
or auto-reply received a provider id and discarded it, which made generic status tracking structurally
incomplete rather than merely unimplemented.

**Where it goes.** `ellsms_message_attempts` — extended, not replaced. That table was already the
ELLSMS-owned record of what happened to a send at the transport level (Phase 8, Invariant E:
`outbound_message` is backend-owned and ELLSMS must never fabricate rows in it). It recorded only
failures because a success was always a real `outbound_message` row written by the backend; with a
configured gateway that is no longer true, and nothing else holds the provider id.

So the status enum gained `accepted`, and the row gained `gateway_id`, `gateway_config_version`,
`route_id`, `operator_id`, `destination`, `provider_message_id`, `provider_status` and the
delivery-status columns. **No column was added to any backend-owned table, and no second message
history exists.**

Bounded by construction:

- one row **per destination** — a provider message id identifies one message to one recipient;
- written only when the send used a gateway **and** the provider returned an id — a row with no id can
  never be polled, so writing one would add volume and answer nothing;
- the legacy transport writes nothing here, so its behaviour is unchanged;
- `(gateway_id, provider_message_id)` unique via an application-maintained slot column, so a replayed
  worker pass cannot create a second delivery record (a slot rather than a UNIQUE on the pair itself,
  because that pair is NULL on every pre-existing failure row and MySQL treats each NULL as distinct —
  the constraint would have been vacuous for exactly the rows it must police);
- bulk items are excluded (`$recordTransport = false`) — they already have their own durable row, and
  a second would make the poller track one message twice.

**Status affinity.** The poller compiles the connector from the `gateway_id` recorded on the row —
never by re-resolving the route, never the default. Tested by sending through gateway A, re-pointing
the route at gateway B whose status API answers `failed`, and asserting the message still resolves
`delivered` from A.

**Nothing is fabricated.** A send with no provider id leaves no pollable row; the poller's SQL excludes
`provider_message_id IS NULL` rather than inventing one.

Bulk and direct rows are polled by the **same pass and the same code**, so the two cannot drift into
different delivery-tracking behaviours.

---

## Closure 3 — TD-072, DNS rebinding

**Status: CLOSED.**

**The gap.** The address check ran before every request, but curl then resolved the hostname again on
its own — so a name could answer the check with a public address and the connection with
`169.254.169.254`. The check was advisory.

**The fix.** `gateway_endpoint_allowed()` now returns the addresses it validated, and
`gateway_execute()` pins the connection to one of them via `CURLOPT_RESOLVE`. There is no second
resolution to disagree with the first.

Also strengthened while there:

- IPv6 handled explicitly — `::1`, `fe80::/10`, `fc00::/7`, and IPv4-mapped forms such as
  `::ffff:127.0.0.1` (a loopback address in an IPv6 costume is still loopback);
- **every** resolved address must pass, not merely the first — a name answering with one public and
  one loopback address is a rebinding attempt;
- an unresolvable host is refused rather than attempted;
- the internal-host allowlist matches **exact hostnames**, so `evil-sms-gw.internal` and
  `sms-gw.internal.evil.example` do not satisfy an entry for `sms-gw.internal`, and allowlisting one
  host permits no other private destination;
- `gethostbyname()` fallback so containerised deployments (whose names resolve through the system
  resolver, not DNS) are not refused for a name that resolves perfectly.

**HTTPS verification is untouched and still bound to the hostname.** `CURLOPT_RESOLVE` is a
name-to-address override, not a URL rewrite, so the certificate is still checked against the configured
host. The easy version of this fix — putting the IP in the URL and disabling hostname verification —
would have traded an SSRF gap for a much worse MITM hole. TLS verification is not a configurable knob
anywhere, and `--insecure` in a pasted `curl` command is ignored.

**Evidence, in three parts** (no single assertion proves a TOCTOU fix):

1. prohibited destinations are refused — loopback v4/v6, `169.254.169.254`, link-local, unique-local,
   RFC1918 (all three blocks), IPv4-mapped loopback, unspecified, and non-HTTP schemes;
2. refusal happens **at request time without contacting the endpoint** — the gateway is pointed at a
   name resolving to loopback where the test's own recorder is listening, and the recorder sees
   nothing;
3. the pin is honoured by this curl build — a public hostname pinned to the local recorder reaches it,
   which is only explicable by the pin, and the `Host` header still names the original host.

A rebinding resolver was not used, and pretending one was would be worse than saying so. The residual
boundary is documented: a name that legitimately resolves to a public address the attacker also
controls is allowed, because that is an ordinary public host rather than rebinding.

**Redirects** are still never followed — that stops a provider bouncing a request (with its
`Authorization` header) to an internal address, and also stops it escaping the pin.

---

## Verification

| | |
|---|---|
| `make lint` | 261 files, clean |
| Unit suite | **300 tests, 730 assertions — pass** |
| Integration suite | **548 tests, 4700 assertions — pass** (9 skipped: capability-gated, unchanged from before) |
| Gateway-specific | **82 tests, 2308 assertions — pass** |
| Legacy byte parity | **unchanged and exact** — reasserted after the partitioning rewrite |
| `sms-gateway-backfill` / `-integrity-check` / `-status` / `-simulate --compare` / `sms-status-poll` | all ran; simulate reports IDENTICAL, exit 0 |

**One pre-existing flake, named rather than papered over.** Across the confirmation runs the only
residual failure was `SubscriptionEffectiveSlotConcurrencyTest::testTwoProcessesCancellingConcurrently
ReleaseTheSlotExactlyOnce`, which surfaces `SQLSTATE[40001] Deadlock found` roughly one run in five —
including when run entirely on its own (4/5 passes in isolation). It comes from the TD-070 subscription
work, touches no gateway code, and is already in the debt register; the prescribed fix is a bounded
deadlock retry in `subscription_transition()`, deliberately not done inside this closure because
subscription code is outside its declared scope. One full integration run in this session was
completely clean (548/548, 4700 assertions); the others differ only by this test.

New test classes: `GatewayOperatorPartitionTest` (8), `GatewayDirectSendIdentityTest` (9),
`GatewayEndpointSafetyTest` (14).

### Two things the validation itself surfaced

**A per-request DNS lookup, introduced by Closure 3 and then removed.** Resolving before every request
is what makes the pin trustworthy, but an uncached hostname endpoint would have cost a resolver round
trip per send — a genuine hot-path regression, and on a busy worker a resolver one too. Validated
resolutions are now cached per process for `SMS_GATEWAY_DNS_CACHE_SECONDS` (default 30). This does not
weaken the protection: what is cached is the address set that already *passed*, and the connection is
pinned to it, so a mid-window DNS change cannot redirect anything. The cost runs the other way — a
legitimate address change takes up to that long to be noticed. Failed resolutions are cached too,
deliberately: otherwise a name that stops resolving puts a blocking resolver timeout in front of every
send. The gateway suite went from 92s to 51s once this landed.

**Local-server contention in the suite.** `GatewayDirectSendIdentityTest` originally spawned two dev
servers (send + status). A full run with that extra process produced four unrelated failures —
`CostPreviewApiTest` (curl timeout), `WebhookDeliveryTest` ("receiver never accepted connections") and
two concurrency flakes — every one of which passed in isolation. That is process contention, not a
defect, so the recorder fixture now answers both roles and the class runs one server. Worth stating
rather than hiding: this suite's tolerance for concurrent single-threaded `php -S` servers is finite,
and HTTP-fixture timeouts under load are the shape that failure takes.

---

## Migrations

`db/migrations/2026_08_14_gateway_direct_send_results.sql` — extends `ellsms_message_attempts` with
the `accepted` status, transport identity, delivery-status columns, and the provider-slot uniqueness
column. Fully guarded and idempotent. **No generated columns** (TD-070). No backend-owned table is
touched.

## Breaking changes

**None.**

- `SMS_GATEWAY_TRANSPORT=0` remains the default and still preserves the legacy path exactly; the
  rollback story is unchanged.
- `dispatch_message_raw()` and `dispatch_gateway_result()` gained a trailing optional parameter;
  every existing call site is unaffected.
- `gateway_send()`'s `$operatorId` argument is now optional and means "force this operator" rather
  than "the operator" — callers that passed one behave as before.
- `gateway_status_claim()` and `gateway_status_record()` gained a leading `$source` argument. Both are
  internal to the polling engine; the only external caller was a test.
- The legacy transport writes no new rows, and gateways with no operator overrides still send one
  request per batch.
