# Delivery runtime & reporting closure

Two problems, one cause and one consequence.

**The runtime problem.** `gateway_status_poll_pass()` was correct and proven live — a real message
went `sent → delivered` when an operator typed `php cron/sms-status-poll.php`. Nothing ran it. There
was no scheduler, no container, no cron entry: delivery status tracking was implemented and never
executed, which is indistinguishable from broken for anyone using the panel.

**The reporting problem.** Even with polling running, the panel could not show what happened. The
reports list read `outbound_message` (backend-owned), while everything the poller maintains —
provider reference, delivery state, poll count, delivery time — lives in ELLSMS's own
`ellsms_message_attempts` and `ellsms_bulk_items`. The two were never joined, and there was no
message detail page at all.

---

## Part A — the persistent status worker

### What was added

`cron/sms-status-worker.php`, run by a dedicated `status-worker` Compose service. It adds **no
polling logic**: it calls the existing bounded `gateway_status_poll_pass()` on an interval and owns
only the question of *when*.

```
app · worker · webhook-worker · status-worker
```

Its own container, for the same reason `webhook-worker` has one: a provider whose status API hangs
must only ever delay other **status** polls, never a scheduled send, an auto-reply, or a bulk pass.

### Worker interval is not connector delay

These are different concepts and the distinction is load-bearing:

| | Meaning | Owned by |
|---|---|---|
| `SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS` | How often we **look** for due rows | the worker |
| `poll_initial_delay_seconds` | How long after a send a message is **worth asking about** | the connector |
| `poll_max_attempts` / `poll_max_age_seconds` | When to **stop** asking | the connector |

A worker waking every 15s against a connector configured for a 30s initial delay simply finds the row
not yet due and skips it. That is why a short interval is cheap rather than abusive to the provider,
and why the interval can never override a connector's limits.

Default 15s, minimum 5s. A configured `0` is **raised to 5 and logged** (`interval_raised`) rather
than rejected — a busy loop issuing continuous claim queries is never what an operator meant, but a
typo must not stop delivery tracking entirely.

### Graceful shutdown

`SIGTERM`/`SIGINT` set a flag; the worker finishes the pass it is in, skips the next, and exits 0.
With `pcntl_async_signals(true)` the signal interrupts the sleep immediately, so shutdown does not
wait out the remaining interval — `docker compose down` does not take an interval per worker, and
`stop_grace_period: 30s` is never reached. Without pcntl this degrades to the OS default, logged
explicitly rather than silently.

### Failure isolation

A provider timeout, DNS failure, malformed response, misconfigured connector, or one bad row costs
**that cycle and nothing more**. The exception is logged at `critical` and the loop continues. A
worker that dies on the first provider outage is worse than no worker: delivery tracking would stop
silently until somebody noticed.

### Overlapping passes

Within one process, impossible by construction — the pass is a synchronous call and nothing else runs
concurrently. If a provider takes longer than the interval, the next sleep simply starts late.

Across processes (accidental duplicate replicas, or `make sms-status-poll` run alongside the
service), safety comes from `gateway_status_claim()`'s compare-and-swap on `delivery_checked_at`,
not from a lock. No Redis was added.

### Observability

Structured events: `gateway.status_worker.started`, `.pass_completed`, `.pass_failed`, `.stopping`,
`.stopped`, `.interval_raised`, `.signal_received`. Pass logs carry `claimed / polled / requests /
updated / terminal / skipped / unmatched / elapsed_ms / interval_seconds`. No secrets — asserted by
test, not merely intended.

`make sms-status-worker-status` reports on the **work** (configured connectors, due rows, oldest due
age, checks in the last 15 minutes, rows stuck at `unknown`) rather than on the process, because
whether a container is alive is `docker compose ps`'s job. It warns only in the one genuinely
actionable case: polling is configured, rows are waiting, and nothing has been checked recently.

The healthcheck deliberately means *"this worker's runtime is alive"*, **not** *"providers are
answering"*. Making health depend on an external provider would have an orchestrator restart the
worker in a loop during an outage — destroying the failure isolation above.

---

## Part B — delivery reporting

### Segment count has exactly one source

The reported part count comes from `ellsms_sms_price_snapshots.segment_count`, frozen at acceptance —
the number the customer was actually billed on. Where no snapshot exists (rows predating snapshots),
it falls back to `sms_parts()`, **the same function** pricing and cost preview call.

`app/Reports/MessageDetail.php` contains no length algorithm. The 70/67 and 160/153 boundaries appear
nowhere in reporting code. This is asserted across Persian, Latin, boundary, multipart and
mixed-encoding cases in `DeliveryReportingTest`.

A stored historical count **wins over recomputation even when they disagree** — that is the point of
freezing it, and it is what keeps a report matching its invoice.

### Historical facts, not current configuration

Route, gateway, `gateway_config_version` and operator are read from what the attempt **recorded**. A
sender's preferred route may have been re-pointed since; reading it now would report a route the
message never travelled. Prices are read from the immutable snapshot and never recomputed — a
verified test changes the tariff afterwards and asserts the historical figure does not move.

### Provider references are strings, always

A 19-digit reference (`4473621976262727360`) exceeds exact integer range in both PHP and JavaScript.
It is carried and rendered as a string end to end, in a monospace LTR cell. CSV export writes it with
a leading tab so Excel keeps it as text rather than converting it to `4.47362E+18`.

The test asserts the exact 19 digits survive **and** separately demonstrates that a float round-trip
genuinely corrupts this value — so the guarantee is shown to be non-trivial rather than merely stated.

### Timestamps that are not each other

| Field | Label | Meaning |
|---|---|---|
| `attempted_at` | زمان تلاش ارسال | when we tried to send |
| `delivery_checked_at` | آخرین استعلام وضعیت | when we last **asked** the provider |
| `delivered_at` | زمان تحویل | when it was **delivered** |

`delivery_checked_at` is never shown as, or substituted for, a delivery time. A message with
`delivered_at IS NULL` shows **هنوز تحویل نشده**, never a fabricated time. The timeline emits steps
only for timestamps that actually exist; a terminal failure shows its step **without** a time rather
than borrowing the poll time.

Poll attempts are labelled **تعداد تلاش استعلام وضعیت** — distinct from send retries, which are a
different concept and a different counter.

### Raw provider status (the one behavioural fix)

`gateway_status_record()` previously wrote only the mapped canonical state; the provider's own token
was discarded. A row stuck at `unknown` was therefore undiagnosable — the operator could not discover
*which* token was missing from the mapping.

The raw token is now persisted alongside the canonical state, and deliberately **also when the
transition is refused** — a terminal row re-reported by the provider, or an unmapped token that
cannot overwrite a known state, is exactly when the operator most needs to see what was said.

Monotonicity is untouched: the token can never change `delivery_status`. Both properties are tested
directly (`delivered` + re-reported `sent` → state stays `delivered`, token recorded as `1`).

### Tenant isolation

Every report query is scoped by organization **in SQL**, not filtered afterwards. A detail page
cannot load another tenant's row and then decide not to show it. Bulk recipients are scoped through
the owning **job**, since `ellsms_bulk_items` has no `organization_id` of its own.

A cross-tenant id returns an ordinary "not found" rather than "exists, but not yours" — the latter
confirms the id is real, which is itself a disclosure. Platform admins keep their existing documented
bypass.

### Performance

Names for a whole page (gateways, routes, operators) resolve in **three bounded queries**, not one
per row. A 50-row recipient table costs three lookups. CSV export enriches in chunks of 500 so a
100k-row export issues neither 100k queries nor loads every attempt into memory.

An index was added for the one access path that had none: `idx_attempt_reference (reference_type,
reference_id)`. The poller's own path was already covered by `idx_attempt_delivery_polling`.

---

## Migration

`db/migrations/2026_08_15_delivery_reporting.sql` — additive, rerun-safe (verified by running it
twice), fresh-DB safe, backup/restore safe, no generated columns (TD-070).

- `ellsms_bulk_items.provider_status` — the column already existed on `ellsms_message_attempts`, so
  the same poller writing through the same function could preserve a token for a direct send and had
  nowhere to put it for a bulk recipient.
- `ellsms_bulk_items.route_id` / `operator_id` — the poller previously selected literal `NULL`s for
  bulk rows, so a batch-capable connector could not group them by route/operator the way it groups
  direct sends. Now recorded from what the send actually used.
- `idx_attempt_reference` on `ellsms_message_attempts`.

**No backfill**, deliberately. A historical row's raw provider token was never captured and cannot be
reconstructed; deriving one from today's mapping would run the mapping backwards and produce a value
the provider may never have sent. Pre-existing rows keep `NULL` and the report shows them as
unavailable — which is the truth.

Old invalid provider ids (the historical `54`–`66` internal values, created before response mapping
was corrected) are **not rewritten**. There is no authoritative mapping source, so they are shown as
the historical values they are.

---

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS` | `15` | How often the worker looks for due rows. Min 5. |

No other new variables. No breaking changes.

---

## Operating it

```sh
docker compose up -d                    # brings up ellsms-status-worker with the rest
docker compose ps status-worker         # is it running
make sms-status-worker-status           # is polling configured, is anything stuck
make sms-status-worker-logs             # tail it
make sms-status-poll                    # still available: one throwaway pass, safe alongside the service
```
