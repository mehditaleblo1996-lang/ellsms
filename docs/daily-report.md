# Daily Telegram report for the admin (گزارش روزانه‌ی تلگرام)

Once a day the admin's Telegram chat gets a summary of the previous Tehran day:

| Section | What | Read from |
|---|---|---|
| headline (3rd line) | one line: 🟢 «وضعیت عادی» with the delivery rate, or 🟡 / 🔴 with up to three things that need attention (see below) | `daily_report_headline()` over the figures below |
| ✉️ ارسال پیامک | messages and SMS parts — total (with ▲▼ against the day before), delivered and failed (with their % of the total), sent (waiting for a report), queued, cancelled; then «بدترین درگاه»: the gateway with the lowest delivery rate, its delivered and failed % and message count | `sent_report_summary()` over every account: the same counts «لیست پیام‌های ارسالی» shows for that day (bulk rows, direct/API/scheduled/auto-reply attempts, legacy backend sends); per gateway, the same sources grouped by `gateway_id` (`daily_report_gateways()`) |
| 💰 مالی | «مبلغ کسرشده» (SMS `sms_debit`, AI `ai_debit`), «مبلغ برگشتی» (`delivery_refund`, refunds of failed / undelivered messages), «خالص کسر» (▲▼ against the day before), «خرید اعتبار» (`purchase`), admin credits / debits | the wallet ledger (`ellsms_wallet_transactions`) of that day |
| ⚠️ خطاها | failed messages (▲▼ against the day before), «⏳ گیرکرده»: messages of the day still queued when the report is built, their five most common error codes (an attempt's `error_code`, a bulk row's `[CODE]` prefix), scheduler jobs whose last run failed | `ellsms_message_attempts`, `ellsms_bulk_items`, `ellsms_scheduler_runs` |
| 👤 مشتری‌های جدید | panel accounts created that day (platform admins left out), the first ten by name | `ellsms_meta.created_at` |
| 🚨 حادثه‌های باز | every open or acknowledged alert, the most severe first (up to eight listed) | `AlertManager::activeIncidents()` |

### Headline and comparison

The third line of the report is its verdict. Each of these adds one item (at most three are shown,
then «…»):

| Item | When | Colour |
|---|---|---|
| «N حادثه‌ی بحرانی باز» | an open or acknowledged `critical` / `emergency` alert | 🔴 |
| «نرخ خطا بالا (N٪)» | failed ≥ 10% of the day's messages (`DAILY_REPORT_FAIL_WARN_PERCENT`) | 🟡, 🔴 from 30% (`DAILY_REPORT_FAIL_CRITICAL_PERCENT`) |
| «N پیام هنوز در صف» | messages of the day still queued when the report is built (the next morning) | 🟡 |
| «درگاه X با N٪ تحویل» | the worst gateway delivered < 50% of its messages (`DAILY_REPORT_GATEWAY_WARN_PERCENT`) | 🟡 |
| «افت ارسال ▼N٪» | the day's messages fell ≥ 50% against the day before (`DAILY_REPORT_VOLUME_DROP_PERCENT`; only when the day before had ≥ 50) | 🟡 |
| «N کار زمان‌بندی‌شده‌ی ناموفق» | a scheduler job's last run failed | 🟡 |

Nothing of that: «🟢 وضعیت عادی · نرخ تحویل N٪».

▲▼ compare with the Tehran day before the report's day (read the same way, at report time): «کل»,
«پیام‌های ناموفق» and «خالص کسر» get « ▲N٪», « ▼N٪» or « ＝»; nothing is shown when the day before
had none. The date line says «▲▼ نسبت به روز قبل» when there is something to compare with.

The gateway rates count only messages that reached a gateway (`gateway_id` set — queued, cancelled
and pre-gateway failures have none) and only gateways with at least 50 messages that day
(`DAILY_REPORT_GATEWAY_MIN_MESSAGES`). «تحویل» is a confirmed delivery report, so a gateway that
returns no delivery reports reads low: its messages stay «ارسال‌شده (منتظر گزارش)». The thresholds are
constants at the top of `app/DailyReport.php`.

Code: `app/DailyReport.php` (collect, format, send, the scheduler pass), `cron/daily-report.php`,
settings card on **هشدارها** (`/admin/alerts`, `public/alerts.php`). No migration, no API route.

## When it is sent

The scheduler job **`daily-report`** runs every 10 minutes (`docs/scheduler.md`). It sends the report
for *yesterday* (Tehran) once the time of day `DAILY_REPORT_AT` (default `08:00`, Tehran) has passed
and that day has not been sent yet. The last day sent is kept in the setting `daily_report_last_day`,
so:

- a restart or a late scheduler still sends the day once, never twice;
- a Telegram failure (unreachable, the bot refused) exits 1 — the scheduler raises its usual
  `scheduler_job_failed:daily-report` alert — and the next run, 10 minutes later, tries again;
- no bot token or no chat is not a failure: nothing is sent and nothing is retried until one is set.

## Settings

| Setting | Where | Default |
|---|---|---|
| `daily_report_enabled` | «ارسال گزارش روزانه» checkbox on `/admin/alerts` (env `DAILY_REPORT_ENABLED` as the default) | on |
| `daily_report_telegram_chat_id` | «شناسه‌ی گفت‌وگوی تلگرام» on `/admin/alerts` — a numeric chat id (groups are negative) or `@channel` | empty = the «تماس با ما» chat (`telegram_chat_id`) |
| `DAILY_REPORT_AT` | `.env` | `08:00` (HH:MM, Tehran) |

The bot is the one configured under **تنظیمات → تماس با ما** (`telegram_bot_token`). The same card has
**«پیش‌نمایش گزارش دیروز»** (the text, built on request — it reads a whole day) and **«ارسال آزمایشی
گزارش دیروز»** (sends it now; the scheduled report still goes out at its own time). Both are audited
(`daily_report.settings`, `daily_report.test_send`).

## Cost

Every figure is bounded by one day — the report's day and, for ▲▼, the day before it (its message
counts and ledger money are read a second time). The day's ledger rows are found by a binary search on the
ledger's primary key (`daily_report_id_range()`: ids grow with time; there is no index on
`created_at` alone), so the money section reads only that day's rows. The message counts use the
send report's own indexed per-source queries.

## Commands

```bash
php cron/daily-report.php --dry-run                 # print yesterday's report, send nothing
php cron/daily-report.php --day=2026-11-15          # send that Tehran day's report now
php cron/daily-report.php --day=2026-11-15 --dry-run
make scheduler-run JOB=daily-report                 # the scheduler's own pass (sends only if due)
```

Tests: `tests/Integration/DailyReportTest.php`.
