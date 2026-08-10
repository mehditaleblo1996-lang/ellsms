# ELLSMS — Pre-Send Cost Preview

Shows what a send will cost *before* it happens: how many recipients are actually eligible, how many
SMS segments the message really becomes, the estimated credit cost, the wallet balance before and
after, and whether the plan's quota allows it.

**A preview is read-only.** It reserves nothing, debits nothing, queues nothing, consumes no quota,
and creates no message. It is an estimate, never a financial commitment.

## Why this exists

`sms_parts()` has always been the codebase's segmentation rule, but nothing surfaced it before a
send. The practical consequence: **a Persian message is UCS-2, so one SMS is 70 characters, not
160.** A ~98-character Persian message is two SMS, and a 150-character one is three. Users routinely
assumed "one recipient = one SMS" and were surprised by the charge. The preview makes the real
number visible before anyone commits.

## Pricing source of truth

```
cost (in CREDITS) = Σ over recipients of ceil(segments × unit_price_millicredits / 1000)
```

The unit price is resolved per recipient from the admin-configured operator/route tariff catalog by
`sms_pricing_price_messages()` — **the same function** `dispatch_message()`,
`dispatch_message_retryable()` and `bulk_queue_job()` call before they reserve from the wallet. That
shared call is what makes preview↔send parity true by construction rather than by convention. See
**[docs/sms-pricing.md](sms-pricing.md)** for the full model.

- The wallet ledger is denominated in credits (Phase 3); a tariff is stored in integer millicredits
  (1 credit = 1000) and becomes credits once per message.
- `rial_per_credit` (Settings → `ellsms_settings`) is the Rial value of one credit, used **only** to
  render a human-readable Rial figure beside the credit figure. It is never part of wallet
  arithmetic. It is reported separately as `rial_currency: "IRR"`, while `currency` is the unit the
  cost itself is in: `credit`.
- **Platform admins are exempt** — `dispatch_message()` charges them nothing, so the preview shows
  zero cost for them rather than a figure that would never be debited. An exempt preview also never
  fails closed on an unpriceable recipient: the answer is zero either way.

### This used to say "there is deliberately no per-operator pricing"

That was true, and is no longer. Pricing is now admin-configured per operator/provider/route, so a
preview returns a `pricing.groups` breakdown. Two consequences worth stating explicitly:

- `credits_per_segment` is populated **only when every priced recipient shares one unit price** —
  the common case, and always true on a legacy-parity install. When operators genuinely differ in
  rate it is `null`, and `unit_price_min_millicredits` / `unit_price_max_millicredits` plus `groups`
  carry the truth. A single averaged "unit price" over a mixed-rate send would be a number no
  customer is ever charged.
- A preview **fails closed** if any recipient cannot be priced: `ok = false`,
  `reason = "pricing_unavailable"`, plus a `pricing_failure` block with priced/unpriced counts and
  per-reason totals so the UI can show "10,000 input / 9,850 priced / 150 unpriced" and refuse to
  offer a confirm button.

## Segmentation

| Encoding | Single SMS | Each part when concatenated |
|---|---|---|
| GSM-7 (plain ASCII) | 160 chars | 153 chars |
| Unicode / UCS-2 (any Persian, emoji, or non-ASCII) | 70 chars | 67 chars |

A single non-ASCII character forces the **whole** message to Unicode — 100 ASCII characters is one
segment, but adding one Persian character makes those 101 characters two segments.

The estimator never computes segment counts itself: `cost_estimate_segments()` calls `sms_parts()`
and only adds descriptive fields around that number (encoding label, character count, remaining room
in the current segment). A unit test asserts the two can never disagree.

## Personalized messages

For bulk sends where each recipient gets a differently-rendered body, segment counts genuinely
differ per recipient, so the total is a **sum, not a multiplication**. The preview returns a
distribution:

```
1 segment:  8,422 recipients
2 segments: 1,104 recipients
3 segments:     18 recipients
Total billable segments: 10,684
```

Callers hand `estimate_bulk_cost()` the already-rendered items — the identical
`[['mobile'=>…, 'content'=>…]]` shape `bulk_queue_job()` receives — so the estimator prices the exact
strings that will be queued, rather than re-rendering through a second code path that could drift.

### Large sets

Above `COST_PREVIEW_EXACT_RECIPIENT_LIMIT` resolved items (default 20,000), counting every recipient
individually becomes expensive. The estimate then samples that many items, derives the mean, scales
it, and is returned with `segments.exact = false` — the UI and API both say the number is an
estimate rather than presenting an approximation as fact. Below the ceiling the result is exact.

## Recipient eligibility

Run through the **same** pipeline a real send uses, with each stage reported separately:

| Stage | Reported as |
|---|---|
| Raw tokens submitted | `input_count` |
| Failed `normalize_msisdn()` | `invalid_count` |
| Same number more than once | `duplicate_count` |
| On the sender's blacklist | `blacklisted_count` |
| Bulk item with an empty body | `empty_content_count` |
| **Actually sendable** | `eligible_count` |

Nothing is mutated: the blacklist lookup is a `SELECT`, and no contact or suppression record is
written or removed.

## Wallet preview

Read from `wallet_balance()` — the Phase 3 ledger — never from the legacy `user_.currentcredit`
projection. Returns `balance`, `estimated_cost`, `estimated_remaining`, `sufficient`.

`sufficient: true` is a **point-in-time observation, not a guarantee**. See "Races" below.

## Quota preview

Read from Phase 13's meters via `organization_usage()`. Consumes nothing.

Note the units genuinely differ and are not interchangeable:

- **Cost** is counted in **segments** (a 3-segment message to 1 recipient costs 3 credits).
- **Quota** is counted in **messages** (that same send consumes 1 message of the allowance).

This mirrors how `usage_reserve_messages()` is actually called from the send paths, so the preview
matches what will really be reserved.

When `BILLING_ENABLED=0`, the preview reports `quota.enforced = false`.

## Preview vs. final send

The preview is advisory. **Every value is recomputed server-side at confirmation**, and the real
send performs its wallet reservation and quota reservation atomically under a row lock — exactly as
it did before this feature existed.

| | Web UI | Public API |
|---|---|---|
| Cost changed since preview | Re-shows the preview with current numbers and asks again | Proceeds on current authoritative values |
| Preview older than TTL | Requires re-confirmation | N/A (each call is independent) |

The web threshold is `COST_PREVIEW_RECONFIRM_PERCENT` (default 5%) and the TTL is
`COST_PREVIEW_TTL_SECONDS` (default 300). A large *decrease* triggers re-confirmation too — it
usually means the recipient set changed.

**Client-supplied cost is never trusted anywhere.** The hidden `previewed_cost` field the web form
carries is used solely to detect drift; it is never the price. The API ignores any
`estimated_cost` / `unit_price` / `segments` in the request body entirely — a dedicated test asserts
that a tampered request produces the identical computed cost.

The optional preview fingerprint (`cost_preview_fingerprint()`) is a **change detector only** — never
authorization and never a financial commitment. A forged one buys nothing, because the server
recomputes regardless.

## Races

A preview that said "sufficient" can never force a send through. Both cases are covered by tests:

**Balance race** — balance 100, preview an 80-credit send (sufficient), something else spends 50,
user confirms → the send fails with an insufficient-credit message, the balance stays at 50, the
wallet never goes negative, no reservation is left dangling, and **no ledger entry is written at
all** — not even a partial one.

**Quota race** — allowance 100, preview an 80-message send (sufficient), something else consumes 50,
user confirms → the send is refused before reserving any money or dispatching anything; only the
concurrent operation's usage is recorded. An over-quota bulk job rolls back its own job row entirely
rather than being left queued.

## Surfaces

| Surface | Entry point |
|---|---|
| Direct send | `public/send.php` — "محاسبه‌ی هزینه و ادامه" |
| Combined send panel (direct / recurring / gradual) | `public/new-send.php` — same button |
| API, single message | `POST /api/v1/messages/preview` (scope `messages:send`) |
| API, bulk | `POST /api/v1/bulk-jobs/preview` (scope `bulk:write`) |

Both web pages keep an "ارسال بدون پیش‌نمایش" (send without preview) button, so nothing that worked
before now requires an extra step.

Scheduled sends are previewed too — the preview is evaluated *before* the immediate/scheduled split,
so choosing "schedule" and clicking preview shows the cost rather than silently creating the
schedule. The card states that the final cost is revalidated at execution time.

### API scopes

Preview reuses `messages:send` / `bulk:write` rather than introducing a new scope. A preview reveals
send capability, pricing, wallet balance and quota, so a key that could not send must not be able to
preview. A read-only key gets `403`.

## API response shape

```json
{
  "data": {
    "kind": "message",
    "originator": "5000",
    "recipients": { "input_count": 3, "invalid_count": 1, "duplicate_count": 0,
                    "blacklisted_count": 0, "eligible_count": 2 },
    "message":  { "encoding": "unicode", "characters": 150, "segments": 3,
                  "concatenated": true, "single_segment_limit": 70,
                  "concatenated_segment_limit": 67, "characters_remaining_in_segment": 51 },
    "segments": { "per_recipient": 3, "total": 6, "distribution": {"3": 2}, "exact": true },
    "pricing":  { "unit": "credit_per_segment", "credits_per_segment": 1,
                  "unit_price_millicredits": 1000,
                  "unit_price_min_millicredits": 1000, "unit_price_max_millicredits": 1000,
                  "price_source": "route_default", "message_type": "promotional",
                  "estimated_cost": 6,
                  "rial_per_credit": 1000, "currency": "credit", "rial_currency": "IRR",
                  "groups": [ { "operator": "mci", "operator_name": "همراه اول",
                                "provider": "legacy", "route": "default",
                                "message_type": "promotional", "recipients": 2, "segments": 6,
                                "unit_price": 1, "unit_price_millicredits": 1000,
                                "price_source": "route_default", "cost": 6 } ],
                  "estimator_version": "2" },
    "wallet":   { "balance": 50000, "reserved": 0, "estimated_cost": 6,
                  "estimated_remaining": 49994, "sufficient": true },
    "quota":    { "enforced": false, "estimated_usage": 2, "sufficient": true },
    "notes":    { "estimate_only": true, "revalidated_at_send": true,
                  "price_mode": "estimated", "ttl_seconds": 300 }
  }
}
```

Errors use the existing stable API error model: `422 validation_failed` (with `fields`) for an
invalid sender, empty content, empty recipient list, or all-ineligible recipients; `403 forbidden`
for a missing scope; `403 feature_not_available` when the plan excludes bulk sending.

## Security

- A sender belonging to another organization cannot be priced — the estimator defers to
  `can_use_originator()`, the same choke-point rule the send path enforces.
- A foreign campaign is indistinguishable from a missing one (`campaign_not_found`).
- The wallet figure is always the acting principal's own; another user's balance cannot leak in.
- Preview never logs message content or the recipient list — only counts, the sender, and the
  result (`cost_preview_record()`).
- Metrics use low-cardinality labels only (surface + result); never an organization name or a cost
  value as a label.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `COST_PREVIEW_TTL_SECONDS` | `300` | How long a web preview stays valid before re-confirmation |
| `COST_PREVIEW_RECONFIRM_PERCENT` | `5` | Cost drift (%) that forces re-confirmation |
| `COST_PREVIEW_EXACT_RECIPIENT_LIMIT` | `20000` | Above this, bulk segment counting is sampled and labelled inexact |

All three have safe defaults; none is required, and none changes existing send behavior.

## Limitations

- **XLSX-upload bulk pages (`p2p-send.php`, `smart-send.php`) have no web preview.** Those flows
  parse an uploaded file and queue in one request; a preview would require persisting the parsed
  rows across a second request, which for large files means either session bloat or a re-upload.
  Programmatic callers get full bulk preview via `POST /api/v1/bulk-jobs/preview`, and the
  textarea/contacts-driven bulk path (`new-send.php` gradual mode) is previewable. Adding it to the
  upload flows needs a deliberate decision about where parsed rows live between the two requests.
- **Campaign preview prices a body against a caller-supplied audience.** A saved campaign in this
  product is a stored sender+body template with no audience of its own, so there is no "campaign
  audience count" to report — that concept does not exist in the schema and was not invented here.
- **The Rial figure is display-only** and is omitted entirely if `ellsms_settings` is unreachable;
  the credit cost — the number that actually matters — is always computed.
- **A client-side character counter duplicates the segmentation rule.** `public/send.php` and
  `public/new-send.php` each carry a small JavaScript live counter (pre-dating this feature) that
  reimplements the 70/67/160/153 thresholds and the same `[^\x20-\x7E\r\n]` Unicode test for
  instant feedback while typing. It is *currently exactly equivalent* to `sms_parts()`, and it was
  deliberately left in place: a server round-trip per keystroke would be worse, and the number it
  shows is only a typing hint. **The authoritative figure is always the server-computed preview**,
  which is what the confirmation card displays and what the send actually charges. This is a real
  drift risk worth knowing about — if the segmentation thresholds ever change, those two JS blocks
  must change with `sms_parts()`.
- **No preview for auto-reply**, which is triggered by inbound messages rather than composed by a
  user, so there is no pre-send moment to preview.
