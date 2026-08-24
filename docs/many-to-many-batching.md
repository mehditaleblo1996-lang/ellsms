# ELLSMS — Generic ManyToMany batching (Phase 9C)

How P2P and Smart-send rows — each with their own recipient, and often their own message body —
share provider requests, without a second batching engine and without any provider-specific code.

## The defect

Phase 9A made classic bulk (one body, many recipients) batch correctly. P2P and Smart rows, where
each recipient has different text, stayed one-request-per-row even on a `batch`-mode gateway. Two
things were true at once:

1. `dispatch_message_raw(array $user, string $originator, array $destinations, string $content, ...)`
   took exactly **one** `$content` string for the whole call.
2. `gateway_send_context()` built `messages_array` and `senders_array` with
   `array_fill(0, $count, $message)` — the same value repeated N times.

So even a connector correctly configured to reference `messages_array` never received real per-row
text; the ManyToMany machinery existed but had nothing real to carry. `bulk_group_key()` therefore
had to fragment by `content` to stay correct — and that fragmentation is exactly why different-body
rows never batched.

## What did not change

Everything Phase 9A built stays exactly as it was:

- `gateway_send()`'s grouping by effective configuration (gateway, config version, route, parameter
  signature, sender, message type).
- Positional correlation (`gateway_extract_positional_result()`) — exact count match, fail-closed on
  mismatch, never shifts an id, never fabricates one.
- The claim/throttle ordering: the claim enforces the gradual-send rate; batching only reshapes rows
  that were already eligible.
- Per-item financial settlement, keyed `commit:bulk_item:{id}`.

This phase only makes `messages_array` (and, optionally, `idempotency_keys_array`) carry real
per-destination data, and only relaxes the grouping key's content constraint when the resolved
connector's *compiled parameters* actually consume it.

## The shape

```
bulk_send_group()
  builds perDestinationContent[destination] = item's own content   (keyed, not positional)
  builds perDestinationIdempotencyKeys[destination] = 'ellsms:bulk_item:' . item['id']
        │
dispatch_message_raw(..., $perDestinationContent, $perDestinationIdempotencyKeys)
        │
gateway_send_for_dispatch(..., $perDestinationContent, $perDestinationIdempotencyKeys)
        │
gateway_send()                    groups destinations exactly as Phase 9A does
        │
gateway_send_context()            messages_array[i] = perDestinationContent[recipients[i]] ?? $message
                                   idempotency_keys_array[i] = perDestinationIdempotencyKeys[recipients[i]] ?? ''
        │
gateway_build_request()           only reads messages_array if a compiled parameter says to
```

**Keyed by destination, not by array position.** `gateway_send()` splits destinations into groups
internally (by operator, by effective parameter set); a positional array would need re-indexing at
every split point, and Phase 9B's `mock_reference()` bug is exactly what happens when that kind of
alignment is gotten wrong once. A destination-keyed map survives any split untouched — no
re-indexing, no index-alignment bug class possible.

### The capability gate

`bulk_group_key()` drops `content` from its hash **only** when
`gateway_connector_supports_per_recipient_content($connector)` is true — which scans every compiled
`send` parameter (gateway, route and operator scopes) for one whose `value_type` is `'variable'` with
`value === 'messages_array'`, or a `'template'` whose placeholders include it.

**Capability-driven, not provider-specific.** Nothing here names a gateway, a provider, or a
connector code. A gateway configured with the scalar `message` variable keeps fragmenting by content
exactly as Phase 9A left it; only a gateway an admin has explicitly wired to `messages_array` gets the
relaxed grouping. Fails toward `false` (keep separating by content) on any resolution problem — a
capability that cannot be confirmed is never assumed, because assuming it wrongly would silently send
the wrong text to every recipient after the first in a wrongly-merged group.

`gateway_connector_capability_for_sender()` resolves the SAME route `gateway_send_for_dispatch()`
would use, through the same TTL-cached (`sms_pricing_route_for_sender`) and version-cached
(`gateway_compiled`) lookups every other send already goes through — deliberately **not**
re-memoized in `bulk_group_key()`'s caller. An earlier draft added a second `static` memo keyed only
by originator; it had no invalidation of its own, so a gateway reconfigured mid-run (or, concretely,
two tests reusing one sender against two different gateways) kept returning the first answer forever.
Removed rather than patched with a version key, because the caches this already goes through are the
correct place for that invalidation to live.

## At-least-once delivery

**Phase 9A's finding stands: the provider-accept-then-worker-crash window is at-least-once, not
exactly-once, and Phase 9C narrows it rather than closing it.**

If the provider accepts a batch and the worker dies before the per-row `UPDATE` commits, the lease
expires and those rows are reclaimed and retried — up to `SMS_PROVIDER_BATCH_SIZE` messages may be
sent twice.

### What Phase 9C.10 adds

A **generic, connector-driven** per-message idempotency token: `idempotency_keys_array`, positionally
aligned with `recipients_array` and `messages_array`.

```php
$perDestinationIdempotencyKeys[$destination] = 'ellsms:bulk_item:' . $item['id'];
```

**Deterministic, not random — this is the whole point.** The existing `value_type: 'uuid'` parameter
type generates a **fresh** value on every `gateway_parameter_resolve()` call, so a retry after a crash
would carry a *different* key and a provider trying to deduplicate on it would see two distinct
requests for the same message — worse than no key at all, because it would look like idempotency was
handled when it was not. `bulk_item.id` is stable across a crash because `bulk_claim_items()`'s
lease-expiry reclaim `UPDATE`s the **same row** (`WHERE status='processing' AND lease_expires_at <
NOW()`), never inserts a new one — proven by `testIdempotencyKeyIsStableAcrossARetryOfTheSameRow`,
which re-claims one row and asserts the second request carries the identical key.

**Only reaches the wire when a connector asks for it.** Exactly like `messages_array`:
`gateway_connector_supports_per_recipient_idempotency()` scans compiled parameters for a reference to
`idempotency_keys_array`; absent that, the key is built (cheap: string concatenation) but never
resolves into any request field.

### Why this narrows the risk rather than closing it

Whether duplicate SUPPRESSION actually happens depends on the **provider**, not on ELLSMS:

1. The provider's API must accept a per-message idempotency field at all. `idempotency_keys_array` is
   allowlisted and available; whether a specific provider's ManyToMany endpoint has an equivalent
   parameter is a fact about that provider, not something this project can verify or guarantee.
2. Even where such a field exists, the provider decides the deduplication WINDOW (an hour, a day,
   forever) and the dedup KEY SPACE (global, per-account, per-sender). ELLSMS's key is namespaced
   (`ellsms:bulk_item:{id}`) to avoid colliding with anything else that account might send, but cannot
   control how long the provider remembers it.
3. A provider without such a field receives the value in the request (if the admin wired it there
   anyway) but has no obligation to act on it.

**So: the mechanism is generic and available; whether it actually prevents a duplicate SMS is a
per-provider fact to confirm, not something this project can promise across every configured
gateway.** Money remains protected unconditionally either way — `wallet_commit_reservation()`'s
per-item key makes a replayed settlement a no-op regardless of what the provider does with
`idempotency_keys_array`.

### Operator guidance

- Confirm with each provider whether their batch/ManyToMany endpoint accepts a per-message
  idempotency token, and if so, wire a `variable` parameter to `idempotency_keys_array` (or a
  `template` placeholder) in that gateway's connector configuration.
- Deployments especially sensitive to duplicate delivery can lower `SMS_PROVIDER_BATCH_SIZE` to
  shrink the blast radius of any one crash, independent of whether idempotency support exists.
- A gateway with no such field configured is unaffected and unchanged — nothing about this closure
  requires touching an existing connector.

## Safety, all covered by tests (`tests/Integration/BulkManyToManyBatchingTest.php`)

- **Batching at scale.** 200 different-content rows on a capable connector → one request carrying all
  200 destinations and all 200 bodies. 450 rows at batch size 200 → 200 + 200 + 50.
- **Capability gate holds both directions.** A connector referencing `messages_array` is detected
  capable; one referencing only the scalar `message` is not, and keeps splitting by content exactly
  as Phase 9A left it.
- **Hostile content survives exactly.** Persian, embedded quotes, commas, literal newlines and emoji
  all arrive byte-for-byte in the request body — inherited for free from the existing
  `gateway_json_encode_body()` (real `json_encode()`, never string interpolation).
- **Positional correlation still holds** with per-row content: every recipient keeps its own provider
  reference; a count mismatch still fails closed (empty accepted list, no fabricated ids, nothing
  shifted).
- **Partial failure isolates correctly** — one rejected recipient in an otherwise-accepted ManyToMany
  batch never marks its neighbours sent, and never gets a provider reference of its own.
- **19-digit provider references** survive exact through the same string-only path Phase 9A proved.
- **Concurrency unchanged** — two workers claiming from the same P2P job still get disjoint rows and
  no recipient is sent twice.
- **Gradual throttle still caps a ManyToMany batch** to what the claim (not the batch) made eligible.
- **Money stays per-row** even when three different-content, different-price rows share one request —
  each settles at its own frozen price, not a group total.
- **Idempotency keys are per-row, deterministic across a retry, and absent entirely from a connector
  that never asked for them.**

## Not done here, and why

**Per-recipient sender within one bulk job** is not exercised, because `ellsms_bulk_jobs.originator`
is a single column — every row in one job already shares one sender. "Different originators" in
practice means two separate jobs, which is Phase 9A's
`testItemsWithDifferentSendersAreNeverMergedIntoOneRequest`. `senders_array` itself is unaffected by
Phase 9C and is verified still carrying the correct value
(`testDifferentOriginatorsStillGroupOrSplitPerConnectorCapability`).

**Per-item settlement performance** — the ~30 ms/recipient cost documented in
`docs/sms-load-testing.md` — is unchanged and is not this phase's concern. Test-server throughput
tuning is explicitly out of scope until the real production server (per project working rules).
