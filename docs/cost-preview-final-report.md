# ELLSMS — Cost Preview: Final Report

Pre-send cost estimation, exact SMS segment counting, and safe send confirmation.

## 1. Executive Summary

Users could not see what a send would cost until after it happened. The consequential gap was
segmentation: **a Persian message is UCS-2, so one SMS is 70 characters, not 160** — a ~98-character
Persian message is two SMS and a 150-character one is three. The prevailing "one recipient = one
SMS" assumption systematically under-estimated cost.

This adds one central, read-only estimator (`app/Cost/MessageCostEstimator.php`) behind every
preview surface — the direct-send page, the combined send panel, and two new API endpoints. It
reports eligible recipients, exact per-recipient segment counts (with a distribution for
personalized bulk), the credit cost, wallet balance before/after, and quota impact.

A preview **mutates nothing**: no reservation, no debit, no job, no message, no quota consumption,
no idempotency lock. It is an estimate, never a commitment — and both the balance race and the quota
race are proven to fail safely at confirmation.

No migration, no breaking change, no new required configuration.

## 2. Invariants

| # | Invariant | Status |
|---|---|---|
| A | Preview never mutates financial state | MET — hard zero-mutation test snapshots 14 counters and asserts identity |
| B | Preview never creates a message/job/send attempt | MET — same test; plus an explicit web-path guard so a preview cannot fall through into `dispatch_message()` |
| C | Preview uses the same pricing logic as the send | MET — parity tests assert the estimate equals `dispatch_message()`'s and `bulk_queue_job()`'s own arithmetic |
| D | Preview uses the same segmentation as the send | MET — the estimator calls `sms_parts()`; a unit test asserts they can never disagree across 15 boundary cases |
| E | Preview is organization-scoped | MET — foreign sender and foreign campaign both rejected; wallet is the acting principal's own |
| F | RBAC / entitlement / sender / quota checked first | MET — web pages gate before the branch; API gates auth → scope → subscription → entitlement |
| G | Preview is an estimate, not a reservation | MET — race tests prove a "sufficient" preview grants nothing |
| H | Actual send revalidates at commit | MET — unchanged existing atomic reserve-under-lock; race tests exercise it |
| I | Client-supplied cost never trusted | MET — API test sends tampered `estimated_cost`/`unit_price`/`segments` and gets the identical computed cost |
| J | Bulk preview needs no queue insertion | MET — the estimator touches no queue table at all |
| K | A preview cannot bypass a later change | MET — web re-confirms on drift/expiry; API always uses current authoritative values |

## 3. Central Estimator

`app/Cost/MessageCostEstimator.php`. Read-only by construction — it calls only `sms_parts()`,
`normalize_msisdn()`, `filter_blacklist()` (SELECT), `can_use_originator()`, `wallet_balance()` and
`organization_usage()`, every one of which the real send path already uses, and none of which
writes. A grep for `INSERT|UPDATE|DELETE|exec(|wallet_reserve|usage_reserve|wallet_debit|audit(`
across the file returns only comments.

Public surface: `estimate_message_cost()`, `estimate_bulk_cost()`, `estimate_campaign_cost()`,
plus `cost_estimate_segments()`, `cost_analyze_recipients()`, `cost_wallet_preview()`,
`cost_quota_preview()`, `cost_validate_sender()`, `cost_pricing_snapshot()`,
`cost_preview_confirmation_check()`, `cost_preview_fingerprint()`, `cost_preview_record()`.

## 4. Segmentation

GSM-7: 160 single / 153 concatenated. Unicode: 70 single / 67 concatenated. A single non-ASCII
character forces the whole message to UCS-2.

The estimator never counts segments itself — `cost_estimate_segments()` delegates to `sms_parts()`
and adds only descriptive fields (encoding, character count, remaining room in the current segment).
Verified across empty, 159/160/161, 306/307, 69/70/71, 134/135, and mixed-script inputs.

## 5. Personalized Messages

Segment counts genuinely differ per recipient, so the bulk total is a **sum, not a multiplication**.
A distribution is returned (`{"1": 8422, "2": 1104, "3": 18}`). Callers pass already-rendered items
in the identical shape `bulk_queue_job()` receives, so the estimator prices the exact strings that
will be queued.

Above `COST_PREVIEW_EXACT_RECIPIENT_LIMIT` (default 20,000) the estimate samples and is returned
with `exact: false`, explicitly labelled rather than presented as precise.

## 6. Pricing Source of Truth

```
cost (in CREDITS) = sms_parts(content) × recipient_count
```

Verbatim what `dispatch_message()`/`dispatch_message_retryable()`/`bulk_queue_job()` already reserve.
The billable unit is **one credit per SMS segment**; the wallet ledger is in credits.
`rial_per_credit` is a display-only conversion, never part of wallet arithmetic, and is omitted
(rather than failing the preview) if `ellsms_settings` is unreachable. Platform admins are exempt,
mirroring `dispatch_message()`.

**No per-sender or per-operator pricing was invented**, because none exists: `ellsms_numbers` has no
price column and `ellsms_pricing_packages` is marketing content for credit bundles. Fabricating an
operator breakdown would have made the preview actively wrong.

## 7. Recipient Eligibility

`input_count`, `invalid_count`, `duplicate_count`, `blacklisted_count`, `empty_content_count`,
`eligible_count` — each reported separately, through the same normalize→dedupe→blacklist pipeline
the send uses. A test asserts the eligible count matches `parse_destinations()` for the same input.

## 8. Wallet & Quota Preview

Wallet from `wallet_balance()` (never `currentcredit`). Quota from `organization_usage()`, consuming
nothing. The units deliberately differ and are not conflated: **cost is segments, quota is
messages** — matching how `usage_reserve_messages()` is actually called.

## 9. Surfaces

| Surface | Entry |
|---|---|
| Direct send | `public/send.php` |
| Combined panel (direct / recurring / gradual) | `public/new-send.php` |
| API single message | `POST /api/v1/messages/preview` (`messages:send`) |
| API bulk | `POST /api/v1/bulk-jobs/preview` (`bulk:write`) |

Both pages keep a "send without preview" action, so nothing that worked before needs an extra step.
Preview is evaluated **before** the immediate/scheduled split, so choosing "schedule" and previewing
shows the cost instead of silently creating the schedule.

Preview reuses existing scopes rather than inventing one — a preview reveals send capability,
pricing, balance and quota, so a key that cannot send must not preview. A read-only key gets `403`.

## 10. Preview vs Final Send

Every value is recomputed server-side at confirmation. Web: re-confirmation is required if cost
drifts beyond `COST_PREVIEW_RECONFIRM_PERCENT` (5%) or the preview exceeds
`COST_PREVIEW_TTL_SECONDS` (300). API: always proceeds on current authoritative values.

The hidden `previewed_cost` field is a **drift detector only**, never the price. The fingerprint is
a change detector, explicitly not authorization.

## 11. Race Results

**Balance race** — balance 100, previewed 80 as sufficient, 50 spent concurrently, confirmed →
send refused, balance still 50, wallet never negative, no dangling reservation, and **zero ledger
rows written** (asserted separately).

**Quota race** — allowance 100, previewed 80 as sufficient, 50 consumed concurrently, confirmed →
refused before reserving money or dispatching; only the concurrent 50 recorded; no reservation left.
An over-quota bulk job rolls its own job row back entirely (proven in a dedicated class running
without an ambient test transaction, since `db_transaction()` deliberately joins rather than nests).

## 12. Security

Foreign sender rejected via `can_use_originator()`; foreign campaign indistinguishable from missing;
wallet always the acting principal's own; client-injected price/segments ignored; responses contain
no file paths, SQL, or secrets; previews log counts and sender only — never message content or the
recipient list; metrics use low-cardinality labels only.

## 13. Test Results

- **PHP lint:** 213 files, clean.
- **Unit:** 277 tests / 650 assertions, 0 failures (24 new in `CostEstimatorTest`).
- **Integration:** **305 tests / 1298 assertions, 0 failures** on a clean database (42 new across 4
  new classes). One earlier run showed a `WalletConcurrencyTest` auto-increment collision, traced to
  leftover rows from this author's own manual smoke-testing of the estimator polluting the shared
  test database — not a product defect. Confirmed by resetting the database and re-running clean.
- **GSM segmentation:** PASS (boundaries 160/161, 306/307).
- **Unicode segmentation:** PASS (boundaries 70/71, 134/135; realistic Persian sentence = 2 segments).
- **Personalized distribution:** PASS (mixed `{1:2, 2:1, 3:2}` = 10 segments vs 5 naive).
- **Pricing calculation:** PASS, including admin exemption and Rial display fallback.
- **Wallet preview:** PASS (ledger-sourced, remaining computed, insufficiency reported not hidden).
- **Quota preview:** PASS (reported without consuming; unenforced when billing is off).
- **Zero-mutation:** PASS — 14 counters identical across every preview surface including failures.
- **Preview/actual parity:** PASS — single-content, bulk, and a controlled 100-recipient
  personalized dataset all match the send path's own arithmetic exactly.
- **Balance race / quota race:** PASS.
- **Cross-tenant:** PASS (sender, campaign, wallet).
- **API preview:** PASS — 14 tests including scope gating, tampered-price rejection, and
  zero-mutation over real HTTP.
- **Container smoke:** PASS — real Apache container, `200` with correct Unicode segmentation
  (75 chars → 2 segments each → 4 total), and **0** ledger rows / **0** messages after.

## 14. Files Created

`app/Cost/MessageCostEstimator.php`, `app/Api/Handlers/Preview.php`, `app/views/cost_preview.php`,
`tests/Unit/CostEstimatorTest.php`, `tests/Integration/CostPreviewTest.php`,
`tests/Integration/CostPreviewRaceTest.php`, `tests/Integration/CostPreviewApiTest.php`,
`tests/Integration/CostPreviewBulkRollbackTest.php`, `docs/cost-preview.md`,
`docs/cost-preview-final-report.md`.

## 15. Files Modified

`app/bootstrap.php` (require the estimator), `public/api/index.php` (two routes, ordered before
`/messages/{id}` so the literal path wins), `public/send.php`, `public/new-send.php`,
`.env.example`, `docs/architecture.md`, `docs/public-api.md`, `docs/production-runbook.md`.

## 16. Breaking Changes

**None.** No migration, no schema change, no new required configuration, and no change to how any
send is priced or charged — the estimator reuses the send path's own arithmetic rather than defining
its own. Both send pages retain their original one-click send.

## 17. Known Limitations

- **XLSX-upload bulk pages (`p2p-send.php`, `smart-send.php`) have no web preview.** Those flows
  parse an uploaded file and queue in one request; previewing needs the parsed rows to survive a
  second request, which means session storage (bloat for large files) or a re-upload. The API bulk
  preview covers programmatic callers, and the textarea/contacts bulk path is previewable. Adding it
  needs a deliberate decision about where parsed rows live between requests.
- **A client-side JS character counter duplicates the segmentation rule** in both send pages. It
  pre-dates this work, is currently exactly equivalent (same regex, same thresholds), and was left
  in place because per-keystroke server round-trips would be worse. The authoritative number is
  always the server-computed preview — but if the thresholds ever change, those two JS blocks must
  change with `sms_parts()`. Disclosed rather than silently accepted.
- **Campaign preview prices a body against a caller-supplied audience** — a saved campaign here is a
  sender+body template with no audience of its own, so no "campaign audience count" exists to report
  and none was invented.
- **No auto-reply preview** — it is inbound-triggered, so there is no pre-send moment.
- **Large personalized sets above 20,000 are sampled**, labelled `exact: false`.

## 18. Configuration

`COST_PREVIEW_TTL_SECONDS` (300), `COST_PREVIEW_RECONFIRM_PERCENT` (5),
`COST_PREVIEW_EXACT_RECIPIENT_LIMIT` (20000) — all optional with safe defaults; none alters existing
send behavior.
