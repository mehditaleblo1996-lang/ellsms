# Shared OTP (کد تایید اشتراکی)

One-time codes sent from the platform's own lines, for customers who have no sender line of their
own. It is ELLSMS's version of Negar's *predefined messages* (`PreDefinedMessage`,
`ApiServiceImpl.sendParameterizedMessage()`, `msg.number.send.type.message`): the customer fixes the
text in advance, an admin approves it, and each call supplies only the variable values.

## How it works

1. **Template.** On **/otp-templates.php** (sidebar: یکپارچه‌سازی → کد تایید اشتراکی) the customer
   writes the fixed text and marks each variable as `{name}` (Latin letters, digits, `_`):

   ```
   فروشگاه نمونه
   {name} عزیز، کد ورود شما: {code}
   ```

   Up to 5 variables, up to 500 characters, and it must have fixed text — a template that is nothing
   but placeholders is refused. Prohibited words are checked when it is saved. It starts as
   **pending**.
2. **Review.** A platform admin approves it or rejects it with a reason (the customer sees the reason
   and gets a panel notification). An approved template can later be disabled. Any change to the
   text sends it back to *pending*; a title-only change keeps the approval.
3. **Send.** With an API key that has `otp:send`:

   ```http
   POST /api/v1/otp
   Authorization: Bearer ellsms_live_…
   Content-Type: application/json

   {"template_id": 12, "destination": "09121234567", "parameters": {"name": "سارا", "code": "4821"}}
   ```

   `parameters` may also be a list in the template's variable order (`["سارا", "4821"]`), like Negar's
   positional parameters. The answer is `201` with
   `{"id", "template_id", "destination", "originator", "status": "sent", "parts", "error", "created_at"}`;
   `originator` is always `null`: the customer never learns the shared line (shown as «خط اشتراکی» in
   reports, `shared_line_mask()`). The customer can also send a test code from the
   panel page.

   **From the send pages.** «ارسال پیامک» (`send.php`) and «پنل جدید ارسال» (`new-send.php`) carry an
   «ارسال کد تایید اشتراکی» box above the composer while the service is on
   (`app/views/shared_otp_send_card.php`): pick an approved template, type one mobile number and a
   value for each of its variables. It posts `do=shared_otp_send` back to the same page, which hands it
   to `shared_otp_handle_send_form()` before its own POST handling — the same `shared_otp_send()` as
   the API, recorded with source `panel`, blocked during a support impersonation like any direct send.
   The box starts open for a customer with no sender line of their own, since it is then the only way
   they can send.

## What keeps a shared line safe to lend

- **The caller never picks the sender.** `originator`/`sender` in the body is rejected with `422`, as
  are the pricing fields every send endpoint rejects (`message_type`, `price`, …).
- **Only approved text goes out.** The fixed text is exactly what the admin read.
- **The variables can't carry an ad.** Every variable must be given, nothing else is accepted, and each
  value is 1–30 characters, on one line, without a link (`http…`, `www.`, `something.ir` …) and
  without braces.
- **One number can't be flooded.** A second code from the same customer to the same number within
  `shared_otp_min_interval_seconds` (default 60) answers `429` with `Retry-After`.
- **The line never becomes the customer's.** It is granted to exactly one `dispatch_message()` call
  (`shared_otp_with_line()`); `can_use_originator()`, `sendable_originators()` and the send pages do not
  change, so the same customer sending free text from that line on any other path is still refused.

## Sending, money and reports

The send goes through `dispatch_message()` — the same path as every single send:

- message type `otp`: the shared line's OTP tariff, priced server-side, reserved and settled from the
  **customer's own wallet** (ledger reference `shared_otp`); the organization's message quota counts it;
- the customer's send policy (IP allowlist, rate limit) applies, and so do prohibited words, support
  impersonation and suspended sub-accounts;
- the recipient's line opt-out (`11`) does not apply — OTP is exempt by default (`docs/line-optouts.md`);
- it is recorded like a direct send, so it shows in the customer's reports and delivery status.

**Which line.** The first line tried is picked by a hash of the recipient's number, so one recipient
keeps getting codes from the same line while the load spreads over all of them. When that line's
gateway cannot take the message (a retryable failure), the next line is tried; each attempt is its own
wallet/quota operation, fully released when it fails, so a fail-over never charges twice.

`ellsms_otp_messages` keeps one row per send (template, recipient, line, outcome) — never the rendered
text, so the code itself is not stored.

## Admin settings

Platform admin → کنترل ارسال → کد تایید اشتراکی (the same page):

| Setting | Meaning |
|---|---|
| `shared_otp_enabled` | Offer the service. Customers see the menu and the API answers only while it is on **and** a line is set. |
| `shared_otp_lines` | The shared sender lines. Each must be a number on **/numbers.php** with a route/gateway (the page warns about any that isn't). They are not assigned to anyone. |
| `shared_otp_min_interval_seconds` | Minimum gap between two codes to one number from one customer (0 = no limit). |

Templates are listed with a status filter (pending first); approve, reject (with a reason) or disable.

## API reference

| | |
|---|---|
| `POST /api/v1/otp` | send — see above. `422` validation (unknown/unapproved template → `template_id`, bad values → `parameters.<name>`), `429` too soon, `402` the send failed (credit, quota, gateway), `503` service off |
| `GET /api/v1/otp/templates?status=` | the organization's templates with `variables`, `status` and `review_note` |
| `GET /api/v1/otp/{id}` | one send |

All three need the `otp:send` scope; `client_message_id` / `Idempotency-Key` work on `POST /otp` as on
`POST /messages`. A key whose creator has the «کد تایید اشتراکی» menu closed is refused, like any other
menu-mapped route.

## Files

| | |
|---|---|
| Schema | `db/migrations/2026_10_25_shared_otp.sql` |
| Logic | `app/SharedOtp.php`; the line grant in `dispatch_message_raw()` (`app/backend.php`) |
| API | `app/Api/Handlers/SharedOtp.php`, routes in `public/api/index.php` |
| Panel | `public/otp-templates.php` (`/integrations/otp`); the send-page box `app/views/shared_otp_send_card.php` |
| Tests | `tests/Unit/SharedOtpTest.php`, `tests/Integration/SharedOtpTest.php`, `tests/Integration/SharedOtpHttpTest.php` |
