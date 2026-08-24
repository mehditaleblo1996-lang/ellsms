# ELLSMS — Bulk provider batching (Phase 9A)

How a bulk job stops becoming one provider HTTP request per recipient.

## The defect

`run_bulk_send_pass()` claimed a set of rows and then called, once per row:

```php
dispatch_message_raw($user, $item['originator'], [$item['mobile']], $item['content'], null, false);
```

A **one-element** destination array. So a 1,000,000-recipient job produced roughly 1,000,000
provider HTTP requests — even when the gateway's `send_mode` was `batch`.

## What was already correct

This is the important part, and it kept the fix small. `gateway_send()` **already** implemented
batching properly:

- it resolves an operator **per destination**, not once for the whole call;
- it groups destinations by effective configuration —
  `gateway_id | config_version | route_id | parameter_signature | sender | message_type`, plus the
  destination itself when the connector is per-message or a parameter reads a per-recipient
  variable;
- it emits **one request per group**;
- it returns `message_ids` and `operators` keyed **by destination**, and `sent` as the list of
  destinations the provider actually accepted.

`bulk_send_one_item()` was already reading `$gatewayMeta['provider_message_ids'][$item['mobile']]` —
it expected a destination-keyed map all along. The machinery was complete; the bulk worker was
simply the one caller that never handed it more than a single recipient.

**So Phase 9A did not build a batching engine.** It made the worker pass the whole compatible set,
and split the per-item work into pieces that can be reused for one row or two hundred.

## The shape now

```
bulk_claim_items()            atomic claim, unchanged  ── enforces the gradual throttle
        │
bulk_send_claimed_items()     preflight each row, then group, then chunk
        │
        ├── bulk_item_preflight()   per row: job status, org, subscription, owner account
        ├── bulk_group_key()        what may share a request
        ├── array_chunk(SMS_PROVIDER_BATCH_SIZE)
        │
bulk_send_group()             ONE dispatch_message_raw() with the whole destination array
        │
bulk_finalize_item()          per row: wallet commit, status, provider id, retry policy
```

### The grouping key

Only what the **worker** owns is keyed here; `gateway_send()` applies its own finer grouping on top,
and duplicating that would be a second place to get it wrong.

| Field | Why it must match |
|---|---|
| `job_id` | job status, organization and subscription are re-checked per job; `sent_rows`/`failed_rows` are per job |
| `user_id` | `can_use_originator()` and the owner-account check are per user |
| `organization_id` | tenant isolation and the wallet reservation identity |
| `originator` | changes both the request and the authorization decision |
| `content` | **the binding constraint** — `dispatch_message_raw()` carries one message body per call |

Destination and row id deliberately do **not** split a group; batching them together is the point.

### Batch size

`SMS_PROVIDER_BATCH_SIZE` (default 200, clamped to 1–1000) bounds **one HTTP request**. It is
distinct from three sizes it is easy to confuse it with:

| Knob | Bounds |
|---|---|
| `SMS_PROVIDER_BATCH_SIZE` | recipients in one provider request |
| `WORKER_BULK_BATCH_SIZE` | DB rows one worker pass claims |
| `IMPORT_CHUNK_SIZE` | source rows one import chunk analyzes |
| `throttle_count` | rows a gradual job may send per window |

A 500-row claim at a batch size of 200 becomes 200 + 200 + 100.

`WORKER_BULK_BATCH_SIZE` was **raised from 20 to 200** in this phase. The old default was sized for
a worker that issued one request per row, where claiming more only lengthened the pass. With
batching, the claim size is what a batch can be formed from — leaving it at 20 would have capped
every request at 20 recipients regardless of the batch size.

## Safety

**Gradual throttle.** The claim is what enforces the rate, and it is unchanged and runs *before* any
grouping. A throttled job claims at most `throttle_count` rows per window, so batching can only
reshape requests for rows that were already eligible. It can never send more in a window than the
throttle allows. Covered by `testGradualThrottleStillCapsWhatABatchMaySend`.

**Concurrency.** The atomic claim (`UPDATE ... SET claimed_by = <unique token>` then
`SELECT WHERE claimed_by = <token>`) is untouched. Two workers get disjoint sets. Covered by
`testTwoWorkersNeverClaimTheSameItem`.

**Money stays per row.** The wallet commit is still keyed `commit:bulk_item:{id}`, one per item,
even when two hundred items shared a request. That per-item key is exactly what makes a replay after
a crash a no-op; a single aggregated debit for a batch would forfeit that idempotency and make a
partial failure unsettleable. Covered by `testABatchedSendChargesEachRecipientExactlyOnce`, which
also replays the settlement and asserts the balance does not move.

**Partial failure.** `gateway_send()` returns `sent` — the destinations actually accepted.
`bulk_finalize_item()` decides each row's fate by membership in that list, so one refused recipient
in a batch never marks its neighbours sent, and an accepted recipient is never resent because a
neighbour failed. Covered by `testAPartialBatchFailureSettlesOnlyTheAffectedRecipients`.

**Correlation.** Provider ids come from the destination-keyed map, so every row gets its own
reference — never the first recipient's, never one id shared across rows. Long references stay
strings end to end. Covered by `testEachRecipientKeepsItsOwnProviderReference` and
`testALongProviderReferenceSurvivesBatchedCorrelationExactly`.

**per_message gateways** are unaffected: that decision is made inside `gateway_send()` from the
connector, so the worker needs no knowledge of it. Covered by
`testAPerMessageGatewayStillSendsOneRequestPerRecipient`.

## Measured

1,000 recipients through the real claim + batched send path against the recording receiver
(`tests/fixtures/recording_gateway_server.php`), counting requests that actually crossed a socket:

| | Before | After |
|---|---:|---:|
| Provider HTTP requests | 1,000 | **5** |
| Peak memory | — | 6 MB |
| Duration | — | 53 s |

`items_sent` was 999 of 1,000: one generated number contained `000`, which the recording receiver
rejects by design. That is the fixture's rejection seam, not a defect — and it incidentally
demonstrates partial-failure isolation at scale, since the other 999 in the same batches all sent.

Tests: `tests/Integration/BulkProviderBatchingTest.php` — 13 tests, 68 assertions.

## Residual risks — stated, not papered over

**At-least-once, not exactly-once.** If the provider accepts a batch and the worker dies before the
per-row UPDATE commits, the lease expires and those rows are reclaimed and re-sent. This window
existed before Phase 9A and is **unchanged in kind** — but it is now **wider in blast radius**: a
crash in that window previously risked re-sending one message, and can now risk re-sending up to
`SMS_PROVIDER_BATCH_SIZE` of them.

Money is protected either way: `wallet_commit_reservation()` is keyed per item, so a replay does not
double-charge. What is not protected by this project alone is the recipient receiving the SMS twice —
that additionally needs the PROVIDER to recognize a retried message. Phase 9C.10 added the generic
mechanism for that (`idempotency_keys_array`, deterministic per `bulk_item.id`); see
`docs/many-to-many-batching.md#at-least-once-delivery` for the full account, including why it narrows
this risk rather than closing it. Deployments especially sensitive to duplicates can also lower
`SMS_PROVIDER_BATCH_SIZE` to shrink the exposure.

**Different-content rows.** ~~Not yet batched as of Phase 9A~~ — closed by Phase 9C. p2p and
smart-send rows, where each recipient has their own text, now batch on any connector whose compiled
parameters reference `messages_array`; a connector that does not stays exactly as this phase left it
(fragmented by content, one request per row). See `docs/many-to-many-batching.md`.
