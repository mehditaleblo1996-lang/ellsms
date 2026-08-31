# Six-month admin-approved archive workflow (issue #13)

## Agreed decision

`docs/database-audit.md` flagged `ellsms_bulk_items` as one of four ELLSMS-owned tables that grow
without bound and are never pruned, and left it explicitly "permanent by policy" pending a real
retention decision. This is that decision: raw items past a cutoff move to cold storage on a
six-month cycle, and only with admin coordination and approval — **never** an automatic silent
purge, and never a destructive delete with no way back.

Only `ellsms_bulk_items` is archived. `ellsms_bulk_jobs` is small (one row per campaign, not per
recipient) and stays in place, so an archived item's `job_id` always still resolves to its job.
`outbound_message`/`inbound_message` (backend-owned) are out of scope entirely — ELLSMS has no write
access to them (Invariant E, `docs/service-boundaries.md`), so it cannot archive or delete from them
regardless of age.

## Workflow

1. **Preview** (`bulk_archive_preview()`) — read-only, computed fresh every call: exact count and
   date range of items eligible right now for a given cutoff date. Only `sent`/`failed`/`cancelled`
   items are ever eligible — a `pending` item is live work and is never swept, no matter its age.
2. **Request** (`bulk_archive_request()`) — records the previewed scope as a new run
   (`ellsms_bulk_archive_runs`, `status = 'pending_approval'`). Requesting a run is deliberately
   **not** enough to start archiving anything.
3. **Approve** (`bulk_archive_approve()`, admin only) — a separate, explicit action that moves the
   run to `approved`. Only an `approved`/`running` run's chunks may ever execute; every other status
   is refused.
4. **Execute** (`cron/bulk-archive-worker.php`) — a persistent worker advances every
   `approved`/`running` run by bounded, chunked passes (`app/BulkArchive.php`'s
   `bulk_archive_run_worker_pass()`/`bulk_archive_run_chunk()`), the same high-water-mark-in-one-
   transaction-per-chunk shape as issue #12's report aggregation: each chunk's archive-insert,
   live-delete, and high-water-mark advance commit together or not at all
   (`BulkArchivePartialFailureTest` proves this with a real forced lock-timeout failure). The run
   flips to `completed` once no eligible rows remain.
5. **Admin UI** (`public/bulk-archive.php`) — preview + request form, run history with live status,
   approve/cancel buttons, and a restore action per completed run.

## Why this stays safe under load

- **Never one long scan/lock**: chunk size defaults to 2000 rows (`BULK_ARCHIVE_CHUNK_ROWS`),
  floored at 100 — a large run advances in many short, independent transactions rather than one
  table-wide operation.
- **Resumable**: a crash or restart mid-run picks back up from `last_archived_item_id`, the same
  pattern `report_summary_cache`/`report_dimension_summary` already use.
- **Idempotent**: the archive insert is `ON DUPLICATE KEY UPDATE id = id` keyed on the item's own
  original id — re-archiving the same row twice (a retried chunk, an already-completed run's chunk
  called again) is a harmless no-op, never a duplicate or an error.
- **Aggregated daily metadata is unaffected**: `ellsms_report_daily_dimension_summary` (issue #12) is
  a separate table already fully computed from the raw rows before they age past six months in any
  realistic deployment; archiving raw items never touches it
  (`BulkArchiveTest::testAggregatedDailyDimensionSummaryIsUnaffectedByArchivingRawItems`).

## Restore / retrieval procedure (documented and tested)

Archived rows are never deleted outright — `ellsms_bulk_items_archive` keeps the full original row
as JSON (`payload`), keyed by the item's own original id (not a fresh autoincrement), plus the
`job_id`/`status`/`created_at` it had and which run archived it.

**To restore:**

```php
// Everything a specific archive run moved:
bulk_archive_restore($adminActor, $runId);

// Only one job's items from that run (e.g. a single campaign a customer asks about):
bulk_archive_restore($adminActor, $runId, $jobId);
```

Or from the admin page (`/bulk-archive.php`): the "بازگردانی" (Restore) button on a `completed` run,
with an optional job id field to scope it to one campaign.

Mechanically: for each archived row, the JSON payload is intersected with whatever columns
`ellsms_bulk_items` has **today** (read from `information_schema`, not hardcoded — a later migration
adding/removing a column is picked up automatically), then re-inserted
(`ON DUPLICATE KEY UPDATE id = id`, so restoring twice is also a safe no-op) and removed from the
archive. Restore is admin-only and audited exactly like every other step.

**Tested**: `BulkArchiveTest::testRestoreMovesArchivedRowsBackWithFullOriginalData` — a full
archive-then-restore round trip asserting the restored row's data (mobile, status, everything)
matches the original exactly, and that the archive copy is gone afterward (restore moves, it does
not copy).

## Audit trail

Every step writes one `ellsms_audit_log` row (`bulk_archive.requested` / `.approved` /
`.approve_forbidden` / `.approve_failed` / `.cancelled` / `.restored` / `.restore_forbidden` /
`.restore_empty`), recording the actor, the run id, and step-specific details (cutoff date, preview
count, reason, job id, restored count) —
`BulkArchiveTest::testAuditLogRecordsRequestApprovalAndResult` proves this end to end. A failed run
also records `error_message` on the run row itself and logs `bulk_archive.run_failed`.

## Deliberately out of scope

- **Automatic scheduling** — there is no cron trigger that calls `bulk_archive_request()` on its own
  six-month timer. An admin (or a future scheduled job someone explicitly wires up) always initiates
  the request; the point of this issue is that the cycle runs "only with admin coordination/approval,"
  not that it becomes any more automatic than today.
- **Archiving anything other than `ellsms_bulk_items`** — `ellsms_audit_log`/`ellsms_autoreply_log`
  remain permanent by policy per `docs/database-migrations.md`'s Data lifecycle section; this issue
  did not revisit that decision.
