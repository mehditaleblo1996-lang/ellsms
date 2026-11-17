# CSV exports — protection against formula injection

Code: `csv_safe_cell()` / `csv_safe_row()` in `app/bootstrap.php`. Tests:
`tests/Unit/CsvSafeCellTest.php`.

## The problem

Excel, LibreOffice Calc and Google Sheets run a cell that starts with `=`, `+`, `-` or `@` (or a tab /
carriage return) as a **formula**. Much of what the panel exports was written by someone else — the
text of an inbound SMS, a contact's name, a number's label. Anyone can send an SMS such as
`=HYPERLINK("http://…","click")` to a customer's line; without protection that text would run in the
customer's spreadsheet when they open «خروجی CSV» of «صندوق دریافت».

## The rule

Every CSV the panel writes passes each row through `csv_safe_row()`:

- a cell that starts with `=`, `+`, `-`, `@`, tab or carriage return gets a leading apostrophe (`'`),
  which a spreadsheet shows as plain text and does not run;
- a plain number is left alone (`-500`, `+989121234567`) — it is data, not a formula;
- everything else is unchanged.

So an inbound message `=1+1` appears in the file as `'=1+1`. The message itself, in the panel and
through the API, is not altered.

## Where it applies

| Export | Page |
|---|---|
| «صندوق دریافت» | `public/inbox.php` |
| «لیست پیام‌های ارسالی» (prepared in the background) | `cron/export-worker.php` |
| «گزارش مالی» | `public/financial-log.php` |
| «گزارش تعداد ارسال» / «گزارش تعداد دریافت» | `public/sent-count-report.php`, `public/received-count-report.php` |
| Telegram send and number-check results | `public/telegram-send.php`, `public/telegram-check.php` |
| «شماره‌ها» (list and the bulk-add result) | `public/numbers.php` |
| Reseller «مشتریان» | `public/reseller-customers.php` |

«گزارش وضعیت‌ها» (`public/status-report.php`) writes only dates and counts the server itself produces.

**A new CSV export must call `csv_safe_row()` on every data row.**

No migration, no setting.
