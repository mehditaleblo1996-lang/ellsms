# Service lines — skipping the blacklist (شماره‌ی خدماتی)

A **service line** is a sender line whose sends are **not** checked against the customer's
do-not-contact list (`ellsms_blacklist`, page `/blacklist.php`). It is meant for transactional
lines — payment notices, bills, appointment reminders — that must reach a recipient even if the
customer once put that number on their blacklist for marketing.

This is Vesal's `Number.byPassBlackList` (column `bypassblacklist`): `MTSMSServiceImpl` only calls
`checkBlackList()` when `!originator.isByPassBlackList()`, and the internal blacklist (opt-out via
"11") is still checked for a bypass line. ELLSMS keeps the same split.

## Behaviour

| | Ordinary line | Service line |
|---|---|---|
| Customer blacklist (`ellsms_blacklist`) | applied wherever it is today | **skipped** on every path, even with «فقط ارسال به لیست سفید» ticked |
| Line opt-outs "11" (`ellsms_line_optouts`, docs/line-optouts.md) | applied | **still applied** — the recipient asked *this line* to stop |
| Price, route, gateway | unchanged | unchanged |

Every path that filters the blacklist goes through `filter_blacklist($userId, $destinations, $originator)`
(`app/bootstrap.php`), which returns the list untouched when `$originator` is a service line:

- direct / scheduled / gradual send (`public/new-send.php`), queued direct send (`public/send-queue.php`)
- large imports — p2p, smart, gradual, regional (`import_job_analyze_pass()`, `app/import_worker.php`)
- aimed bulk / postal-code send (`app/AimedBulk.php`)
- cost preview, web and API (`app/Cost/MessageCostEstimator.php`) — so the preview's
  `blacklisted_count` matches what the send does

The flag is read from the database on each call (no cache), so a change reaches long-running
workers on their next chunk. An import or aimed-bulk job whose blacklist pass already ran keeps
that result.

## Managing it

Platform admin → **شماره‌ها** (`/numbers.php`):

- column **خدماتی (عبور از لیست سیاه)** shows فعال / غیرفعال per line, with a button to switch it
  (asks for confirmation);
- the **نوع خط** filter shows all lines, only service lines, or only ordinary lines, with counts;
- each change is audited as `number.bypass_blacklist_on` / `number.bypass_blacklist_off` with the number.

New lines start as ordinary lines (`DEFAULT 0`). Shared users of a line (docs/shared-numbers.md)
send from the same row, so they get the same behaviour — like Vesal copying the flag to transferred
numbers. Customers cannot change the flag; on the send page a service line is labelled «خدماتی»
and a note under the blacklist toggle says the blacklist does not apply to it.

## Schema

`db/migrations/2026_10_22_number_bypass_blacklist.sql` adds
`ellsms_numbers.bypass_blacklist TINYINT(1) NOT NULL DEFAULT 0`. Until it is applied, no line is a
service line and everything behaves as before.

Tests: `tests/Integration/ServiceLineBlacklistBypassTest.php`, `tests/Integration/ServiceLinesHttpTest.php`,
plus service-line cases in `LargeImportPipelineTest` and `AimedBulkTest`.
