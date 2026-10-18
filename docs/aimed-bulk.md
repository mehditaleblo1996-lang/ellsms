# Aimed bulk: send by postal code from ELLSMS's own number bank

Send one message to the subscribers in a **postal-code area**, a **province** or **city**, a
**number prefix** and/or a **SIM type** (prepaid / postpaid). The filters combine: «postal code 131…,
prepaid, 0912» is one target.

The number bank is Vesal's `TBL_MOBILE`, copied into ELLSMS. This is the difference from
[regional bulk](regional-bulk.md) (#43), which asks Vesal to send and never sees a number. Aimed bulk
sends through ELLSMS's own pipeline: its sender lines, gateways, bulk queue and wallet.

Code: `app/AimedBulk.php`. Page: `/messages/aimed` (`public/aimed-bulk.php`). Import:
`cron/aimed-mobiles-import.php`. Schema: `db/migrations/2026_10_17_aimed_bulk.sql`.

## What to bring from Vesal

Vesal is on Oracle (`PDB_VESAL2`, schema `vesal`). Export these tables as **CSV with a header row,
UTF-8**:

| Vesal table | Needed | Columns ELLSMS reads | Goes into |
|---|---|---|---|
| `TBL_MOBILE` | **yes** | `NUMBER_`, `POSTALCODE`, `PROVINCECODE`, `CITYCODE`, `SIMTYPE`, `GENDER`, `BLOCKLISTED` | `ellsms_aimed_mobiles` |
| `TBL_PROVINCE` | for the province list | `ID`, `NAME`, `CODE` | `ellsms_aimed_provinces` |
| `TBL_CITY` | for the city list | `NAME`, `CODE`, `PROVINCE_ID` | `ellsms_aimed_cities` |

- **Not needed:**
  - `TBL_BLACKLIST`: Vesal already folds it into `TBL_MOBILE.BLOCKLISTED`, and blocklisted numbers are never targeted.
  - `TBL_MOBILECATEGORY_MOBILE`: categories are not used.
  - `TBL_MCICITY`: MCI's own city list, a separate code space.
- **Header names:** the importer matches the headers without regard to case, so the export's own header row works as is. Other column orders are fine, and extra columns are ignored.
- **`TBL_CITY.PROVINCE_ID`:** this points at `TBL_PROVINCE.ID`, not at the province code. That is why the provinces file is loaded first and its `ID` is kept, as `source_id`. A cities file that has a `PROVINCECODE` column instead is used as is.
- **Values:**
  - `SIMTYPE`: `PRE_PAID` or `POST_PAID`, Vesal's enum names. The ordinals `1` and `0` are also accepted.
  - `GENDER`: `0` (male) or `1` (female).
  - `BLOCKLISTED`: `1`, `true` or `Y` means blocklisted.
  - `NUMBER_`: any form of the number (`0912…`, `912…`, `98912…`) is normalized to `98XXXXXXXXXX`. Anything that is not an Iranian mobile number is skipped and counted as invalid.

### Exporting with SQLcl

```sql
SET SQLFORMAT CSV
SET FEEDBACK OFF
SPOOL TBL_PROVINCE.csv
SELECT ID, NAME, CODE FROM TBL_PROVINCE;
SPOOL TBL_CITY.csv
SELECT ID, NAME, CODE, PROVINCE_ID FROM TBL_CITY;
SPOOL TBL_MOBILE.csv
SELECT NUMBER_, SIMTYPE, GENDER, POSTALCODE, BLOCKLISTED, CITYCODE, PROVINCECODE FROM TBL_MOBILE;
SPOOL OFF
```

SQL Developer's *Export → CSV* produces the same thing. Make sure its encoding is UTF-8, so the
Persian province and city names survive.

## Setup (platform admin)

1. Apply the migration: `make db-migrations-apply`.
2. Load the bank. Put the files under the project directory so the container can see them.
   `storage/aimed-import/` is git-ignored for this. Then run:

   ```bash
   docker compose run --rm worker php cron/aimed-mobiles-import.php --dry-run \
     --provinces=storage/aimed-import/TBL_PROVINCE.csv --cities=storage/aimed-import/TBL_CITY.csv --mobiles=storage/aimed-import/TBL_MOBILE.csv
   docker compose run --rm worker php cron/aimed-mobiles-import.php \
     --provinces=storage/aimed-import/TBL_PROVINCE.csv --cities=storage/aimed-import/TBL_CITY.csv --mobiles=storage/aimed-import/TBL_MOBILE.csv
   ```

   `--batch=N` sets the rows per INSERT for the mobiles file (default 1000).

   - **Bank statistics:** after a real load the importer counts the bank and stores the figures, and `/messages/aimed` shows those instead of counting tens of millions of rows on every view. `--stats` alone recounts without loading anything.

   - **Upsert:** every load is an upsert keyed by the number (or the province or city code). Running it again with a newer export refreshes the bank in place, including a number that became blocklisted.
   - **Missing numbers:** a number missing from a newer export is **not** removed.
   - **Cleanup:** delete the CSV files afterwards, since they are the whole subscriber list.
3. On `/messages/aimed`, set:
   - **Enabled for customers.** The page and menu entry are always visible to admins.
   - **Surcharge %:** added on top of the normal price. Default 0.
   - **Most numbers per request:** default 500,000. A larger area must be capped with the count field.
4. KYC gate `aimed_bulk` is **required by default**, because this is promotional mass messaging.
   Change it on the KYC gates page.

## Flow

| Step | Who | Status | Money |
|---|---|---|---|
| Pick filters, click «شمارش» | customer | — | — |
| «ثبت درخواست» | customer | `staging` | nothing |
| Picks and prices the numbers | worker | `staging` → `priced` | nothing reserved |
| «تأیید و ارسال» | customer | `confirmed`, bulk job `staged` → `pending` | exact staged price reserved |
| Sends it | normal bulk worker | bulk job `processing` → `done` | each sent row commits its own price |

- **Local checks at creation:**
  - The feature is enabled.
  - The KYC gate passes.
  - Impersonation rules allow the send.
  - The customer has a send line and may use the chosen one.
  - The content passes the prohibited-words policy.
  - The target is not empty and not above the per-request limit.
- **Staging (worker, `aimed_bulk_stage_pass()`):**
  - The matching numbers are snapshotted once into `ellsms_aimed_bulk_targets`, in bank order, up to the count cap.
  - Then they are processed 2,000 at a time:
    - The customer's own blacklist (`ellsms_blacklist`) is dropped.
    - Each number is priced through `sms_pricing_price_messages()`, the same tariff, operator and route rules as any other send, at one pricing instant for the whole request.
    - The surcharge is applied: each row's frozen `price_cost_credits` and `unit_price_millicredits` are multiplied by (1 + surcharge / 100), rounded up.
    - The rows are written into a `staged` bulk job.
  - Each batch writes its rows and advances the cursor in one transaction. A crash resumes where it stopped and never stages a number twice. The request is leased, so two workers never stage the same one.
  - A request whose rows all drop out (blacklist, or no tariff) ends `failed`.
- **Confirmation:**
  - Organization quota is reserved, then the wallet reservation `bulk_job:<id>` for the exact staged total.
  - If either fails, nothing stays reserved and the request goes back to `priced`.
  - The status transition is claimed first, so a double click cannot reserve twice.
  - Platform-admin owners are not charged, the same as every other bulk job.
- **Sending:** the job is an ordinary `p2p` bulk job. Throughput, provider batching, retries, line opt-outs (`لغو۱۱`), cancellation, reports and delivery polling all work as for any bulk job.
- **Cancelling:** while `staging` or `priced`, the request is cancelled from `/messages/aimed`, and its staged rows are deleted. After confirmation it is cancelled like any bulk job, on its job page.

## The numbers stay the platform's

The bulk job carries `recipients_masked = 1`. For anyone but a platform admin:

- **Panel:** the number is shown masked, as `0912***4567`, on the job page (`/messages/bulk-jobs`), the bulk report, message detail and the dashboard.
- **Report exports:** the CSV export carries the masked number too.
- **Delivery webhooks:** `message.delivered` and `message.undelivered` carry the masked `destination`.
- **Line forwarding:** a delivery report forwarded to the line's URL (`app/NumberForwarding.php`) carries the masked `destination` too.
- **Number search:** a number search never reaches a masked job: it is ignored on the job page, and masked jobs are excluded from the bulk report and the export when a number is searched. Otherwise a customer could check whether a given number is in the bank.

**What masking cannot hide:** a recipient who **replies** to the sender line shows up in that line's
inbox with their own number, like any inbound message. Opt-outs (`لغو۱۱`) are recorded per line as
usual.

## Tests

- `tests/Integration/AimedBulkTest.php` covers:
  - Import, in Vesal's layout: BOM, `;` delimiter, upsert on rerun, and city→province resolution through `PROVINCE_ID`.
  - Filter normalization and counts.
  - Staging: a masked staged job, the surcharged price, the customer blacklist, the count cap, and a 4,500-row request staged across passes without duplicates.
  - Confirmation, double confirmation, insufficient credit, cancellation and customer isolation.
  - Settlement of a sent row at its frozen price.
  - Masking in webhooks, exports and the dashboard.
- `tests/Integration/AimedBulkHttpTest.php` runs the real pages: create, stage, confirm, and the job page masked for the customer but in full for the admin.
