# Per-line opt-out — "11" / "12" (#38)

A recipient who texts **11** to a line stops receiving messages from **that line**; **12** re-subscribes.
Other lines are unaffected. This is the operator convention for promotional SMS in Iran, and it is how
Vesal's `MOPreparationJob` (`check11And12MOMessage`) and its internal blacklist behave.

## How it works

| Piece | Where |
|---|---|
| Keyword handling for every new inbound message (both inbound stores — backend `inbound_message` and gateway `ellsms_inbound_messages`) | `line_optout_process_inbound()`, called from `run_autoreply_pass()` |
| Who opted out of which line | `ellsms_line_optouts` (UNIQUE `originator, mobile`) |
| Idempotency + history (one row per keyword message) | `ellsms_line_optout_events` (PK `inbound_message_id`) |
| Send-time filter — direct, scheduled, auto-reply, legacy URL API | `dispatch_message_raw()` |
| Send-time filter — every bulk / import / gradual job | `bulk_send_group()`; the row fails with a clear reason |
| Page | `/contacts/optouts` (`public/line-optouts.php`) |

An opted-out recipient never reaches the provider and is never charged: callers settle only the
recipients the provider accepted, and a skipped bulk row is failed before any request. A keyword
message is applied once even if seen twice. The confirmation SMS goes out from the same line through
`system_sms_send()` (a platform notice, not a paid customer message).

Keywords are matched after normalization: Persian/Arabic digits → Latin, whitespace and ZWNJ removed,
lower-cased — so "۱۱", " 11 " and "١١" all count, while "111" or "سلام 11" do not.

## Settings (admin, on the page)

| Setting | Default |
|---|---|
| `line_optout_enabled` | `1` |
| `line_optout_stop_keywords` / `line_optout_start_keywords` | `11` / `12` (comma lists) |
| `line_optout_confirm` | `1` — send a confirmation SMS |
| `line_optout_stop_text` / `line_optout_start_text` | Persian defaults; `{line}` is replaced |
| `line_optout_exempt_types` | `otp` — message types always sent, even to an opted-out number |

## Who can do what

A customer sees the opt-outs of the lines they may send from, read-only: re-opening a number that
asked to stop is for the recipient ("12") or a platform admin. A platform admin sees every line, can add
or remove an opt-out by hand (audited as `line_optout.admin_add` / `line_optout.admin_remove`), and
edits the settings.

Tests: `tests/Integration/LineOptoutsTest.php`, `tests/Integration/LineOptoutsHttpTest.php`.
