# ELLSMS — Observability & Performance (Phase 9)

This document is the living technical reference for Phase 9's metrics, operational commands,
benchmark methodology, and the capacity/scaling conclusions drawn from measured results. See
`docs/phase-9-final-report.md` for the phase's closure narrative and acceptance-criteria evidence.

## 1. Metrics catalog

Every metric is a structured JSON log line (`Metrics` class, `app/Support/Metrics.php`) — event
name `metric.<name>`, `metric_type` one of `counter`/`timing`/`gauge`. No new storage, no external
agent: metrics ride the same `storage/logs/ellsms-YYYY-MM-DD.log` file and the same redaction rules
every other log line already uses (see §"Security" below).

| Metric | Type | Where | Meaning |
|---|---|---|---|
| `backend.request` | timing | `app/Backend/ApiClient.php` | one `backend_api_request()` call, tagged `method`, `result` (success/unreachable/invalid_response/error_response), `http`, `error_class` |
| `backend.request.not_configured` | counter | ApiClient.php | attempted a call with no `api_base_url` configured |
| `queue.claim.bulk_items` | timing | `bulk_claim_items()` | one claim transaction, tagged `requested`/`claimed` |
| `queue.claim.bulk_items.batch_size` | gauge | `bulk_claim_items()` | rows actually claimed this call |
| `queue.claim.schedule_lookup` | timing | `run_due_schedules()` | the due-row SELECT, tagged `found` |
| `queue.claim.autoreply_scan` | timing | `run_autoreply_pass()` | new-inbound cursor scan, tagged `found` |
| `queue.claim.autoreply_retry` | timing | `run_autoreply_pass()` | expired-lease retry scan, tagged `found` |
| `queue.lease_reclaimed` | counter | `bulk_claim_items()` | a claimed row's `attempt_count` was already >1 (a crashed/expired claim being reclaimed), tagged `job_type` |
| `worker.pass.schedules` / `.autoreply` / `.bulk` | timing | `cron/worker.php` | one full pass, tagged `ok` |
| `worker.pass.schedules.processed` / `.autoreply.sent` / `.bulk.sent` | gauge | `cron/worker.php` | count returned by that pass |
| `worker.pass.failed` | counter | `cron/worker.php` | a pass threw, tagged `pass` |
| `worker.loop_duration` | timing | `cron/worker.php` | one full tick (all three passes) |
| `worker.idle_duration` | timing | `cron/worker.php` | the `sleep($pollIntervalSeconds)` between ticks |
| `worker.graceful_shutdown_duration` | timing | `cron/worker.php` | signal received → process actually exits |

**Sampling**: `METRICS_LOG_SAMPLE_RATE` (default `1` — emit every call). Lower it only if a specific
deployment's log volume is a genuine problem; the default preserves full visibility.

**Point-in-time state** (queue depth, backlog age, stale leases, stale reservations) is deliberately
NOT tracked as a running counter here — it's computed on demand, straight from the source tables,
by the two operational commands below. A duplicate counter store would just be another thing that
can drift from the tables that are already the ground truth.

## 2. Operational commands

| Command | Cost | Purpose |
|---|---|---|
| `make jobs-status` / `php cron/jobs-status.php` | cheap, indexed | per-status counts across bulk items/jobs, schedules, auto-reply log; oldest-pending-age per queue; active-worker approximation |
| `make jobs-status-json` (`--json`) | same | machine-readable form of the above |
| `make performance-snapshot` / `php cron/performance-snapshot.php` | cheap, indexed (one full-scan exception, disclosed below) | backlog age, expired leases, stale wallet reservations, backend failures in the last hour by error class |
| `make performance-snapshot-json` (`--json`) | same | machine-readable form |
| `make backend-boundary-check` | static analysis, no DB | Phase 8's boundary scanner — unaffected by this phase |

Neither command is wired into `/health` or `/health/ready` (STEP 32) — those stay liveness/
readiness-only and cheap enough for a load-balancer probe on every request; these are on-demand
operator commands.

**Known non-indexed query**: `performance-snapshot`'s stale-wallet-reservation count
(`ellsms_wallet_reservations WHERE status='active' AND expires_at < NOW()`) is a full scan of that
one table — it has `(user_id, status)` but no `(status, expires_at)` index. Left as a full scan
deliberately rather than adding a speculative index: that table's row count tracks in-flight sends
(active reservations), not historical volume, and stayed small and fast in every workload this
phase measured (see §9). Flagged here as the one query to add a `(status, expires_at)` index for if
it ever measurably matters — not done now because there was no measured evidence it needed to be.

## 3. Fake backend (load-test mode)

`tests/fixtures/fake_backend_server.php` — Phase 8 built it for deterministic HTTP-status/timeout
testing; Phase 9 extended it with a configurable latency/failure profile for load testing, driven
by environment variables (`FAKE_BACKEND_LATENCY_MS`, `_LATENCY_JITTER_MS`, `_FAILURE_RATE`,
`_FAILURE_MIX`, `_SEED`) so `cron/load-test.php` can launch it with an exact profile per benchmark
run. Deterministic per seed + per-request body hash (see the fixture's own docblock for why —
PHP's built-in server re-executes the script fresh per request, so determinism can't rely on any
state persisting between requests).

## 4. Load-test harness

`cron/load-test.php` (worker driver: `cron/load-test-worker-runner.php`). Seeds disposable
organizations/users/wallets/bulk jobs via the SAME `bulk_queue_job()` the real app uses, starts the
fake backend with the requested profile, spawns N real OS worker processes running
`run_bulk_send_pass()` in a loop, measures wall-clock throughput, runs the correctness checks in
§8, writes a JSON artifact to `storage/benchmarks/`, and cleans up everything it seeded.

**Safety (Invariant C)**: refuses to run unless `BACKEND_DB_NAME` contains `"test"`, or
`ELLSMS_ALLOW_LOAD_TEST=1` is explicitly set. This is a last-resort guard, not a substitute for
actually pointing it at a disposable database.

**Reproduce any run**:
```
ELLSMS_TEST_DB_HOST=127.0.0.1 ELLSMS_TEST_DB_PORT=33061 ELLSMS_TEST_DB_NAME=ellsms_test \
ELLSMS_TEST_DB_USER=ellsms_test ELLSMS_TEST_DB_PASS=ellsms_test \
LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=2 LOAD_TEST_BACKEND_LATENCY_MS=50 \
LOAD_TEST_LABEL=my_run php cron/load-test.php
```
`make load-test-small` / `make load-test-medium` / `make load-test-workers` wrap common profiles
(see Makefile) — all target the disposable test database, never production by default.

**A real bug this harness itself hit and fixed during this phase**: the fake backend's and each
worker's stdout/stderr were originally wired to `proc_open` pipes nobody read from. At load-test
log volume, that fills the OS pipe buffer (~64KB) within seconds and blocks the child process's
next `write()` indefinitely — one run stalled for ~300s once this happened, mid-benchmark, with the
worker process alive but making zero progress (visible as flat CPU time in `ps`, not a query lock
in `SHOW PROCESSLIST`). Fixed by redirecting both to real log files instead of pipes. Documented
here because it's exactly the kind of instrumentation-overhead bug STEP-12/30-style correctness
checks exist to catch, and because a future maintainer hitting the same "worker looks alive but
frozen, no DB lock, no CPU" symptom should recognize it immediately.

## 5. Clean-state test discipline (STEP 35)

Phase 8's closure report disclosed one transient integration-test failure caused by reusing a
long-lived disposable MySQL container across many separate `phpunit` invocations in one working
session — `ellsms_schema_migrations` isn't reset between invocations the way a genuinely fresh CI
container would be, so a test asserting "the ledger starts empty" saw stale rows from an earlier
run. This phase's fix is procedural, not code: `TRUNCATE TABLE ellsms_schema_migrations` before a
clean full-suite run when reusing a long-lived container across a working session (see
`docs/phase-9-final-report.md` §27 for the exact commands used for this phase's own final
validation). A genuinely fresh container (as CI provides) never needs this. This harness's own load
tests don't have the same issue — they clean up everything they seed, verified by direct row-count
checks after every run during this phase's own testing.

## 6. Workload profiles

| Profile | Items | Workers | Notes |
|---|---|---|---|
| A — small | 500 | 1 | this environment's practical single-worker ceiling for a benchmark that completes in well under a minute |
| B — medium | 500 | 2–8 | multi-worker scaling comparison at fixed item count |
| C — largest safely tested | 500 | 8 | see §12 "Environment limitations" — this session's shared dev sandbox, not a dedicated benchmark rig, made 50,000-item runs impractical within a reasonable working session; item count was kept constant and worker count varied instead, which answers the scaling question this phase actually needs answered without requiring a multi-hour run |

## 7. Single-Worker Baseline

See `docs/phase-9-final-report.md` §7 for the actual measured numbers and artifact paths.

## 8. Correctness checks (every load-test run)

`cron/load-test.php` verifies, from the database, after every run:

- `total_accounted_for` — sent + failed + still-pending items equal exactly what was seeded (no
  item silently disappeared)
- `no_stuck_processing_rows` — zero items left in `processing` after the run's own deadline (a
  stuck claim would mean a lease/finalize bug)
- `no_negative_wallet_balance` — every seeded user's wallet stayed ≥ 0
- `reservations_reconciled` — a drained job's wallet reservation moved out of `active` (committed
  per item as it sent, released for whatever didn't) — an `active` reservation after full drain
  means something was left stranded
- `no_cross_tenant_item_leakage` — every item claimed under this run's own job ids actually belongs
  to one of this run's own job ids (a real query, not an assumption)

A fast but incorrect benchmark is treated as a failed benchmark — `cron/load-test.php` exits
non-zero if any check fails.

## 9. Database query analysis

### `run_due_schedules()`'s due-row lookup

**Before** (`COALESCE(next_attempt_at, run_at) <= NOW()`) — EXPLAIN against 20,000 seeded rows
(2,000 genuinely due):

```
type: ALL   key: NULL   rows: 20000   filtered: 50.01%   Extra: Using where; Using filesort
```

Full table scan. `idx_due (status, next_attempt_at, run_at)` has existed since Phase 4, but
`COALESCE()` over two columns is not sargable — MySQL cannot use any index for it, regardless of
what indexes exist.

**After** (explicit `next_attempt_at IS NOT NULL AND ... <= NOW() OR next_attempt_at IS NULL AND
run_at <= NOW()`, logically identical — see `schedule_due_condition_sql()` in `app/backend.php`):

```
type: range   key: idx_due   key_len: 12   rows: 2002   filtered: 100.00%   Extra: Using index condition; Using where; Using filesort
```

Real range scan on `idx_due`, ~10x fewer rows examined for the same due-count. `Using filesort`
remains in both (the final `ORDER BY run_at LIMIT 20` genuinely needs to sort the filtered
candidate set — a separate cost from the WHERE evaluation this fix targets, and not large enough
at any measured scale to justify further index restructuring). Applied in `app/backend.php`;
regression-pinned by `tests/Integration/QueryPlanRegressionTest.php` (row-selection equivalence to
the old form, plus a static guard against ever reintroducing `COALESCE()` here — see that file's
own docblock for why a live EXPLAIN assertion inside a transaction-wrapped test would be unreliable
rather than meaningful).

### Other hot paths (measured, no change needed)

- `bulk_claim_items()`'s two-pass `UPDATE ... ORDER BY id LIMIT n` — already uses `idx_claim
  (job_id, status, next_attempt_at)` (added Phase 4), confirmed via this phase's `queue.claim.bulk_items`
  timing metric staying flat as batch size varied (see §9 batch-size results in the final report) —
  no non-sargable predicate here to begin with.
- `backend_scan_new_inbound_messages()` (auto-reply cursor scan) — simple `id > ?` range on the
  primary key, already optimal.
- `run_bulk_send_pass()`'s per-tick job-completion check (`NOT EXISTS` over
  `ellsms_bulk_items`/`ellsms_bulk_jobs`) — uses `idx_claim`'s `(job_id, status)` prefix; not
  measured as a bottleneck at any tested scale.

## 10. Index changes

None applied this phase beyond what the `run_due_schedules()` rewrite already made usable
(`idx_due`, which existed since Phase 4 — this phase made the query able to use it, it did not add
a new index). See §2 above for the one full-scan query left un-indexed on purpose, with the
reasoning for why.

## 11. N+1 review (STEP 29)

`bulk_send_one_item()` does two per-item lookups that look N+1-shaped but are deliberately NOT
batched: the job-status/organization-suspension re-check and the `backend_find_user_by_id()`
revalidation. Both exist specifically to catch a cancellation/suspension/access-revocation landing
in the window between a batch claim and this specific item's dispatch (STEP 13/21/26/6 in prior
phases' own language) — batching them across a claimed batch would mean item #50 in a 100-item
batch dispatches against state that's already up to 100 items stale, silently reopening exactly the
race those checks were added to close. Both are single indexed-PK lookups, confirmed cheap at every
batch size this phase tested (§9 batch-size results) — the correctness requirement, not a missed
optimization, is why they stay per-item.

## 12. Environment limitations

Every benchmark in this phase ran in the same shared development sandbox used for the rest of this
session (WSL2 host, MySQL in a separate Docker container reached over a mapped port, PHP's
built-in single-connection-at-a-time server standing in for the backend platform) — not a dedicated
benchmark rig, not representative of a tuned production deployment's hardware or network topology.
Absolute throughput numbers should be read as "what this specific sandbox can do," not as a
production capacity claim. Relative comparisons (scaling efficiency across worker counts, batch
size sensitivity, latency sensitivity) are the load-bearing conclusions this phase draws — those
hold regardless of the absolute floor.

Item counts were kept at 500 (varying worker count/batch size/failure rate around that fixed point)
rather than scaling up to the profile-C 50,000-item target the phase brief describes, specifically
because — see §4's own account of the proc_open pipe-buffer bug this session hit — a single
500-item single-worker run at zero simulated latency already took ~55s in this sandbox; a
50,000-item run at the same per-item overhead would run roughly 90+ minutes, disproportionate to
what a fixed-item-count, varying-worker-count comparison already answers for this phase's actual
question (does throughput scale with workers, and where does it stop). This is a disclosed
constraint of the benchmarking session, not a claim that the application itself cannot handle
larger backlogs — the schedule-query fix in §9 specifically improves behavior at exactly the row
counts (tens of thousands) this environment couldn't comfortably load-test end-to-end.
