# Aimed bulk: send by postal code from ELLSMS's own number bank

Send one message to the subscribers in a **postal-code area**, a **province** or **city**, a
**number prefix** and/or a **SIM type** (prepaid / postpaid). The filters combine: «postal code 131…,
prepaid, 0912» is one target. «نحوه‌ی انتخاب شماره‌ها» decides which of the matching numbers a
request takes: from the start of the bank, at random, or a range of rows such as 10,001–20,000 (see
[Which numbers a request takes](#which-numbers-a-request-takes)). Also on the public API
(`/api/v1/aimed/*`, scopes `aimed:send` / `aimed:read`, see [API](#api)).

The number bank is Vesal's `TBL_MOBILE`, copied into ELLSMS. This is the difference from
[regional bulk](regional-bulk.md) (#43), which asks Vesal to send and never sees a number. Aimed bulk
sends through ELLSMS's own pipeline: its sender lines, gateways, bulk queue and wallet.

Code: `app/AimedBulk.php`, API `app/Api/Handlers/AimedBulk.php`. Page: `/messages/aimed` (`public/aimed-bulk.php`). Import:
`cron/aimed-mobiles-import.php`. Schema: `db/migrations/2026_10_17_aimed_bulk.sql` and
`db/migrations/2026_11_22_aimed_bulk_background_count.sql` (covering indexes and background counts).

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

1. Apply the migrations: `make db-migrations-apply`.
   - `2026_11_22_aimed_bulk_background_count.sql` rebuilds the bank's indexes (see
     [Counting and indexes](#counting-and-indexes)) in one in-place `ALTER`, without locking the
     table. On a bank of tens of millions of numbers this takes minutes and needs free disk for the
     new indexes while it runs (roughly 40 bytes per number per index).
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
   - **Stored counts:** a real load also clears the stored «شمارش» results (`ellsms_aimed_counts`), so the next count reflects the new bank.

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
| Pick filters, click «شمارش شماره‌ها» | customer | count queued; the worker counts it, the page shows the result | — |
| «ثبت درخواست» | customer | `staging` | nothing |
| Checks the area's size, picks and prices the numbers | worker | `staging` → `priced` (or `failed`, with the reason) | nothing reserved |
| «تأیید و ارسال» | customer | `confirmed`, bulk job `staged` → `pending` | exact staged price reserved |
| Sends it | normal bulk worker | bulk job `processing` → `done` | each sent row commits its own price |

- **Local checks at creation:**
  - The feature is enabled.
  - The KYC gate passes.
  - Impersonation rules allow the send.
  - The customer has a send line and may use the chosen one.
  - The content passes the prohibited-words policy.
  - A count cap («سقف تعداد»), or a range («بازه») longer than the per-request limit, is refused. An invalid range (from missing or below 1, to before from) is refused too.
  - The area is **not** counted here: on a large bank that outlived the page's proxy timeout. The worker checks it in the next step.
- **Staging (worker, `aimed_bulk_stage_pass()`):**
  - Without a count cap, the area is counted first, only up to the per-request limit. An empty area ends the request `failed` with «هیچ شماره‌ای با این مشخصات در بانک شماره نیست»; an area above the limit ends it `failed` with the advice to set a cap or narrow the area. No bulk job is created for either. With a cap (or a range, whose length is the cap), nothing is counted; the cap is the snapshot's size.
  - The matching numbers are snapshotted once into `ellsms_aimed_bulk_targets`, up to the count cap (or the limit), as «نحوه‌ی انتخاب شماره‌ها» says: in bank order, in random order, or the range's rows. A range that starts past the end of the area ends `failed` with «هیچ شماره‌ای در این بازه نیست». The rows are staged in that order, and the bulk worker claims them for sending by row id (`bulk_claim_items()`), so the send follows it too.
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

## Counting and indexes

«شمارش شماره‌ها» used to count inside the web request. On a bank of tens of millions, a broad filter
such as prefix 0912 took longer than the proxy waits (Cloudflare error 524). Counting now runs in
the background, on indexes that answer it without reading the table:

- **The page only queues the count.** `aimed_bulk_count_request()` stores a row in
  `ellsms_aimed_counts` (status `queued`) and the page returns at once. The id of the session's
  last count is kept in the session, so a customer only ever sees the count they asked for.
- **The worker counts it.** `aimed_bulk_count_pass()` in `cron/worker.php` (every loop; one indexed
  `SELECT` when nothing is queued) claims queued counts under a lease, counts each one, and stores
  the result (`done`) or the reason (`failed`).
- **The page polls.** While a count is queued or running, the page asks
  `/messages/aimed?action=count_status` every two seconds and shows the result in place:
  «تعداد شماره‌های «…»: N». The customer may leave the page; the result is shown when they come back.
- **Up to the limit only.** A count stops at the per-request limit plus one
  (`aimed_bulk_count($criteria, $upTo)`), and a larger area is shown as «بیش از N (حداکثر هر
  درخواست)». A request can never target more than the limit, so counting further buys nothing.
- **Time limit.** One count query may run `AIMED_BULK_COUNT_MAX_SECONDS` (60 s, MySQL
  `MAX_EXECUTION_TIME`). Past that it is stopped and the customer is asked to narrow the area. The
  same limit applies to the size check while staging, which then ends the request `failed`.
- **Reuse.** A finished count of the same filters (and the same limit) is shown again for an hour
  (`AIMED_BULK_COUNT_REUSE_SECONDS`) instead of being recounted, whoever asks: the count is a fact
  about the bank, not about the customer. A failed or older count is queued again. Every real
  import clears the table.

**Covering indexes** (`ellsms_aimed_mobiles`). Each index holds every column a filter can use, so a
count or a target snapshot reads only the index, never the table rows:

| Index | Columns | Serves |
|---|---|---|
| `idx_prefix` | `blocklisted, mobile, sim_type` | prefix, prefix + SIM type |
| `idx_postal_cover` | `blocklisted, postal_code, sim_type, mobile` | postal code, + SIM type / prefix |
| `idx_place_cover` | `blocklisted, province_code, city_code, sim_type, mobile` | province, province + city, + SIM type / prefix |
| `idx_city_cover` | `blocklisted, city_code, sim_type, mobile` | city alone, + SIM type / prefix |

They replace the earlier `idx_postal`, `idx_province` and `idx_city`. Before, a prefix-only filter
had only `uniq_mobile`, which holds no `blocklisted` column, so every matching number cost a lookup
in the table. Mixing postal code with province or city still works, but only one index is used; the
rest of the filter is then checked against the table rows.

## Which numbers a request takes

«نحوه‌ی انتخاب شماره‌ها» on the form (`pick` in the request's `criteria_json` and on the API):

| Mode | `pick` | Takes | SQL |
|---|---|---|---|
| «از ابتدای بانک» (default) | `bank` | the first numbers of the area, up to «سقف تعداد» | `ORDER BY m.id LIMIT cap` |
| «تصادفی» | `random` | a random draw from the whole area, different every request | `ORDER BY RAND() LIMIT cap` |
| «بازه» | `range` | rows `range_from`..`range_to` of the area in bank order | `ORDER BY m.id LIMIT len OFFSET range_from − 1` |

- **Bank order** is the order the numbers were imported in: a re-import keeps a number's place, new
  numbers go to the end. With `bank` the same filters and cap pick the **same numbers every time**,
  so a second request reaches the same people again.
- **Random** costs about the same as bank order: measured on the production bank, drawing 100,000 of
  a 0912 area took 3.4 s against 3.2 s.
- **Range** lets a customer work through an area in slices: rows 1–10,000, next time 10,001–20,000.
  The range's length is the cap («سقف تعداد» is hidden and ignored) and must fit the per-request limit.
  «ردیف» counts the numbers that match **all** the filters (0912 alone and 0912 prepaid are different
  lists). A range past the end sends what there is (rows 10,001–20,000 of a 15,000-number area = 5,000);
  one that starts past the end fails. **Rows can shift between requests:** a number that becomes
  blocklisted (or stops being) on a re-import moves every later row by one, so consecutive ranges can
  then overlap or skip a few numbers. New numbers only add rows at the end.
- The list shows the mode next to the filters: «انتخاب تصادفی» or «ردیف ۱۰٬۰۰۱ تا ۲۰٬۰۰۰».
- None of them is a filter: the count and the size check do not change. Without a cap (the whole area,
  within the limit) `bank` and `random` take the same numbers; random only changes the sending order.
- Requests made with the earlier «انتخاب تصادفی شماره‌ها» checkbox carry `"random": true` and are read
  as `random` (`aimed_bulk_pick()`); the API and the form also still accept `random=1`.

## API

Scopes `aimed:send` / `aimed:read`, handler `app/Api/Handlers/AimedBulk.php`, reference in
`docs/public-api.md` and `docs/openapi-v1.yaml`, customer guide PDF §9:

| Route | Scope | Does |
|---|---|---|
| `GET /api/v1/aimed/provinces`, `GET /api/v1/aimed/provinces/{code}/cities` | `aimed:read` | codes and names for the filters |
| `POST /api/v1/aimed/count` | `aimed:read` | queues the background count; `202` while counting, call again with the same body until `200` |
| `POST /api/v1/aimed/requests` | `aimed:send` | creates a request (filters, `pick`, `range_from`/`range_to`, `count`, `originator`, `text`, `title`, `client_message_id`) → `202`, status `staging` |
| `GET /api/v1/aimed/requests/{id}` | `aimed:read` | status, size, `total_cost` once `priced`, `error` once `failed`, `bulk_job_id` once confirmed |
| `POST /api/v1/aimed/requests/{id}/confirm` | `aimed:send` | reserves the price and sends |
| `POST /api/v1/aimed/requests/{id}/cancel` | `aimed:send` | cancels while `staging` / `priced` |

The same rules as the page apply (service on, KYC gate, sender line, prohibited words, per-request
limit), through the same functions. The key acts as its creator, like every other route. No number
ever appears: after confirm, `GET /api/v1/bulk-jobs/{bulk_job_id}/state` (`bulk:read`) gives each
recipient's delivery state with the number masked.

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
  - Filter normalization and counts, the count bounded at the limit, and background counts: queued, counted by the worker pass, reused while fresh, recounted when stale, cleared by an import.
  - Staging: a masked staged job, the surcharged price, the customer blacklist, the count cap, the pick modes (validation, random draws that differ between requests, ranges taking those rows in bank order, a range past the end, one starting past it, one longer than the limit), a 4,500-row request staged across passes without duplicates, and an empty or too-large area ending `failed` in staging.
  - Confirmation, double confirmation, insufficient credit, cancellation and customer isolation.
  - Settlement of a sent row at its frozen price.
  - Masking in webhooks, exports and the dashboard.
- `tests/Integration/AimedBulkApiTest.php` runs the API over HTTP: scopes, the province list, the count polled until the worker has counted it, a range request staged, priced, confirmed (once) and followed on `/bulk-jobs/{id}/state` with the numbers masked, validation, `404` and cancel.
- `tests/Integration/AimedBulkHttpTest.php` runs the real pages: «شمارش» queued and polled (never counted in the request, and only visible to the session that asked), create (with «انتخاب تصادفی شماره‌ها» reaching the request), stage, confirm, and the job page masked for the customer but in full for the admin.
