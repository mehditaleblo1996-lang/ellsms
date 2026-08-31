# Controlled queue cancellation (issue #11)

## Agreed behavior

An admin can cancel a single queued message, an entire campaign, or every queued message currently
routed to a given provider — without touching anything already sent or already in flight. Every
cancellation is audited (actor, scope, count, reason, outcome).

## What was already there

The per-campaign cancel button on `public/p2p-send.php` and `public/smart-send.php` already existed,
but each had its own inline SQL: it flipped the job to `cancelled` and rewrote pending items, with no
shared code, no audit log entry, and no chunking (a single unbounded `UPDATE ... WHERE job_id = ?`
across however many pending rows a large campaign has). There was no way to cancel one message inside
a campaign, and no way to cancel by provider at all.

## What was added

`app/BulkCancellation.php` is the one place all three scopes go through:

- `bulk_cancel_message(int $itemId, array $actor, string $reason)` — cancels exactly one item, only
  if it is still `pending`. Owner or admin only.
- `bulk_cancel_campaign(int $jobId, array $actor, string $reason, ?string $expectedType)` — flips the
  job to `cancelled` first (if it is `pending`/`processing`), releases its wallet reservation, then
  cancels every still-`pending` item in **chunks** (`BULK_CANCELLATION_CHUNK_SIZE`, default 500, via
  a looped `UPDATE ... WHERE status='pending' ORDER BY id LIMIT n`) so a campaign with hundreds of
  thousands of rows never holds one giant table lock. Owner or admin only; `$expectedType` lets a
  caller (e.g. `p2p-send.php`) refuse to cancel a job that isn't actually a `p2p` job.
- `bulk_cancel_by_provider(string $providerKey, array $actor, string $reason)` — admin only. Walks
  every active (`pending`/`processing`) job, resolves each job's current provider via
  `bulk_job_provider_key()` (the same routing resolution `dispatch_message()` itself uses — the
  legacy backend, or `gateway:<id>` for issue #8's gateway system), and calls `bulk_cancel_campaign()`
  on every match.
- `bulk_cancellation_audit()` — every call above writes one `ellsms_audit_log` row
  (`queue_cancellation.message` / `.campaign` / `.provider`) recording the actor, scope, item/job
  count, reason, and outcome (`cancelled`, `already_terminal`, `not_found`, `forbidden`,
  `type_mismatch`) — including on a no-op, so "nothing happened" is as visible as "cancelled".

### The one rule that makes this safe under concurrency

**Every cancellation UPDATE is `WHERE status = 'pending'`.** A row a worker has already claimed is
`processing`, not `pending` — cancellation can never rewrite it, so a message that is already mid-send
always completes (or fails and retries) exactly as if no cancellation had happened. A row already
`sent`/`failed` is likewise never touched. This is the same guarantee the worker's own pre-dispatch
recheck (`bulk_item_preflight()` in `app/backend.php`, which is what makes the *job*-level `cancelled`
status actually stop new claims) already relies on — cancellation only had to preserve it, not
invent it.

### Where it's exposed

- `public/p2p-send.php` / `public/smart-send.php` — existing cancel buttons now call
  `bulk_cancel_campaign()` instead of inline SQL (same UI, now audited and chunked).
- `public/queue-cancellation.php` (new, admin-only) — cancel one message by item ID, or cancel every
  queued message for a given provider (with a live pending-count-per-provider table and provider
  health status alongside).

## Tests

`tests/Integration/BulkCancellationTest.php` — the three scopes, the "never rewrites a non-pending
row" rule, wallet reservation release, chunking across multiple batches, ownership/admin checks, and
the audit log content.

`tests/Integration/BulkCancellationRaceTest.php` — the acceptance criterion "tests cover races between
worker claim/send and admin cancellation": a real worker subprocess (same real-`kill`-capable harness
as issue #6's crash test) claims one item against a deliberately slow fake backend so it is genuinely
`processing`, an admin cancellation runs while that request is still in flight, and the test asserts
the in-flight item completes normally (`sent`) while the still-pending item in the same job is
cancelled.

## Deliberately out of scope

- **Undo** — cancellation is one-directional; re-queuing a cancelled item/campaign is not built here.
- **Bulk multi-select in the UI** — `queue-cancellation.php` cancels one message ID or one whole
  provider at a time, not an arbitrary admin-picked subset of messages.
