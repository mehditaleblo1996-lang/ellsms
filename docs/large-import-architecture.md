# ELLSMS — Large-scale import architecture (Phase 10)

How a large P2P/Smart/gradual recipient file becomes priced, reserved `ellsms_bulk_items` rows —
streamed and chunked throughout, with no whole-file PHP array at any point.

## Why this doc exists now

The pipeline (`app/import.php`, `app/import_reader.php`, `app/import_worker.php`,
`cron/import-worker.php`) predates this phase by several sessions and was referenced from
`app/bootstrap.php` as "docs/large-import-architecture.md" — a file that did not exist. It also had
**zero integration test coverage**. Writing the first tests
(`tests/Integration/LargeImportPipelineTest.php`) found that the pipeline could not actually
complete a single import end to end. Five real bugs, all now fixed:

1. **`import_job_check_insert_completion()` chained `->execute([...])->fetchColumn()`.**
   `PDOStatement::execute()` returns `bool`, not `$this`. Every call threw
   `Error: Call to a member function fetchColumn() on true`, caught by the enclosing `try/catch` in
   `import_process_insert_chunk()`, which silently marked the chunk `'failed'`. **Every import failed
   at the very last step**, after successfully analyzing, deduping, blacklist-filtering, and pricing
   every row. Fixed: three separate `prepare()`/`execute()` pairs.

2. **`import_create_job()`'s closure never captured `$template`/`$variableHeaders`.** Both are
   function parameters, not local variables — a `use (...)` clause that names every OTHER parameter
   but these two still leaves them undefined inside the closure. PHP raised
   `Undefined variable $template` warnings and bound `NULL` for both. **Smart-send's per-recipient
   template rendering was silently discarded at creation**, even though the analyze pass correctly
   reads `$job['template']`/`$job['variable_headers']` back out — they were never actually stored.
   Fixed: added both to the closure's `use` list.

3. **`ellsms_import_jobs.message_type` is `NOT NULL DEFAULT 'default'`, but every caller passes
   `null`.** A column default only applies when the column is *omitted* from an `INSERT`, never when
   `NULL` is bound explicitly. `new-send.php`'s gradual mode, `p2p-send.php`, and `smart-send.php`
   all either omit the parameter or pass `null` outright — every one of them hit
   `SQLSTATE[23000]: Column 'message_type' cannot be null` and never got as far as bug #1. **No large
   import via the web UI could ever be created.** Fixed: `import_create_job()` now runs
   `$messageType` through `sms_pricing_normalize_message_type()` — the same normalization the pricing
   engine itself falls back to, so a job's stored type never disagrees with how it gets priced.

4. **`import_claim_uploaded_job()` referenced `ellsms_import_jobs.claimed_by`/`claimed_at`, which no
   migration ever created.** `ellsms_import_chunks` got this column pair
   (`2026_08_16_import_jobs.sql`, "mirrors the proven claim/lease pattern used by
   ellsms_bulk_items") — the jobs table never did. On any database built from the committed
   migrations, the claim UPDATE failed with `Unknown column 'claimed_by'`, meaning **pass 1 could
   never start** — the earliest and most fundamental of the four failures; every import got stuck at
   `status='uploaded'` forever. Fixed: `db/migrations/2026_08_24_import_job_claim_columns.sql`, an
   additive, guarded, rerun-safe migration adding the missing pair.

5. **`import_create_job()` accepted `$originator` as a parameter but never wrote it to the
   `ellsms_import_jobs` INSERT, and no migration had ever created the column.** Every downstream
   read of `$job['originator']` — pass 1's per-chunk pricing, reserve/stage's exact-total repricing,
   `import_create_bulk_job()`, and pass 2's per-chunk pricing — silently evaluated to `''`
   (PHP `Undefined array key "originator"` warning, caught nowhere). Pricing fell through to the
   tenant's default route (`source_default`, visible in the pricing metrics) instead of the sender
   the user actually selected, and — worse — the confirmed bulk job itself was created with
   `originator=''`, meaning the send would have gone out from no sender at all, and the price the
   user was shown/charged could differ from the selected sender's actual route price. Fixed by
   `db/migrations/2026_08_24_import_job_originator_column.sql` (adds the missing column) plus
   persisting `$originator` in `import_create_job()`'s INSERT. Covered by
   `testOriginatorSurvivesFromJobCreationThroughToTheStagedBulkJob`.

Also, `app/import_worker.php` — which defines `import_job_run_analysis()`,
`import_claim_uploaded_job()`, `import_claim_insert_chunk()`, `import_process_insert_chunk()` — was
**only ever `require`'d from `cron/import-worker.php`**. Nothing else in the application, including
the integration test suite's own bootstrap, could reach these functions at all. Added to
`app/bootstrap.php`'s require chain, alongside `import.php` and `import_reader.php`.

**None of these five bugs were caused by anything in Phases 8, 9A, 9B, or 9C.** They predate this
session's work entirely; writing the first tests this pipeline has ever had is what surfaced them.

## The two-pass architecture

```
public/new-send.php (gradual) ─┐
public/p2p-send.php ───────────┼──► import_create_job()
public/smart-send.php ─────────┘         │ counts rows (streaming), inserts the job header
                                          │ ('uploaded'), splits into 'analyze' chunks
                                          ▼
                              import_claim_uploaded_job()      atomic: status uploaded -> analyzing
                                          │
                                          ▼
                              import_job_analyze_pass()        PASS 1, one chunk at a time:
                                          │                    stream rows -> normalize/validate ->
                                          │                    dedupe within chunk -> blacklist filter
                                          │                    -> price -> INSERT IGNORE into
                                          │                    ellsms_import_dedupe (cross-chunk dedupe
                                          │                    via its UNIQUE(job_id, mobile, fingerprint))
                                          ▼
                              import_job_reserve_and_stage()   re-price the FULL dedupe set at the
                                          │                    analysis instant for the exact total,
                                          │                    reserve wallet + quota, create the
                                          │                    ellsms_bulk_jobs row ('staged'),
                                          │                    create 'insert' chunks by dedupe id range
                                          ▼
                              import_claim_insert_chunk()      atomic, parallel-safe across workers
                                          │
                                          ▼
                              import_process_insert_chunk()    PASS 2, one chunk at a time:
                                          │                    read dedupe rows by id range -> price
                                          │                    again at the SAME analysis instant ->
                                          │                    multi-row INSERT into ellsms_bulk_items
                                          ▼
                              import_job_check_insert_completion()
                                          │                    all insert chunks done? -> promote to
                                          │                    'ready_for_confirmation'; any failed? ->
                                          │                    fail the job, release wallet + quota
                                          ▼
                                  user confirms in the UI
                                          │
                                          ▼
                              run_bulk_send_pass()             the SAME batched send path Phase 9A/9C
                                                                built — an import's bulk_items are
                                                                ordinary bulk_items from here on
```

**Only pass 1 is single-worker per job** (`import_claim_uploaded_job()` transitions
`uploaded → analyzing` exactly once). **Pass 2 is parallel-safe** — `import_claim_insert_chunk()`
uses the same atomic claim-token UPDATE-then-SELECT pattern as `bulk_claim_items()` and
`import_claim_chunk()`, so multiple workers may insert different chunks of the same job
concurrently.

## Bounded memory, verified

No stage holds a whole file in memory:

- **Row counting** (`csv_count_rows()`): `fgets()` line by line.
- **Pass 1** (`import_job_analyze_pass()`): reads exactly one chunk's row range
  (`import_read_row_range()`), processes it, writes to `ellsms_import_dedupe`, discards it, moves to
  the next chunk.
- **Reserve/stage's re-pricing walk**: `SELECT ... LIMIT ? OFFSET ?` over `ellsms_import_dedupe` in
  `IMPORT_CHUNK_SIZE`-sized batches, never the whole set at once.
- **Pass 2** (`import_process_insert_chunk()`): reads one chunk's id range from the dedupe table,
  builds a bounded insert buffer (`DB_INSERT_BATCH` rows), multi-row `INSERT`s, discards, repeats.

Verified directly: `test10000RowImportStaysBoundedInMemory` asserts peak-memory growth stays under
2 KB per row across a 10,000-row import (100 analyze chunks + insert chunks at
`IMPORT_CHUNK_SIZE=100`) — nowhere near what 10,000 resident content strings in one array would cost.

## What each acceptance item maps to

| Requirement | Where it's enforced | Test |
|---|---|---|
| Duplicates across chunks | `ellsms_import_dedupe`'s `UNIQUE(import_job_id, mobile, content_fingerprint)` + `INSERT IGNORE` | `testExactDuplicatesAcrossDifferentChunksAreDeduped` (duplicate rows placed in DIFFERENT chunks) |
| Invalid numbers | `normalize_msisdn()` in `csv_read_row_range()`; a `null`/empty mobile is counted `invalid` | `testInvalidMobileNumbersAreCountedInvalidNotQueued` |
| Blacklist | `filter_blacklist()`, applied per chunk after within-chunk dedupe | `testBlacklistedMobilesAreExcludedAndCounted` |
| Empty rows | a row needs BOTH a valid mobile AND non-empty content | `testEmptyRowsAreSkippedNotQueued` |
| UTF-8 / Persian | BOM-aware CSV read, no re-encoding anywhere in the pipeline | `testPersianContentSurvivesTheWholePipelineExactly` (Persian + emoji, byte-for-byte) |
| CSV headers | row 1 is treated as a header IF it does not parse as a mobile number | `testACsvHeaderRowIsDetectedAndSkipped`, `testAFileWithNoHeaderRowStillImportsItsFirstDataRow` |
| Resume/retry | `import_claim_insert_chunk()`'s expired-lease reclaim, identical in shape to `bulk_claim_items()` | `testAnInsertChunkWithAnExpiredLeaseIsReclaimedAndCompletes` |
| Cancellation | `bulk_item_preflight()` re-checks job status fresh, right before dispatch — the same guard every bulk send already relies on | `testCancellingTheBulkJobStopsFurtherSending` |
| Tenant isolation | `import_load_job()` requires a matching `organization_id`; a bulk claim scoped to another org's id returns nothing | `testAnImportJobIsInvisibleToAnotherOrganization`, `testBulkItemsFromOneOrgsImportAreNotVisibleToAnother` |
| A failed chunk fails the whole job and releases money | `import_job_check_insert_completion()`'s failed-chunk branch releases both wallet and quota | `testAFailedInsertChunkFailsTheWholeJobAndReleasesReservations` |
| Selected sender is honored, not silently dropped | `ellsms_import_jobs.originator` (bug #5 above) | `testOriginatorSurvivesFromJobCreationThroughToTheStagedBulkJob` |

## Cost preview / confirmation flow (10.3)

No SMS is ever sent before the user confirms. The bulk job is created with `status='staged'`
(`import_create_bulk_job()`) and only promoted to `'pending'` — the status `run_bulk_send_pass()`
actually claims from — by an explicit confirmation action in the UI. Between
`ready_for_confirmation` and that confirmation, `ellsms_import_jobs` already carries everything the
summary screen needs: `valid_rows`, `invalid_rows`, `duplicate_rows`, `blacklisted_rows`,
`priced_rows`, `unpriced_rows`, `estimated_cost_credits` — all populated by the passes above, all
read directly rather than recomputed. Pricing is never client-supplied: both the per-chunk price
during pass 1 and the exact total during reserve/stage come from `sms_pricing_price_messages()`
resolved server-side at one fixed instant (`analysis_started_at`), and pass 2 prices each row again
at that SAME instant — so the amount reserved, the amount shown to the user, and the amount actually
charged per row can never drift apart.

## Known follow-up (not a Phase 10 blocker)

**`ellsms_import_dedupe` rows are never cleaned up for a failed job.** Not a correctness issue —
`import_job_run_analysis()`'s guard (`status !== 'analyzing'`) prevents a failed job from ever being
re-analyzed, so there is no risk of reprocessing stale dedupe rows — but it is a storage-hygiene gap
worth a retention sweep alongside the export-file retention job (`docs/sms-load-testing.md`) if failed
imports accumulate in practice.

**Chunk count vs. per-row DB round trips.** At `IMPORT_CHUNK_SIZE=100`, a 10,000-row import performs
~100 analyze-chunk passes and a similar number of insert-chunk passes, each with its own pricing
call and chunk-completion UPDATE. The 10k-row integration test took several minutes end to end on
this test server. Production deployments should size `IMPORT_CHUNK_SIZE` and `DB_INSERT_BATCH`
larger (see `docs/production-tuning.md`) to trade memory headroom for fewer round trips — this is a
tuning question, not a bounded-memory violation; the per-chunk footprint stays flat either way.
