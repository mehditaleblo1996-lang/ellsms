# Status report («گزارش وضعیت‌ها») and SMS parts in the reports

«گزارش وضعیت‌ها» answers one question per month (or per day): of the messages sent, how many reached
the phone, how many reached the operator, how many were refused and why — each as a count of messages
(«پیامک», one per recipient) **and** of SMS parts («بخش»). The same parts figure now sits next to the
message count in every main report (see [SMS parts everywhere](#sms-parts-everywhere)).

| | |
|---|---|
| Page | `public/status-report.php` — `/messages/reports/status` (clean URL; `/status-report.php` redirects) |
| Menu | «گزارش و دریافت» → «گزارش وضعیت‌ها» — admins, and every customer who has the «گزارش ارسال» menu (`reports` in `app/MenuAccess.php`) |
| Code | `app/Reports/StatusReport.php` (data, column rules, chart), `app/SendContext.php` (send channel, dropped recipients), `app/Reports/OperatorReport.php` (the daily summary it shares with the operator report) |
| Assets | `public/assets/css/status-report.css`, `public/assets/js/status-report.js` |
| Migration | `db/migrations/2026_11_11_status_report.sql` |
| Tests | `tests/Unit/StatusReportTest.php`, `tests/Integration/StatusReportHttpTest.php` |

## Who sees what

* **Admins** see every account. «سازمان» narrows to one organization (bulk jobs by organization, every
  other send by the organization's members), «کاربر» to one user. Picking an organization or user
  reloads the form so «کاربر» and «شماره‌های اختصاصی» list only theirs.
* **A customer** sees their own organization (the operator report's scope, `operator_report_scope()`),
  and can narrow to one member of it with «کاربر» when the organization has more than one.
* Rows an admin removed from a customer's panel (`app/HiddenMessages.php`) stay out of the customer's
  report, as everywhere else.

## Filters («فیلتر گزارشات»)

| Filter | Meaning |
|---|---|
| سازمان | (admins) one organization |
| شماره‌های اختصاصی | one or more sender lines — a searchable checklist of the customer's own lines (owned, organization and shared); for an admin the picked user's / organization's lines, else every line |
| کاربر | one user (an admin: anyone; a customer: a member of their organization) |
| نوع پیام | the message's language: «فارسی» (Unicode) or «انگلیسی» (all-Latin) |
| اپراتور | the destination operator; «نامشخص» for numbers no prefix rule recognizes |
| سال / ماه | a Jalali year (this year by default), optionally one month of it |
| تاریخ شروع / تاریخ پایان | override either end of the year/month range |
| تعداد بخش | only messages of exactly that many parts |
| عامل ارسال پیام | how the message was sent — see [Send channel](#send-channel-عامل-ارسال-پیام) |

The range never runs past today. A range of up to 62 days (`STATUS_REPORT_DAILY_MAX_DAYS`) is shown
one row per day, a longer one one row per Jalali month — so a year shows its months (only up to the
current month), a month its days. Every period of the range has its row, empty ones included.

## Columns («جدول گزارشات»)

Each column has «پیامک» and «بخش» sub-columns; «مجموع کل» is the row's sum. Stored statuses
(`OPERATOR_REPORT_STORED_STATUSES`) map to columns in `status_report_column()`:

| Column | Counts |
|---|---|
| رسیده به گوشی | `delivered` — the delivery report confirmed it |
| رسیده به مخابرات | `sent`, `unknown` — the operator took it, no final report (yet) |
| نرسیده به گوشی | `undelivered`, `expired`, `rejected` — the delivery report said it was not delivered |
| لیست سیاه | `blacklisted` (the operator's blacklist answer in a delivery report), `send_blacklist` (a send refused with a provider code mapped to «لیست سیاه»), `blocked` (a bulk row whose recipient opted out of the line with 11) and recipients the panel dropped for the customer's own blacklist or an opt-out |
| نرسیده به مخابرات | `failed_send` — refused at send time by the gateway/provider |
| شماره فرستنده نامعتبر | `invalid_sender` — a provider code mapped to «فرستنده نامعتبر», a bulk row sent from a line the customer may not use (or none), and sends refused for their line before reaching a gateway |
| در صف ارسال | `queued` — only shown when the range has messages still waiting |

Cancelled messages are left out: they were never sent nor charged.

**Without delivery states.** A customer who is not on delivery-based billing does not see delivery
states anywhere (`delivery_status_visible()`, [delivery-based-billing](delivery-based-billing.md)). For
them «رسیده به گوشی» and «نرسیده به گوشی» are not shown and everything the operator reported on —
delivered, undelivered, the operator's blacklist answer — counts as «رسیده به مخابرات». Refusals at
send time and the panel's own drops keep their columns.

## Charts, print and download

* «نمودار پیام‌های ارسال شده» — one stacked bar per period (inline SVG, no library; hover a bar for
  its numbers). «نمودار تعداد پیام‌های ارسال شده» — the range's share of each column (the operator
  report's CSS donut), with the total parts under it. The two chart buttons in the page's toolbar hide
  or show them; the choice is remembered per browser.
* The print button prints the table and charts without the sidebar and filters.
* The download button returns the table as CSV (UTF-8 with BOM, opens in Excel): one row per period
  with every column's messages and parts, then «جمع کل». Each download is audited
  (`status_report.export`).

## Where the numbers come from

1. **The daily summary.** `ellsms_operator_report_daily`, built per Tehran day by
   `report-summary-worker` (`operator_report_worker_pass()`), now also has the dimensions `channel` and
   `parts` (the per-message part count, `message_parts_sql()`), so parts are `SUM(parts * message_count)`.
   A built day is a few hundred rows whatever its volume.
2. **Live, for days not built yet.** Each run of unbuilt days is counted from the message tables in
   one pass and bucketed into Tehran days in SQL; the run is split wherever Iran's UTC offset changed
   (daylight saving until 2022 — `status_report_utc_pieces()`), so every message lands on its exact
   local day. The summary and the live count give identical tables (tested).
3. **`ellsms_send_drops`** — recipients the panel refused before any message row existed (below).

The three message sources are the operator report's: bulk items, gateway sends
(`ellsms_message_attempts`) and the legacy backend's `outbound_message`. Gateway sends now include the
recipients the gateway turned down (a failed attempt *with* a destination) and auto-replies, which the
operator report did not count before; see [Operator report changes](#operator-report-changes).

### How a refusal is told apart

Nothing extra is stored on a message for this; the report reads what the send and status paths
already keep, through each gateway's own mappings (`operator_report_refine_status()`):

* **At send time.** A failed gateway send keeps the provider's error code (`ellsms_message_attempts.
  error_code`; a bulk item through its `provider_audit_id` → `ellsms_provider_response_audit`). If the
  gateway's **error mapping** (Platform Admin → درگاه‌های پیامک → send connector → «نگاشت خطا») maps
  that code to `BackendInvalidSender` it is «شماره فرستنده نامعتبر», to `BackendBlacklisted`
  «لیست سیاه». Both classes are new, permanent (never retried) and do not count against provider
  health. Example: `{"13": "BackendInvalidSender", "27": "BackendBlacklisted"}`.
* **In the delivery report.** The status mapping («نگاشت وضعیت تحویل») accepts a new target
  `blacklisted`, e.g. `{"11": "blacklisted"}`. It is **stored as `rejected`**
  (`gateway_state_storable()`), so billing, delivery refunds, webhooks, DLR forwarding and every other
  report keep treating it as the rejection it is; the raw token stays in `provider_status`, and the
  report maps it back through the same mapping. Push callbacks understand `blacklisted`, `blacklist`
  and `black_list` without configuration, and a raw token that itself says "black…" or «لیست سیاه»
  counts too — so past messages are classified as well.
* **Refused by the panel itself.** A bulk row failed with the opt-out reason (`LINE_OPTOUT_ERROR`) is
  `blocked`; with the line-not-allowed / no-line / unsendable-line reasons it is `invalid_sender`
  (`ORIGINATOR_NOT_ALLOWED_ERROR`, `ORIGINATOR_EMPTY_ERROR`, `NO_ASSIGNED_LINE_MESSAGE` in
  `app/authorization.php`).

### Send channel («عامل ارسال پیام»)

`SEND_CHANNELS` in `app/SendContext.php`: پنل کاربری (`panel`), وب‌سرویس (`api`), ارسال زمان‌بندی‌شده
(`schedule`), منشی پیامک (`autoreply`), منشی هوشمند (`ai` — the AI secretary's replies and outreach,
`app/Ai/AiSecretary.php`, `app/Ai/AiOutreach.php`; a reply an operator types in the panel is `panel`),
نظیر به نظیر (`p2p`), پیامک هوشمند (`smart`), ارسال تدریجی
(`gradual`), ارسال حجیم (`bulk`).

* Panel and API sends both reach `dispatch_message()` as `direct_send`, so the request records which
  it was: `public/api/index.php` sets the send context to `api`, the auto-reply worker to `autoreply`
  (`send_context_with()`), a schedule is `schedule` by its own id, a shared-service message the worker
  sends later keeps the channel it was submitted through (`ellsms_shared_messages.source`), anything
  else is `panel`. The channel is written to `ellsms_message_attempts.send_channel` (and onto the
  recipients dropped during that send).
* A bulk job reports its type (p2p, smart, gradual — a throttled job — or bulk); a job created through
  the public API (`POST /api/v1/bulk-jobs`) gets `ellsms_bulk_jobs.send_channel = 'api'` and reports as
  «وب‌سرویس».
* Gateway sends from before this change have no channel and the legacy backend never had one: they
  show as «پنل / وب‌سرویس (سوابق قدیمی)» (`direct`).

### Recipients the panel dropped (`ellsms_send_drops`)

Some recipients never become a message row, so `send_drops_record()` records them — grouped per send
by (operator, encoding, parts), with the send's user, organization, line and channel:

| Reason | Recorded by |
|---|---|
| `blacklist` — on the customer's own blacklist | `public/new-send.php` (direct, scheduled, gradual and large sends, once the send went ahead — never for a preview), `public/send-queue.php` (once queued), the import worker per chunk (`app/import_worker.php`), aimed-bulk staging (`app/AimedBulk.php`), WebEngage (`app/Api/Handlers/WebEngage.php`), the shared sending service's promotional check (`app/SharedService.php`) |
| `optout` — opted out of the line with 11 | `dispatch_message_raw()` for direct/API/scheduled/auto-reply sends, WebEngage, the shared sending service |
| `invalid_sender` — a line the sender may not use, an unsendable line, no line at all | `dispatch_message_raw()` (non-bulk sends), `POST /api/v1/messages` when `originator` is `not_allowed` |

* An import job's drops count once the job was actually sent (status `queued`/`sending`/`completed`),
  an aimed-bulk request's once it was confirmed. A retried import chunk replaces what it recorded.
* A retried send (a schedule or auto-reply retry) records its drops once: rows carry the operation's
  `refType:refId` (`ref_key`, unique with the group).
* Recording is reporting only: a failure is logged (`send_drops.record_failed`) and never fails a send.
* The migration backfills the blacklist counters import jobs and aimed-bulk requests already kept
  (their operator, encoding and parts were never recorded, so those rows show under «نامشخص»).

## SMS parts everywhere

A part is one SMS segment: a Unicode (Persian) text up to 70 characters is one part, longer ones 67
characters a part; an all-Latin text up to 160 is one part, longer ones 153 a part (`sms_parts()`).
Every report reads the count stored on the message row (`parts`, the count the send was priced with)
and falls back to the text for a row not filled yet (`message_parts_sql()`); the legacy backend's
`outbound_message` from its text. This report labels it «بخش», as in its design; the other reports
call it «پارت». Every main report shows the parts next to its message counts — the sent list (column
and summary cards), «گزارش ارسال حجیم», the operator reports, the count reports, the dashboard's today
cards and the delivery history: see [message-parts](message-parts.md) for the full list.

## Operator report changes

The status report shares the operator report's summary, so the operator report changed with it:

* New columns «لیست سیاه» (`blacklisted` + `send_blacklist` + `blocked`) and «فرستنده نامعتبر»
  (`invalid_sender`) in the admin status table; `failed_send` still shows under «رد شده».
* Gateway sends the gateway turned down, and auto-replies, are now counted (as «رد شده» / their
  delivery state); before, only accepted direct and scheduled sends were. «مجموع ارسال‌شده» and the
  per-customer «تعاملی» count include them, the same way a refused bulk row always was.

## Migration and rollout

`db/migrations/2026_11_11_status_report.sql` (additive, guarded, rerun-safe):

1. `send_channel` on `ellsms_message_attempts` and `ellsms_bulk_jobs`.
2. `channel` and `parts` on `ellsms_operator_report_daily`, in its primary key, and `layout` on
   `ellsms_operator_report_days`. Only a day built with the current layout
   (`OPERATOR_REPORT_SUMMARY_LAYOUT` = 2, `operator_report_built_days()`) is read from the summary, so a
   day an old worker rebuilds while the deploy migrates is simply rebuilt again. The summary is
   **emptied** (`ellsms_operator_report_days` and the daily rows) so every day is rebuilt with the new
   dimensions; `report-summary-worker` rebuilds today and yesterday first, then the last
   `OPERATOR_REPORT_BACKFILL_DAYS` (400) days newest first, within `OPERATOR_REPORT_PASS_SECONDS` per
   pass. Until a day is rebuilt every report counts it live — correct, only slower — so expect the
   operator and status reports to be slower for long ranges right after deploying.
3. `ellsms_send_drops`, backfilled once from import jobs' and aimed-bulk requests' blacklist counters.

Code without the migration keeps working: the channel and drop recording switch themselves off until
the columns/table exist, and the summary counts as unavailable (every report counts live, the worker
skips with `not_migrated`) until it has the new layout. `deploy.sh` applies migrations before it
restarts the services, as always.
