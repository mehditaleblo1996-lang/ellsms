# ELLSMS — Scale Continuation Final Report

## Provider Batching Completion, Large Import Pipeline Repair & Production-Readiness Documentation

## 0. A note on phase numbering

**"Phase 9A", "Phase 9B", "Phase 9C", "Phase 10" and "Phase 11" in this document, and in the git
commits this session produced, are LOCAL labels used only within this scalability-continuation
session.** They continue a session-local sequence that began with this session's own Phase 9A
(provider batching) and 9B (load testing) work.

**They are unrelated to this repository's own pre-existing, canonical, historical
`docs/phase-N-final-report.md` series**, which independently runs from Phase 1 (large-scale import
schema) through Phase 13 (SaaS plans/subscriptions/entitlements) and documents a different body of
work entirely (observability/queue-scaling decision, production hardening/security closure, backup/
DR, public API/webhooks, billing). That series is untouched and unrenumbered by this document. Where
this report needs to reference the repository's real historical phases, it names them explicitly
(e.g. "the real Phase 9" or cites `docs/phase-9-final-report.md` by filename) to avoid ambiguity.

This document itself follows the existing `docs/phase-N-final-report.md` closure-report convention
(structure, `DECISION:` callouts, Files Created/Modified, Full Test Results) without claiming to be
part of that numbered series — hence the non-numeric filename.

## 1. Executive Summary

This continuation closed out the ManyToMany provider-batching work (session-local Phase 9C),
performed a full production-readiness regression pass (9B commit was already green; further
regression work is session-local Phase 10), and produced the production-facing documentation this
work required (session-local Phase 11).

**Phase 9C** made P2P and Smart-send batching connector-capability-driven rather than
content-shape-driven: a connector whose compiled parameters reference `messages_array` now batches
different-content rows into one provider request exactly like same-content bulk rows already did,
while a connector that doesn't stays exactly as the earlier bulk-batching work left it. A generic,
connector-driven per-message idempotency mechanism (`idempotency_keys_array`, deterministic per
`bulk_item.id`) was added to narrow — not close — the pre-existing at-least-once delivery window;
whether it actually suppresses a duplicate SMS depends on provider support this project cannot
verify universally. 17 new integration tests, 0 regressions.

**Regression work (session-local Phase 10)** ran the full test suite against a completely fresh
database and, in doing so, found that **the large-file import pipeline had zero integration test
coverage and could not complete a single import end to end on a schema built from the committed
migrations.** Five real, pre-existing production bugs were found and fixed (§6) — none introduced by
this session's own changes. 15 new integration tests (14 pipeline behavior tests plus one added
specifically to regression-guard the most severe of the five bugs), 0 regressions.

**Documentation work (session-local Phase 11)** wrote the previously-referenced-but-never-created
`docs/large-import-architecture.md`, added the operator-relevant production runbook sections this
work required (worker roles, large-import operational behavior, mock-gateway safety, provider
batching knobs, benchmark/tuning procedure) to the existing `docs/production-runbook.md`, and added
one dated technical-debt entry for the genuinely still-open items — narrowed-not-closed at-least-once
delivery and the per-item settlement throughput ceiling, both deliberately deferred rather than fixed
here per this continuation's own governing instructions.

**Final gate: full fresh-database integration suite — 658 tests, 6194 assertions, 0 failures, 0
errors.** Full breakdown in §8.

## 2. Session-local Phase 9C — Generic ManyToMany batching

Full design: `docs/many-to-many-batching.md`.

### 2.1 The defect this closed

`docs/bulk-provider-batching.md` (session-local Phase 9A) had already made same-content bulk sends
batch into one provider request per `SMS_PROVIDER_BATCH_SIZE` recipients. P2P and Smart-send rows —
where every recipient legitimately has *different* content — were explicitly out of that phase's
scope and continued sending one HTTP request per recipient regardless of connector capability.

### 2.2 The fix

- `gateway_connector_supports_per_recipient_content()` scans a connector's compiled parameters for a
  reference to the new `messages_array` context variable (destination-keyed, built from
  `$input['messages']`), exactly the same pattern already used for `recipients_array`.
- `bulk_group_key()` now only includes `content` in its grouping hash when the connector does **not**
  support per-recipient content — so a capable connector groups purely by originator/message-type/
  route, letting different-content rows share one request; an incapable connector keeps splitting by
  content exactly as before.
- `bulk_send_group()` builds a destination-keyed `$perDestinationContent` map (not a positional array)
  before dispatch specifically to avoid the index-alignment bug class found earlier in this session's
  own history (the Phase 9B `mock_reference()` bug).

### 2.3 A design bug introduced and self-corrected during this work

The first draft of `bulk_connector_supports_per_recipient_content()` used a `static $memo` keyed only
by originator, with no invalidation. `testAPlainConnectorStillSplitsByContent` caught it: a stale
`true` leaked from an earlier test that had used the same sender against a capable gateway. Removed
the memoization entirely — this project's established pattern (`gateway_compiled()`,
`gateway_parameter_signature()`) always keys and invalidates its caches centrally
(`gateway_cache_reset()`); an ad hoc uninvalidated memo violated that pattern and was wrong to add.
Documented in the function's own docblock.

### 2.4 At-least-once delivery — narrowed, not closed

**DECISION: add a generic connector-driven idempotency mechanism rather than attempt to guarantee
exactly-once delivery.** Exactly-once delivery across a provider crash-and-retry window cannot be
guaranteed by this project alone — it depends on the provider accepting and honoring a per-message
idempotency token, which is a fact about each configured gateway, not something ELLSMS can enforce
universally. Investigated whether generic per-message idempotency could be supported through the
existing connector/configuration model: **yes** — `idempotency_keys_array`, positionally aligned with
`recipients_array`/`messages_array`, deterministic (`'ellsms:bulk_item:' . $item['id']`, not the
existing `value_type: 'uuid'` parameter type, which regenerates a fresh value on every resolve call
and would make a retry carry a different key). Implemented generically, wired only when a connector's
compiled parameters actually reference it, and tested
(`testIdempotencyKeysArrayCarriesADeterministicPerRowToken`,
`testIdempotencyKeyIsStableAcrossARetryOfTheSameRow`,
`testAConnectorWithoutIdempotencySupportIsUnaffected`).

This **narrows** the pre-existing at-least-once risk (documented as residual in
`docs/bulk-provider-batching.md` since session-local Phase 9A) rather than closing it: whether a
specific provider actually suppresses the duplicate depends on that provider's own API and dedup
window, which this project cannot verify across every configured gateway. Money is protected
unconditionally either way — `wallet_commit_reservation()`'s per-item key makes a replayed settlement
a no-op regardless of provider behavior. Full account:
`docs/many-to-many-batching.md#at-least-once-delivery`.

### 2.5 Settlement performance — explicitly not touched

Per this continuation's own governing instructions, per-item settlement performance (the ~18–30
ms/recipient database cost documented in `docs/sms-load-testing.md`, unrelated to provider batching)
was explicitly **not** optimized in this phase. It is acceptable on the test server used for this
work and is documented for production benchmarking (§7) rather than tuned against test-server
characteristics.

### 2.6 Test results

`tests/Integration/BulkManyToManyBatchingTest.php` — **17 tests, 64 assertions**, all passing.
Covers: batching at scale (200 rows → 1 request; 450 rows → 200+200+50), capability gate holding both
directions, hostile content (Persian/quotes/newlines/emoji) surviving byte-for-byte, positional
correlation and fail-closed count-mismatch handling with per-row content, partial-failure isolation,
long provider references, two-worker concurrency safety, gradual-throttle interaction, per-row money
settlement even within a shared request, and the idempotency-key mechanism.

## 3. Session-local Phase 10 — Production-readiness regression, and what it found

The full test suite was run against a **completely fresh database** (dropped and rebuilt from every
committed migration in filename order) specifically because this is the only way to prove the system
works for a real new deployment, not merely for a database that has accumulated ad hoc manual state
during development. Running it this way surfaced five real, pre-existing bugs — see §6 for detail.

Every one of the five predates this continuation session's own changes. None were introduced by
session-local Phase 9A/9B/9C. Writing the first integration tests the large-import pipeline has ever
had (`tests/Integration/LargeImportPipelineTest.php`, 15 tests) is what surfaced them — they were
undiscovered specifically because zero test coverage existed for this pipeline before this session.

## 4. Session-local Phase 11 — Production-readiness documentation

Per explicit governing instruction for this continuation: this repository already has an established
documentation convention (`docs/phase-N-final-report.md` per historical phase,
`docs/technical-debt.md` as the roadmap/debt register, `docs/production-runbook.md` as the operator
runbook) and has never used root-level `STATE.md`/`ROADMAP.md`/`DECISIONS.md`/`CHANGELOG.md`/
`RUNBOOK.md` files. **No such root-level files were created.** Instead:

- **`docs/large-import-architecture.md`** (new) — full two-pass pipeline architecture, all five bugs
  found (§6), bounded-memory verification, and an acceptance-item-to-test mapping table.
- **`docs/technical-debt.md`** — one new dated section ("Scale continuation — provider batching,
  ManyToMany, large import, 2026-08-24") listing only the genuinely still-open items: at-least-once
  delivery (narrowed, not closed), per-item settlement throughput (deferred by design), the large
  import pipeline's five now-closed bugs (recorded for audit trail, explicitly not re-listed as open
  debt), and the `ellsms_import_dedupe` retention gap for failed jobs.
- **`docs/production-runbook.md`** — five new operator-relevant sections (§11–§15): worker container
  roles, large-import operational behavior and tunables, mock-gateway production-safety checklist,
  P2P/ManyToMany provider-batching operational summary, and a production benchmarking/tuning
  procedure that explicitly flags the 500k/1m figures in `docs/sms-load-testing.md` as extrapolated
  estimates, never measured results.
- **This report** — the closure narrative for this continuation, in the existing report style.

No historical `docs/phase-N-final-report.md` was rewritten, renumbered, or otherwise modified.

## 5. DECISION: keep the mock gateway as a separate, off-by-default container

Restated rather than re-litigated: the mock SMS gateway (`mock/gateway.php`,
`docker-compose.yml`'s `mock-sms-gateway` service) remains a development/load-testing-only fake
provider — no external egress, `ELLSMS_MOCK_GATEWAY_ENABLED` defaults to `0` everywhere, and the load
harness itself refuses to run against a non-test-named database without an explicit override. This
continuation added no new production code path to it and made no change to that isolation; §13 of the
runbook now documents the existing safety properties for operators rather than introducing new ones.

## 6. The five real bugs found in the large-import pipeline

All in `app/import.php` / `app/import_worker.php` / `app/bootstrap.php`; full detail in
`docs/large-import-architecture.md`. Summary:

1. **`import_job_check_insert_completion()` chained `->execute([...])->fetchColumn()`.**
   `PDOStatement::execute()` returns `bool`, not `$this` — every call threw, silently caught, and
   marked the chunk `'failed'`. Every import failed at the very last step, after correctly analyzing,
   deduping, blacklist-filtering, and pricing every row. Fixed: three separate `prepare()`/`execute()`
   pairs.
2. **`import_create_job()`'s closure never captured `$template`/`$variableHeaders`** — both function
   parameters, omitted from `use (...)`. Smart-send's per-recipient template was silently discarded
   as `NULL` at creation. Fixed by adding both to the closure's `use` list.
3. **`ellsms_import_jobs.message_type` is `NOT NULL`, but every real caller passes/omits `null`.** A
   column default only applies when the column is omitted, never when `NULL` is bound explicitly —
   every web-UI import failed at creation with a constraint violation, never reaching bug #1. Fixed
   with `sms_pricing_normalize_message_type($messageType)`.
4. **`import_claim_uploaded_job()` referenced `ellsms_import_jobs.claimed_by`/`claimed_at`, which no
   migration had ever created** (only `ellsms_import_chunks` got that column pair). On any database
   built from the committed migrations, the claim failed outright with `Unknown column` — pass 1
   could never start, for any caller, ever. Fixed by
   `db/migrations/2026_08_24_import_job_claim_columns.sql`.
5. **`import_create_job()` accepted `$originator` as a parameter but never wrote it to the
   `ellsms_import_jobs` INSERT, and no migration had created that column either.** Every downstream
   read of `$job['originator']` — pass 1's per-chunk pricing, reserve/stage's exact-total repricing,
   `import_create_bulk_job()`, and pass 2's per-chunk pricing — silently evaluated to `''`. Pricing
   fell through to the tenant's default route instead of the sender the user actually selected, and
   — more severely — the confirmed bulk job itself was created with `originator=''`, meaning a
   completed import's send would have gone out from no sender at all. Fixed by
   `db/migrations/2026_08_24_import_job_originator_column.sql` plus persisting `$originator` in
   `import_create_job()`'s INSERT.

A sixth, structural fix (not a bug in the traditional sense): **`app/import_worker.php` was never
`require`'d from `app/bootstrap.php`**, only from `cron/import-worker.php` directly — so every
function it defines was unreachable from anywhere else in the application, including this session's
own test suite, until fixed.

Bug #5 (originator) was found **after** the first fresh-DB integration run had already started,
mid-run, via a `PHP WARNING: Undefined array key "originator"` line surfaced in that run's own log
output — not something the four previously-fixed bugs' tests had caught, since none of them exercised
sender/pricing-route correctness specifically. The in-flight run was killed deliberately (schema was
about to change underneath it), the fix applied, and the full suite re-run clean from a freshly
rebuilt database (§8) — the run whose result is reported as final in this document.

## 7. Production benchmark plan (not executed here)

Per this continuation's explicit governing instruction: **no 500k/1m recipient benchmark was run on
this test server.** Correctness was prioritized over speed on shared test infrastructure. The
`docs/sms-load-testing.md` figures at that scale are extrapolations from the measured 100k run, not
measurements, and are labeled as such in that document.

The plan for a real production benchmark, once a suitable environment is available, is now recorded
in `docs/production-runbook.md` §15: re-run `make sms-load-1k`/`-10k`/`-100k` against the production
environment's own hardware first (test-server numbers do not transfer), only then proceed to
`-500k`/`-1m`, address the identified per-item settlement bottleneck first if production throughput
requirements demand it, and send one small controlled real message through a newly configured
provider gateway before ever pointing full campaign volume at it.

## 8. Full Test Results

- **Unit:** **345 tests, 1421 assertions, 0 failures.**
- **`BulkManyToManyBatchingTest` (session-local Phase 9C):** **17 tests, 64 assertions, 0 failures.**
- **`LargeImportPipelineTest` (session-local Phase 10):** **15 tests, 86 assertions, 0 failures**
  (14 pipeline-behavior tests plus `testOriginatorSurvivesFromJobCreationThroughToTheStagedBulkJob`,
  added specifically to regression-guard bug #5 above).
- **Full integration suite, fresh database, every migration applied in filename order, no filter:**
  **658 tests, 6194 assertions, 0 failures, 0 errors.** (14:15.666 wall time.) This is the number
  that matters most: it includes every Phase 1–13 historical regression suite, every suite this
  continuation added, and was run against a database built the same way a real fresh deployment's
  would be — not one accumulated through ad hoc manual testing during development.
- **PHP lint:** 291 files, clean.
- **Backend boundary check:** PASS — every direct backend-table reference outside the allowlist
  accounted for (test fixtures only, as already established by every prior phase).

**No 500,000 or 1,000,000-recipient run was executed.** No figure at that scale in any document this
continuation wrote or touched is a measured result — see §7.

## 9. Files Created

Migrations: `db/migrations/2026_08_24_import_job_claim_columns.sql`,
`db/migrations/2026_08_24_import_job_originator_column.sql`. Tests:
`tests/Integration/LargeImportPipelineTest.php` (session-local Phase 10, 15 tests). Docs:
`docs/large-import-architecture.md`, `docs/scale-continuation-final-report.md` (this document).

(`tests/Integration/BulkManyToManyBatchingTest.php`, `app/Sms/GatewayConnector.php`'s and
`app/Sms/GatewayTransport.php`'s Phase 9C additions, and `docs/many-to-many-batching.md` were created
and committed earlier in this same continuation, prior to this report.)

## 10. Files Modified

`app/import.php` (persist `$originator`; normalize `$messageType`; closure `use` fix — the last two
committed together with bug #3/#2's fixes), `app/import_worker.php` (three `execute()`/`fetchColumn()`
chain fixes), `app/bootstrap.php` (require `import_worker.php`), `docs/technical-debt.md` (one new
dated section), `docs/production-runbook.md` (five new sections, §11–§15).

(`app/backend.php`, `docs/bulk-provider-batching.md` were modified earlier in this same continuation
for session-local Phase 9C and are already committed.)

## 11. Breaking Changes

**None.** Every fix in §6 corrects behavior that was already broken (a large import could not
complete on a fresh database at all) rather than changing behavior that worked. The two new migration
columns are additive (`claimed_by`/`claimed_at` nullable, `originator` `NOT NULL DEFAULT ''` — the
same default the broken code implicitly produced, so an existing row from before this fix reads
identically to how it already behaved). Phase 9C's new context variables
(`messages_array`, `idempotency_keys_array`) are additive to the allowlist and are inert for any
connector that does not reference them.

## 12. Rollback Considerations

- **Session-local Phase 9C:** a connector that does not reference `messages_array` or
  `idempotency_keys_array` in its compiled parameters is entirely unaffected — no operator action
  needed to "roll back" per-connector behavior. There is no feature flag to disable, because the
  mechanism only activates per-connector-configuration, never globally.
- **Session-local Phase 10 migrations:** both are purely additive column adds, guarded and rerun-safe.
  Dropping `ellsms_import_jobs.originator` or `claimed_by`/`claimed_at` after this fix ships would
  reintroduce bugs #4/#5 — do not drop; prefer a forward fix, consistent with this project's existing
  migration-rollback conventions (`docs/production-runbook.md` §6).

## 13. Remaining Risks — disclosed, not hidden

- **At-least-once delivery is narrowed, not closed**, and is fundamentally provider-dependent — see
  §2.4 and `docs/technical-debt.md`.
- **Per-item settlement throughput (~18–30 ms/recipient) is the current ceiling**, deliberately
  unaddressed per this continuation's own governing instructions — see §2.5, §7, and
  `docs/technical-debt.md`.
- **`ellsms_import_dedupe` rows are never cleaned up for a failed import job** — a storage-hygiene
  gap, not a correctness issue (a failed job can never be re-analyzed). See
  `docs/large-import-architecture.md#known-follow-up-not-a-phase-10-blocker`.
- **No 500k/1m benchmark has ever been run against this codebase in its current, Phase 9C-batched
  form.** The plan in §7/`docs/production-runbook.md` §15 exists specifically because this gap
  remains open.

**PRODUCTION READINESS: the code changes in this continuation are complete, tested (658/658 on a
fresh database), and documented.** The condition that cannot be satisfied from this repository alone
is the production benchmark itself (§7) — it requires real production-class hardware and is
explicitly out of scope for the test server used during this work.
