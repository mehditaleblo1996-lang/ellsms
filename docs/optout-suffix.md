# «افزودن لغو۱۱ به انتهای پیام» — opt-out footer on panel sends

A **platform admin** decides **per user** whether the text `لغو۱۱` (Persian digits) is added on a
**new line** at the end of every message that user sends from the panel: **Users → user →
«افزودن «لغو۱۱» به انتهای پیام»** (`/users.php`, checkbox «فعال» + «ثبت», audited as
`user.optout_suffix_on` / `user.optout_suffix_off`). It is **on by default** for every user.
Customers have **no checkbox**: while it is on for them, the send forms show a short read-only note
under the message box; while it is off, nothing is shown and nothing is added.

```
مشتری گرامی، تخفیف ویژه امروز...
لغو۱۱
```

It is **never doubled**: if the text already carries an opt-out marker anywhere, nothing is added.
The marker is «لغو» followed by `11` in Persian (`۱۱`), Arabic (`١١`) or Latin digits, with or without
a space or colon in between (`لغو11`, `لغو ۱۱`, `لغو: 11`…). `لغو 110`, `لغو ۱۲` or a half-typed `لغو1` do
not count. Trailing blank lines/spaces are trimmed before the line is added; an empty text stays empty.

The suffix is part of the sent text, so it counts toward characters, SMS parts and cost. The
composer's counter, phone preview and live cost (new-send / old send page) and the LBA part counter
include it whenever the note is on the page.

## Where it appears

| Page | Note shown | How it is applied |
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
(`docs/large-import-architecture.md`), where the row text is only read in the worker. The sender's setting is
stored on `ellsms_import_jobs.add_optout_suffix` and `import_job_analyze_pass()` /
`import_fast_analyze_generated_job()` append the suffix per row before pricing and dedupe. A large
new-send list is written to the generated CSV with the suffix already applied.

**Migrations:** `db/migrations/2026_11_24_user_optout_suffix.sql` adds `ellsms_meta.optout_suffix`
(per-user switch, default 1; until it is applied every user gets the suffix and the admin control is
hidden). `db/migrations/2026_11_23_import_job_optout_suffix.sql` adds the import-job column (default 0, so
existing jobs are untouched). Until it is applied, small files and every other page still work; a
large P2P/smart file is imported **without** the suffix and `import.optout_suffix_column_missing` is
logged.

## Implementation

- `app/OptoutSuffix.php` — `optout_suffix_present()`, `optout_suffix_apply()`, `optout_suffix_maybe()`,
  `optout_suffix_for_user()` / `optout_suffix_set_for_user()` (the per-user switch) and
  `optout_suffix_notice_html()` (the read-only note; its `data-optout-suffix` attribute is what the
  JS counters look for).
- JS mirrors of the same rule: `public/assets/js/sms-composer.js` (`sentText()`) and the LBA page
  script (`optoutText()`).
- Tests: `tests/Unit/OptoutSuffixTest.php`.

No new setting, cron job or API scope. Related: recipients who text `11` to a line are filtered at
send time by the per-line opt-out list — `docs/line-optouts.md`.
