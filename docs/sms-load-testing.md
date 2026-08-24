# ELLSMS — SMS load testing (Phase 9B)

A repeatable, mock-only harness that drives the real pipeline — bulk job, atomic claim, batched
provider dispatch, delivery-status polling — and reports what actually happened.

## Running it

```
ELLSMS_TEST_DB_HOST=127.0.0.1 ELLSMS_TEST_DB_PORT=13306 \
ELLSMS_TEST_DB_NAME=ellsms_test ELLSMS_TEST_DB_USER=ellsms_test \
ELLSMS_TEST_DB_PASS=... make sms-load-10k
```

| Target | Recipients | Notes |
|---|---:|---|
| `make sms-load-1k`   | 1,000 | |
| `make sms-load-10k`  | 10,000 | |
| `make sms-load-100k` | 100,000 | |
| `make sms-load-500k` | 500,000 | status phase off by default |
| `make sms-load-1m`   | 1,000,000 | status phase off by default |

Or drive it directly:

```
php cron/perf-sms-load.php --recipients=10000 --provider-batch=200 --worker-claim=1000
php cron/perf-sms-load.php --recipients=1000 --mode=p2p --mock-mode=MIXED --no-status
```

Options: `--recipients`, `--mode` (`bulk`|`p2p`), `--gateway` (`mock` only), `--provider-batch`,
`--worker-claim`, `--import-chunk`, `--mock-mode`, `--mock-latency`, `--no-status`, `--keep`,
`--label`, `--json`.

Results are written to `storage/benchmarks/perf-sms-*.json` (gitignored) and summarised on stdout.

## Safety

- **`--gateway` accepts only `mock`.** There is no code path to a real provider.
- The mock is started on an **OS-assigned loopback port**, per run, and torn down afterwards.
- Refuses to run unless `BACKEND_DB_NAME` contains `test`, or `ELLSMS_ALLOW_LOAD_TEST=1` is set
  explicitly. Same guard as `cron/load-test.php`.
- Seeds its own disposable user/org/gateway/route/job and deletes them afterwards (`--keep` opts
  out). No provider credentials are involved at any point.

## How provider requests are counted

From the **mock's own request log**, not from an internal counter.

`MOCK_SMS_REQUEST_LOG=<path>` makes `mock/gateway.php` append one JSON line per request. It records
counts and byte sizes, never the recipients themselves. The variable is unset in normal operation,
so this is inert unless a harness asks for it.

This matters: the one-request-per-recipient defect that Phase 9A fixed was invisible to every
internal measure while it was issuing a million requests. A harness that trusted internal counters
would have measured it as healthy.

## How it differs from `cron/load-test.php`

That harness predates the gateway work. It exercises the **legacy fake backend** with N worker OS
processes to measure queue throughput and concurrency. This one exercises the **gateway path** and
answers a different question: how many provider requests does N recipients become, and where does
the time go. Both are useful; neither replaces the other.

## Measured results

MySQL 8.0.46 in Docker, single PHP process, mock gateway on loopback with zero artificial latency.
Every number below was executed — nothing is projected.

| Recipients | Provider requests | Avg batch | Send | msg/sec | Status | Delivered | Peak mem |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 1,000 | **5** (expected 5) | 200 | 17.4 s | 57.3 | 37.5 s | 1,000 | 6 MB |
| 10,000 | **50** (expected 50) | 200 | 339.1 s | 29.5 | 104.7 s | 3,000 † | 10 MB |
| 100,000 | **497 logged / 500 actual** ‡ | 200 | 2,997.8 s | 33.4 | 89.7 s | 3,000 † | **14 MB** |

† The status phase stops after its bounded pass budget (30 passes); the remaining rows are simply
not polled yet within the harness run, not stuck.

‡ See [the 497-vs-500 discrepancy](#the-497-vs-500-discrepancy) below — an instrumentation defect,
since fixed, not a batching anomaly.

**Batching is confirmed exact at all three scales**: 1,000 → 5 requests, 10,000 → 50, 100,000 → 500,
every batch exactly 200 (min = max = avg). Before Phase 9A the same workloads would have issued
1,000, 10,000 and 100,000 requests respectively.

**Memory is bounded**: 100× more recipients cost **8 MB more** (6 → 14 MB), because nothing
accumulates per recipient. Import throughput actually *improved* with scale (32k → 63k rows/sec) as
the chunked multi-row insert amortised better.

## The bottleneck this harness found

Provider batching is no longer the constraint. Timing one 200-recipient batch:

| | |
|---|---:|
| Provider HTTP request | **5 ms** |
| Whole batch, end to end | **3,650 ms** |

So ~99.9% of send time is **per-item database work** — roughly 18 ms per recipient — and throughput
therefore *falls* as volume grows (57 msg/sec at 1k, 29.5 at 10k) even though the request count
scales perfectly.

The cause is visible in `bulk_finalize_item()`: every successful item issues **two** UPDATEs — its
own row, plus `UPDATE ellsms_bulk_jobs SET sent_rows = sent_rows + 1`. That second statement targets
**one shared row per job**, so all 200 items in a batch serialize on the same row lock, and each
takes a round trip.

Two candidate fixes, both deliberately **not** applied here (Phase 9A is committed and correct;
changing it needs its own phase and its own tests):

1. **Aggregate the counter.** Update `sent_rows`/`failed_rows` once per batch with the counts, not
   once per item. Removes N round trips and N lock acquisitions per batch. The per-item wallet
   commit must stay per item — that keying is what makes a crash replay safe.
2. **Batch the item UPDATEs.** A single multi-row `UPDATE ... CASE` or a temporary-table join for
   the accepted set, instead of one prepared statement execution per row.

Either would move the ceiling substantially. Until then the honest headline is: **batching is
fixed; per-item settlement is now the limit.**

## UI isolation

Representative admin/report queries were run against the same database *while* a 100,000-recipient
load test was in flight:

| Query | Latency |
|---|---:|
| Report page (keyset, 50 rows) | 0.5–1.0 ms |
| Bulk job list | 2.0 ms |
| Export list | 2.5 ms |
| Gateway list | 3.0 ms |

No contention observed. (A first measurement of 234 ms was connection setup, not the query — repeat
runs settled at sub-millisecond.)

## Running the integration suite alongside this work

The HTTP integration tests spawn their own `php -S` child and hand it `getenv('BACKEND_DB_*')` —
**not** the `ELLSMS_TEST_DB_*` names this harness and `IntegrationTestCase` use. Export both sets
with the same values:

```
export BACKEND_DB_HOST=$ELLSMS_TEST_DB_HOST  BACKEND_DB_PORT=$ELLSMS_TEST_DB_PORT \
       BACKEND_DB_NAME=$ELLSMS_TEST_DB_NAME  BACKEND_DB_USER=$ELLSMS_TEST_DB_USER \
       BACKEND_DB_PASS=$ELLSMS_TEST_DB_PASS
```

Without them the child server falls back to `.env`'s `change_me` placeholders, `health.php` answers
`{"database":"error"}` with HTTP 503, and every HTTP test fails. Worth knowing because the symptom —
a whole class of tests failing together on a machine that has just finished a long load run — reads
convincingly as environmental exhaustion. It is not; the server is alive and correctly refusing.

## Known limits of the harness

- **Single process.** It measures the pipeline's per-item cost, not multi-worker scaling; use
  `cron/load-test.php` for the concurrency question.
- **Zero-latency provider by default.** Real providers are slower; `--mock-latency=MS` simulates
  that, and with per-item DB cost dominating, provider latency barely moves the total until it
  exceeds ~18 ms per recipient.
- **The status phase is bounded** at 30 passes, so very large runs will not fully drain it. That is
  why `sms-load-500k` and `sms-load-1m` default to `--no-status`.

## The 497-vs-500 discrepancy

The 100k run's request log recorded **497** send requests covering **99,400** recipients, while the
database recorded **100,000 rows sent with zero retries, every item at attempt 1**.

The arithmetic is exact and identifies the cause precisely:

| | |
|---|---:|
| Logged requests | 497 |
| Recipients covered by logged requests | 99,400 |
| Recipients actually sent (database) | 100,000 |
| Unlogged recipients | **600 = exactly 3 full batches of 200** |
| Actual requests | **500** |

Every logged batch was exactly 200 (`min == max == avg == 200`), so the three missing entries were
whole 200-recipient requests, not partial ones. Combined with zero retries in the database, the
sends demonstrably happened; three *log lines* did not get written.

**What could not be proven.** The write was `@file_put_contents(..., FILE_APPEND | LOCK_EX)`, and
the `@` discarded any error, so the run left no evidence of *why*. Attempts to reproduce the loss
failed: 500 sequential writes, 2,000 sequential writes, and 8 concurrent processes × 100 writes each
all completed with zero loss and no short writes. The mechanism remains unproven — most plausibly a
transient filesystem condition during a 50-minute run on this WSL mount, but that is a hypothesis,
not a finding.

**So the instrumentation was fixed rather than the discrepancy explained away.** A benchmark that can
silently lose a log line under-reports its request count, which reads as *better-than-real*
batching — the most dangerous direction for a measurement to be wrong in. The mock now:

- retries the write up to 3 times with backoff (1 ms, 2 ms);
- treats a **short** write as a failure, since a partial line would corrupt the JSONL and be dropped
  at parse time;
- writes a countable `{"lost":1}` marker if all attempts fail, so a gap is auditable rather than
  invisible.

And the harness now reports `log_lines_lost`, `log_lines_unparsable`, and
`requests_implied_by_db` (`ceil(sent / batch_size)`) alongside the logged count, so the observed
HTTP record and the authoritative database can be reconciled in the artifact itself.

Verified after the fix, at 1,000 recipients: **5 logged = 5 implied by DB, 1,000 covered = 1,000
sent, 0 lost, 0 unparsable.** The metric now reconciles exactly.

The 100k figures above are reported as **500 actual / 497 logged** rather than being silently
restated as 500, because 497 is what that run's instrumentation actually observed.

## 500k / 1m

**NOT EXECUTED.**

Duration below is an **ESTIMATE**, extrapolated from the measured 100k run (2,997.8 s of send time
for 100,000 recipients ≈ 30 ms/recipient). It is **not** a benchmark result and no 500k/1m figures
should be quoted as measured:

| Scale | ESTIMATED send duration | Status |
|---|---|---|
| 500,000 | ~4 hours | NOT EXECUTED |
| 1,000,000 | ~8 hours | NOT EXECUTED |

Running them would re-measure the already-identified per-item settlement bottleneck at greater
length rather than reveal anything new: batching is proven exact at three scales (5 / 50 / 500
requests, every batch exactly 200), and memory is proven flat (6 → 14 MB across a 100× range). They
become worth running once per-item settlement is addressed.

## Three bugs this work uncovered

All were in committed code and are fixed alongside the harness:

1. **`cron/mock-gateway-seed.php` mapped provider token `5` to `pending`** — not one of
   `GATEWAY_DELIVERY_STATUSES`. The whole gateway therefore failed to compile, and every send
   *silently fell back to the legacy backend*. `make mock-gateway-seed` produced a gateway that
   never sent through the gateway path at all. Now maps to `queued`.
2. **`mock/gateway.php`'s `/status` endpoint returned HTTP 500 for numeric reference ids.**
   `state_for_id(string $id)` is strictly typed, but a JSON body carrying bare numeric ids decodes
   them as `int`. Delivery status could never be polled from the mock. Now cast at the boundary.
3. **`mock_reference()` reissued the same references in every batch.** It derived from
   `(seed, index, context)` only, so each batch restarted at index 0 and produced identical values.
   The 100k run stored **200 distinct references for 100,000 recipients** — each shared by ~500
   rows. Nothing downstream could tell those recipients apart, which makes delivery-status polling
   meaningless at scale. Found by this benchmark; the destination is now part of the reference, so
   100,000 recipients yield 100,000 distinct references while staying deterministic.

Worth noting what bug 3 was *not*: ELLSMS's positional correlation was correct throughout. It
faithfully stored the reference the provider returned for each position — the fake provider was
handing out duplicates. A validation script that stopped at "duplicate provider ids found" would
have blamed the wrong component.

The harness now fails loudly if the gateway does not compile, rather than benchmarking a fallback
path and reporting it as success.
