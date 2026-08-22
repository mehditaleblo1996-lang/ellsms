# ELLSMS — Reporting scalability (Phase 8)

How the report page and its CSV export stay responsive when `outbound_message` holds millions of
rows, and what is measured rather than assumed.

## The three problems

1. **OFFSET pagination.** `LIMIT 50 OFFSET 400000` makes the database count and discard 400,000 rows
   to produce 50. Cost grows with depth, so page 1 is instant and page 8,000 is a timeout.
2. **A synchronous CSV export.** The export ran inside the web request. At a few thousand rows that
   is fine; at a few million it holds a PHP process and a database cursor open for minutes while the
   browser gives up — and the row count is not knowable in advance, so the same URL is harmless for
   one organization and fatal for another.
3. **An unindexed delivery-lifecycle lookup.** Every report page and every export chunk ran
   `WHERE destination IN (...) AND status = 'accepted'` against `ellsms_message_attempts`, which had
   no index on `destination`.

## What changed

### Keyset pagination

The report page pages by cursor, not offset. Links carry `before_id` / `after_id`; the query becomes
`WHERE m.id < ? ORDER BY m.id DESC LIMIT 51`, which is an index seek at any depth.

Two details that matter:

- **Direction changes the ORDER BY.** "The N rows immediately *newer* than id X" is only expressible
  as `ORDER BY id ASC`. Keeping `DESC` would return the newest rows in the whole filtered set
  instead of the page adjacent to the cursor, silently skipping everything between. The adapter
  flips direction and the page re-reverses the result so the table still reads newest-first.
- **No total page count is shown.** Deriving one needs `COUNT(*)` over the whole filtered set, which
  is exactly what stops being affordable. One extra row is fetched (`LIMIT per+1`) purely to answer
  "is there another page?", then trimmed before rendering.

Page size defaults to 50 and is capped at 200.

### Durable export jobs

An export larger than 5,000 rows becomes a row in `ellsms_report_exports`, and the request returns
immediately. The `export-worker` container claims it, streams the rows to a file, and marks it ready;
the user downloads it from `/report-exports.php`.

The ≤5,000-row path stays synchronous because it was already bounded — it streams through a cursor
with a hard `LIMIT` and flushes every 500 rows, so it never materializes the result set.

```
public/reports.php ──queue──► ellsms_report_exports ──claim──► cron/export-worker.php
                                                                      │ writes
                                    storage/exports/<32-hex>.csv ◄─────┘
                                                                      │ reads
public/report-exports.php ◄───authenticated download───────────────────┘
```

Properties that make it safe:

| Concern | How it is handled |
|---|---|
| Bounded memory | Rows are read in keyset pages of `EXPORT_CHUNK_ROWS` and written straight to the file handle. Nothing accumulates. |
| Resumability | `last_row_id` is committed as it advances, so a killed worker resumes instead of restarting a million-row scan. |
| Crash safety | Written to `<key>.csv.part` and `rename()`d only on success. A reader never sees a partial file. |
| Tenant isolation | Filters *and* the requester's org scope are captured at queue time and re-applied by the worker. It never re-resolves "who can see what" later. |
| Fail closed | An unresolvable member list emits `1 = 0`, so a misconfigured job returns nothing rather than everything. |
| No SQL injection | Filters are stored as JSON **data** and re-compiled through `report_export_filter_sql()`. Stored SQL is never executed. |
| Files outside the web root | `storage/exports/`, not `public/`. The only way to obtain one is the authenticated endpoint. |
| Opaque filenames | 32 random hex characters. The name leaks no phone number, search term, or date range into directory listings or proxy logs. |
| Path-traversal proof | `report_export_path()` rejects any key that does not match `^[a-f0-9]{32}\.csv$`. |
| Truncation is visible | Hitting `REPORT_EXPORT_MAX_ROWS` writes an explicit notice into the CSV. A silently short export is worse than a failed one. |
| Errors do not leak | The stored message is generic; the exception goes to the log. |
| Retention | Files are deleted after `REPORT_EXPORT_TTL_HOURS`; the job row survives as an audit trail of who exported what. Orphaned files older than a day are swept too. |

### Service boundary

`outbound_message` and `user_` are **backend-owned** (`docs/service-boundaries.md` §1). The export
worker therefore does not query them directly — the keyset walk and the count live in
`app/Backend/messages.php` as `backend_outbound_export_page()` and `backend_outbound_export_count()`.
`make backend-boundary-check` enforces this and did in fact catch a first draft that queried them
directly.

`reference_id` and `delivered_at` are selected only when present: the table is not ours, deployments
differ, and a missing optional column should degrade one CSV column rather than fail the export.

## Measurements

All figures from MySQL 8.0.46 with 50,250 `outbound_message` rows and 40,000
`ellsms_message_attempts` rows. Nothing here is estimated.

### Indexes added — and why only two

**`ellsms_message_attempts (destination, status)`** — the single most valuable change:

| | plan | rows examined |
|---|---|---|
| Before | `type=index, key=PRIMARY` | **39,979** |
| After | `type=range, key=idx_attempt_destination_status` | **3** |

That lookup runs once per report page and once per export chunk, so the saving repeats constantly
and grows with table size.

**`ellsms_report_exports (organization_id, id)`** — removes a filesort on the export list, which is
scoped by org and ordered by id. Cheap, on a small table.

Nothing else was added. `idx_claim`, `idx_org_created` and `idx_expiry` ship with the table and
EXPLAIN confirms `idx_claim` is used as a covering index (`Using index`) for the worker's claim.

### Keyset pagination, verified

| Query | plan | rows |
|---|---|---|
| Report page 1 | `type=index`, backward scan | **51** |
| Report page N (cursor) | `type=range` | 24,239 † |
| Export worker page | `type=range` | 24,239 † |

† See the open item below — the range is bounded by `id` but still scans, because the table lacks an
index that combines the tenant and date predicates. The *page size* is nonetheless constant, which
is the OFFSET problem solved.

### Bounded memory, verified

The proof that memory is bounded by chunk size and not row count:

| Export | Rows | Wall clock | Peak RSS |
|---|---:|---:|---:|
| Small | 250 | < 1 s | 33,216 KB |
| Large | **50,250** | 41.9 s | **33,680 KB** |

A 200× larger export used **1.4% more memory**, under a deliberately tight `memory_limit=128M`. The
resulting file was 5,353,751 bytes with all 50,250 data rows present and no truncation.

CSV correctness was checked on hostile content — embedded double quotes escaped as `""`, commas
preserved inside quoted fields, literal newlines retained within a field (RFC 4180), Persian intact,
and a UTF-8 BOM so Excel opens it correctly.

## Open item: `outbound_message` needs an index, but not from here

EXPLAIN shows two real problems that this change deliberately does **not** fix:

```
summary count            type=ALL    key=NULL    rows=48478   (full table scan)
report page (cursor)     type=range  key=PRIMARY rows=24239
```

The report's summary `COUNT(*)` is a full table scan, and cursor pages fall back to a PRIMARY
backward scan because no index combines `sender_user_id` with `sent_at`.

**Recommended for the team that owns the backend schema:**

```sql
ALTER TABLE outbound_message
  ADD INDEX idx_outbound_sender_sent (sender_user_id, sent_at, id);
```

This was **measured**, not assumed — on a throwaway 500,000-row copy of the table:

| Query | Without the index | With it |
|---|---|---|
| Summary `COUNT(*)` | `type=ALL`, 498,200 rows, `filtered=1.11%` | `type=range`, **`Using index`** (covering), `filtered=100%` |
| Summary `COUNT(*)`, wall clock | 0.38 s | **0.18 s** (~2.1×) |
| Cursor page | `type=range key=PRIMARY`, 249,100 rows | *unchanged* — still `PRIMARY` |

Two honest caveats:

- It fixes the **summary count**, which is the worst offender: a full table scan becomes a covering
  index scan, and the gap widens with table size.
- It does **not** change the cursor-page plan. MySQL keeps choosing `PRIMARY` there because that
  index already satisfies `ORDER BY id DESC` without a sort, and it estimates the id range as
  cheaper than filtering the composite and re-sorting. That is a reasonable choice; page size is
  constant either way, which is the property that matters.

So: worth applying for the count, but it is not a cure-all, and the cursor pages are already
bounded without it.

It is **not** applied here because ELLSMS never writes `outbound_message`, and
`cron/backend-boundary-check.php` enforces that. Applying it unilaterally would breach a boundary
the project treats as an invariant. Until then, exports remain correct and bounded in memory; they
are simply slower than they could be on very large tables.

## Operational notes

Configuration lives in `.env.example` under "Report exports". The knobs that matter:

- `EXPORT_CHUNK_ROWS` (2000) — trades memory for round trips. This is what bounds worker memory.
- `REPORT_EXPORT_MAX_ROWS` (2,000,000) — disk-safety ceiling; hitting it marks the file truncated.
- `REPORT_EXPORT_TTL_HOURS` (24) — how long generated files, which contain real message content,
  remain on disk.
- `REPORT_EXPORT_LEASE_SECONDS` (900) — how long before a crashed worker's job is reclaimable.

Commands:

```
make export-worker-once    # drain one export in the foreground (debugging a failure)
make export-worker-logs    # follow the container
make export-cleanup        # run the retention sweep on demand
```

Diagnosing a stuck export: check `status` in `ellsms_report_exports`. `queued` with no progress
means the worker is not running (`docker compose ps export-worker`); `processing` with a
`lease_expires_at` in the past means a worker died and the job will be reclaimed on the next pass;
`failed` records a user-safe message, with the real exception under `report_export.failed` in the
log.

## Tests

`tests/Integration/ReportExportTest.php` — 16 tests, 968 assertions:

- tenant isolation across organizations, and the fail-closed empty-member-list case
- admin scope is not widened by a stored filter
- an export cannot be fetched by another organization (direct object reference)
- two workers cannot claim the same export; an expired lease *is* reclaimable
- a large export is written in bounded pages, each row exactly once, cursor strictly descending
- a chunked walk returns exactly the same rows as a single query
- stored filters (status / search / destination) survive the round trip
- a hostile search term is bound, not interpolated
- a failed export records a user-safe message with no SQL or data leaked
- only a `ready` export is downloadable
- storage keys outside this module's format are refused, and never resolve inside `public/`
- retention deletes the file, keeps the audit row, and leaves unexpired exports alone
- the export list is org-scoped, newest-first, and bounded
