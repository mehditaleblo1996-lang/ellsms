# Operator reports («گزارش اپراتورها»)

How many messages went to each mobile operator (همراه اول، ایرانسل، رایتل، …), in Persian or English,
and what became of them — over a Jalali date range.

| | |
|---|---|
| Page | `public/operator-reports.php` — `?view=` `all` (default), `number`, `route` |
| Menu | «گزارش و دریافت» → «گزارش اپراتورها», «گزارش اپراتورها بر اساس شماره», «گزارش اپراتورها بر اساس تأمین‌کننده» (admins only) |
| Code | `app/Reports/OperatorReport.php` (counts, daily summary, worker pass) |
| Worker | `report-summary-worker` (`cron/report-summary-worker.php`, `operator_report_worker_pass()`) — see [scheduler](scheduler.md) |
| Migrations | `2026_10_26_operator_report_daily.sql`, `2026_11_11_status_report.sql`, `2026_11_11_report_parts.sql`, `2026_11_25_operator_report_gateway.sql` |
| Tests | `tests/Unit/OperatorReportTest.php`, `tests/Integration/OperatorReportHttpTest.php` |
| API | none |

## The three views

| View | Shows | Who |
|---|---|---|
| «گزارش اپراتورها» (`all`) | each operator's share, the Persian/English split and the status table | admins; customers (their organization) |
| «بر اساس شماره» (`number`) | the status table for one sender line («شماره») | admins; customers (their own lines) |
| «بر اساس تأمین‌کننده» (`route`) | the status table for one **SMS gateway** — the messages that actually left through it | admins only |

Admins can narrow every view to one customer («نام مشتری») and to VIP / regular customers
([vip-customers](vip-customers.md)). A customer sees only «مجموع ارسال‌شده» (and «تحویل گرفته‌شده» on
delivery-based billing — [delivery-based-billing](delivery-based-billing.md)); every count has its SMS
parts beside it ([message-parts](message-parts.md)).

### «بر اساس تأمین‌کننده»: suppliers are the SMS gateways

The «تأمین‌کننده» picker lists the SMS gateways configured under «درگاه‌های پیامک»
(`ellsms_sms_gateways`, [sms-gateway-connectors](sms-gateway-connectors.md), including SMPP and the
Pishgamrayan gateway), archived ones last and marked «(بایگانی‌شده)». The query parameter is
`gateway_id`. A message counts for a gateway when its `gateway_id` names it:

* bulk items (`ellsms_bulk_items.gateway_id`), or for a send-time refusal the gateway in its provider
  answer (`ellsms_provider_response_audit.gateway_id`);
* direct / API / scheduled / auto-reply sends (`ellsms_message_attempts.gateway_id`).

Not counted under any gateway: legacy backend API sends (`outbound_message`, no gateway), messages still
in queue (no gateway yet), and the messages of an old summary day whose messages were archived before
the gateway column existed (see below).

Before 2026-11-25 this view listed the pricing catalog's providers (`ellsms_sms_providers`) and filtered
by their routes. On most installs that catalog only holds the seeded «ارائه‌دهنده‌ی پیش‌فرض», so the
picker showed no real supplier; and a number pinned to a gateway ([number-gateway](number-gateway.md))
bypasses routes anyway.

## The daily summary

`ellsms_operator_report_daily` holds each Tehran day folded into a few hundred rows (source, user,
organization, sender, route, **gateway**, channel, operator, encoding, parts, status, hidden →
`message_count`, `part_count`); `ellsms_operator_report_days` records when each day was built and with
which `layout`. The page sums built days and counts any other day live from the message tables; both
give identical tables (tested). The status report («گزارش وضعیت‌ها», [status-report](status-report.md))
and the customer message counts read the same summary.

Layouts (`OPERATOR_REPORT_SUMMARY_LAYOUT` in `app/Reports/OperatorReport.php`):

| Layout | Adds | Read by |
|---|---|---|
| 2 | `channel`, `parts` (`2026_11_11_status_report.sql`) | every unfiltered count (`OPERATOR_REPORT_SUMMARY_READABLE_LAYOUT`) |
| 3 | `gateway_id` (`2026_11_25_operator_report_gateway.sql`) | also the gateway-filtered view |

### Migration `2026_11_25_operator_report_gateway.sql`

Adds `gateway_id INT UNSIGNED NOT NULL DEFAULT 0` (0 = none) to `ellsms_operator_report_daily` and puts
it in the primary key. Additive, guarded, rerun-safe; the summary is **not** emptied. After it,
`report-summary-worker` writes layout 3 and upgrades layout-2 days newest first (after today and
yesterday), within its usual per-pass budget. Until a day is upgraded the gateway view counts it live
(correct, only slower); every other report keeps reading it from the summary. An upgrade never shrinks a
day: when its messages are no longer all in the tables (archived, or a restored backup) the day keeps
its counts, with gateway 0, and moves to layout 3. Without the migration the code keeps writing layout 2
and the gateway view always counts live.
