# «افزودن لغو۱۱ به انتهای پیام» — opt-out footer on panel sends

Every panel form that composes an SMS has a checkbox **«افزودن «لغو۱۱» به انتهای پیام»**, **ticked by
default**. While it is ticked, the text `لغو۱۱` (Persian digits) is added on a **new line** at the end
of every message before it is priced, previewed, queued or sent:

```
مشتری گرامی، تخفیف ویژه امروز...
لغو۱۱
```

It is **never doubled**: if the text already carries an opt-out marker anywhere, nothing is added.
The marker is «لغو» followed by `11` in Persian (`۱۱`), Arabic (`١١`) or Latin digits, with or without
a space or colon in between (`لغو11`, `لغو ۱۱`, `لغو: 11`…). `لغو 110` or `لغو ۱۲` do not count. Trailing
blank lines/spaces are trimmed before the line is added; an empty text stays empty.

The suffix is part of the sent text, so it counts toward characters, SMS parts and cost. The
composer's counter, phone preview and live cost (new-send / old send page) and the LBA part counter
count it as soon as the box is ticked.

## Where it appears

| Page | Form | How it is applied |
|---|---|---|
| «ارسال پیامک» `/new-send.php` | under «متن پیام» | to `content` before preview and every mode (direct, Bale, recurring schedule, gradual, large-list import). A campaign saved with «ذخیره به عنوان کمپین» keeps the text **as typed**. |
| «ارسال پیامک (قدیمی)» `/send.php` | under «متن پیام» | to `content` before preview, schedule and send |
| «نظیر به نظیر» `/p2p-send.php` | upload form | to **each row's** text (column B) |
| «پیامک هوشمند» `/smart-send.php` | upload form | to each row's text **after** the template is rendered |
| «ارسال با کد پستی» `/aimed-bulk.php` | request form | to the request text before `aimed_bulk_create()` |
| «ارسال منطقه‌ای» `/regional-bulk.php` | request form | to the request text before `regional_bulk_create()` |
| «ارسال پیام منطقه‌ای (LBA)» `/messages/lba` | simple send and periodic start/edit | to `body` before `lba_send_simple()` / `lba_send_periodic*()` |

The web service (`/api/v1/*`) and the legacy URL send API are **not** affected: integrations send
exactly the text they post. Scheduled rows (`ellsms_schedule`) store the text with the suffix
already applied, so later runs send it as is.

## Large files (async import)

P2P and smart files larger than `SMS_SYNC_MAX_RECIPIENTS` rows go through the import pipeline
(`docs/large-import-architecture.md`), where the row text is only read in the worker. The choice is
stored on `ellsms_import_jobs.add_optout_suffix` and `import_job_analyze_pass()` /
`import_fast_analyze_generated_job()` append the suffix per row before pricing and dedupe. A large
new-send list is written to the generated CSV with the suffix already applied.

**Migration:** `db/migrations/2026_11_23_import_job_optout_suffix.sql` adds the column (default 0, so
existing jobs are untouched). Until it is applied, small files and every other page still work; a
large P2P/smart file is imported **without** the suffix and `import.optout_suffix_column_missing` is
logged.

## Implementation

- `app/OptoutSuffix.php` — `optout_suffix_present()`, `optout_suffix_apply()`, `optout_suffix_maybe()`,
  `optout_suffix_requested()` (reads `add_optout`; the form posts a hidden `0` before the checkbox's
  `1`, and a request without the field gets the default **on**) and `optout_suffix_checkbox_html()`.
- JS mirrors of the same rule: `public/assets/js/sms-composer.js` (`sentText()`) and the LBA page
  script (`optoutText()`).
- Tests: `tests/Unit/OptoutSuffixTest.php`.

No new setting, cron job or API scope. Related: recipients who text `11` to a line are filtered at
send time by the per-line opt-out list — `docs/line-optouts.md`.
