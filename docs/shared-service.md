# Shared sending service (سرویس ارسال اشتراکی)

A customer sends a message by giving **only the recipient and the text**. The sender line is the
platform's own, picked from a pool, so:

- the customer needs no line of their own and never sees or chooses a sender number;
- the platform can switch provider without the customer noticing: disable the old provider's lines in
  the pool and enable the new provider's lines;
- every text goes through content control, and anything doubtful waits for a human before it is sent.

It sits beside the shared OTP (`docs/shared-otp.md`), which sends fixed templates. This service takes
free text and controls it by review.

## Two kinds, two pools, two tariffs

| | خدماتی (`service`) | تبلیغاتی (`promo`) |
|---|---|---|
| Use | transactional: orders, bills, reminders | advertising |
| Message type (pricing, opt-out) | `transactional` | `promotional` |
| Hours | any time | only inside `shared_service_promo_start`–`end` (default 08:00–21:00); outside them the message waits (`queued`, `send_after`) and goes out at the start of the next window |
| Customer blacklist (`/blacklist.php`) | not applied | applied: refused |
| Opt-out «۱۱» | the sending line's own opt-outs, as for any send | an opt-out to **any** promo pool line refuses the message, so changing lines never re-reaches someone who opted out |
| Price row | «سرویس اشتراکی خدماتی» | «سرویس اشتراکی تبلیغاتی» |

### The pool

Platform admin → کنترل ارسال → **سرویس ارسال اشتراکی** (`/shared-service.php`, clean URL
`/messages/shared`). Each pool line is a number already on **/numbers.php**; its route or pinned gateway
decides which provider carries it (`docs/number-gateway.md`). A line serves one kind and has a priority:

- enabled lines of the kind are tried **lowest priority number first**;
- lines with the **same priority share the load**: the first one is picked by a hash of the recipient's
  number, so one recipient keeps hearing from the same line;
- when a line's gateway cannot take the message (a retryable failure), the **next line** is tried. Each
  attempt is its own wallet/quota operation, fully released when it fails, so a fail-over never charges
  twice.

**Switching provider:** add the new provider's line, enable it (or give it the better priority), disable
the old one. Customers, their API calls and their messages are untouched.

### Tariff

The price matrix (تعرفه‌ی پیامک → تعرفه‌ی درگاه‌ها, and each account's تعرفه‌ی اختصاصی) has two more rows
under the gateways, like one more provider each: «سرویس اشتراکی خدماتی» and «سرویس اشتراکی تبلیغاتی».
They are stored in `ellsms_gateway_prices` as virtual gateways (`gateway_id` NULL, `gateway_slot`
`4294967001` / `4294967002`, `GATEWAY_PRICE_SHARED_*` in `app/GatewayPrices.php`). A shared-service
message is priced, per destination column and encoding:

1. the account's own shared row → exact price;
2. else the global shared row → × the account's tariff percentage;
3. else exactly as any send from that line (the line's gateway cell, the default row, the route price).

So the customer's price does not depend on which line or provider happened to carry the message. An
account under a reseller is priced down its chain as usual; the chain top's own shared row is used when it
has one. The ↓ fill buttons of the matrix fill gateway rows only, never the shared rows.

## Content control

In order, before anything is sent:

1. **Prohibited words** (`docs/prohibited-words.md`) refuse the message outright (`422 prohibited_content`).
2. **Promotional checks:** the customer's blacklist and «۱۱» opt-outs (table above).
3. **Human review:** each text has a *shape* (`shared_service_fingerprint()`): the text normalized the way
   prohibited words are (Persian/Arabic letters, invisible marks, spacing, case, digits) and every run of
   digits, with any `. , / : -` between them, replaced by one placeholder. So
   «سفارش 1234 به مبلغ 250,000 ریال» and «سفارش 98 به مبلغ 12000 ریال» are the same shape. Each customer has
   a **trust level**:

   | Trust | Held for review |
   |---|---|
   | `trusted` (مورد اعتماد) | only a shape not yet approved for this customer (`hold_reason` = `new_text`) |
   | `supervised` (تحت نظر) | every message (`hold_reason` = `supervised`) |

   A customer without an explicit level gets `shared_service_default_trust` (default `supervised`, the
   safe choice). A shape that was **rejected** is refused immediately on later submissions
   (`422 text_rejected`, with the reviewer's reason).

### Review

The review queue on the same admin page groups held messages by shape:

- **تأیید و ارسال**: releases the group's messages (sent now, a promotional one at the next window) and
  approves the shape, so the customer's later messages of that shape skip review (when they are trusted).
- **فقط همین پیام‌ها**: releases the messages without approving the shape.
- **رد**: needs a reason; rejects the messages and the shape, and the customer gets a panel notification
  (`shared_service.rejected`).
- Messages can also be approved or rejected one at a time (useful for supervised customers).
- An approved or rejected shape can be sent back to review («بازگشت به بررسی»).

A held message nobody reviews within `shared_service_hold_hours` (default 24) **expires** and is never
sent. The admin menu entry shows how many messages are waiting.

## Money

Nothing is reserved while a message is held or queued. It is priced, reserved and charged from the
customer's **own wallet** through `dispatch_message()` at the moment it is sent (ledger reference
`shared_service`, «سرویس ارسال اشتراکی» in the financial log), with the organization's message quota,
the customer's send policy, suspended sub-accounts and support impersonation all applying as for any
send. If the wallet is short at that moment, the message fails with that reason.

### Lines per customer (خط هر کاربر)

On the same admin page, **خط هر کاربر** decides which pool lines — and so which route/provider — a
customer's messages go through (`ellsms_shared_user_lines`, migration
`2026_10_31_shared_service_user_lines.sql`, `shared_service_pool_for()`):

- the admin enters a user id and ticks pool lines; the list below shows every customer with lines;
- a customer with ticked lines of a kind is sent **only** through those lines of that kind that are
  enabled, in their priority order with the usual fail-over among them;
- a kind with no ticked line uses the whole pool of that kind (the default), so assigning service lines
  leaves promo untouched;
- when every ticked line of a kind is disabled, that kind stops for the customer (`not_enabled`) rather
  than spilling onto lines the admin did not choose; removing a pool line removes its assignments.

The customer never sees any of this: no number, no route name — only «خط اشتراکی».

## Hiding the line from the customer

`shared_line_mask()` shows a customer «خط اشتراکی» (`SHARED_LINE_LABEL`) in place of any platform shared
line — every pool line here and every shared-OTP line (`shared_line_numbers()`, cached for a minute) — in
the send report list and message detail, report exports, and delivery webhooks. The shared-service and
shared-OTP API objects return `originator: null`. Admins see the real line everywhere.

## The line never becomes the customer's

The pool line is granted to exactly one `dispatch_message()` call (`shared_otp_with_line()`, the same
grant the shared OTP uses). `can_use_originator()`, `sendable_originators()` and the send pages are
unchanged, so the same customer sending free text from that line on any other path is refused.

## Customer side

Sidebar → ارسال پیامک → **ارسال با خط اشتراکی**, shown once the service is on and a pool has a line.
The form takes the kind (only kinds with an enabled line are offered), the recipient and the text; the
list shows each message's status (`held` with the reason, `queued` with its time, `sent`, `failed`,
`rejected` with the reviewer's reason, `expired`). An account whose menus are restricted
(`docs/resellers-and-menu-access.md`) needs the «ارسال با خط اشتراکی» menu opened for it, and the
API follows the same menu.

### API

Scope `shared:send` (`docs/openapi-v1.yaml`):

```http
POST /api/v1/shared/messages
Authorization: Bearer ellsms_live_…
Content-Type: application/json

{"destination": "09121234567", "text": "سفارش 1234 شما ارسال شد.", "line_type": "service"}
```

| Answer | Meaning |
|---|---|
| `201` | sent; `data.originator` is always `null` (the line is never disclosed) |
| `202` | accepted and waiting: `status` `held` (with `hold_reason`) or `queued` (with `send_after`) |
| `402` | not sent: credit, quota or every line's gateway refused |
| `422` | validation: `originator`/`sender`/`from`, pricing fields, bad `line_type`, prohibited content, rejected text, blacklisted / opted-out recipient (`error.reason` names it) |
| `503` | service off |

`GET /api/v1/shared/messages/{id}` returns the same object, for following a held or queued message.
`client_message_id` / `Idempotency-Key` work as on `POST /messages`.

## Worker

`cron/shared-service-worker.php`, scheduler job `shared-service`, every minute: expires held messages past
the review deadline, sends approved messages an approval did not send itself (an approval sends up to
200 at once), and sends promotional messages whose window has opened. A MySQL named lock keeps one pass at
a time.

A message is claimed (`queued` → `sending`) by one atomic update before it is sent, so an approval and a
worker pass never send it twice. A process that dies mid-send leaves the row in `sending`; it is not
retried automatically (the wallet side is safe: its reservation is per line attempt and replay-safe), so
look at such rows by hand.

## Settings

| Setting | Default | |
|---|---|---|
| `shared_service_enabled` | `0` | offer the service |
| `shared_service_promo_start` / `_end` | `08:00` / `21:00` | promotional hours, local time; end before start runs over midnight, equal means all day |
| `shared_service_hold_hours` | `24` | review deadline |
| `shared_service_default_trust` | `supervised` | trust level of a customer without one |

## Files

| | |
|---|---|
| Schema | `db/migrations/2026_10_31_shared_service.sql` |
| Logic | `app/SharedService.php`; price rows in `app/GatewayPrices.php` and `sms_pricing_price_messages()` (`app/Sms/Pricing.php`) |
| API | `app/Api/Handlers/SharedService.php`, routes in `public/api/index.php` |
| Panel | `public/shared-service.php`, price rows in `app/views/price_matrix.php` |
| Worker | `cron/shared-service-worker.php` |
| Tests | `tests/Unit/SharedServiceTest.php`, `tests/Integration/SharedServiceTest.php` |
