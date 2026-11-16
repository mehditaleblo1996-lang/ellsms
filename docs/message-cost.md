# What one message cost (هزینه‌ی هر پیام در جزئیات پیام)

The detail drawer of every message in «لیست پیام‌های ارسالی» (`/messages/reports`, the eye button)
has a **«هزینه و پیگیری»** section that spells out the charge of that one message:

> **۲ پارت × ۱,۱۲۴ ریال = ۲,۲۴۸ ریال** — `کسر شد` / `برگشت شد`

with «تعداد پارت», «قیمت هر پارت» (the price when it was sent), «مبلغ کسرشده», and — when part or all of
it came back — «مبلغ برگشتی» with its time and «هزینه‌ی نهایی».

Code: `sent_report_cost_breakdown()`, `sent_report_cost_state()`, `sent_report_cost_formula()` in
`app/Reports/SentMessages.php`; view `app/views/sent_message_detail.php`. No migration, no API route.

## Where each figure comes from

Nothing is re-priced against today's tariff:

| Message | Unit price | What was charged |
|---|---|---|
| bulk (p2p, smart, gradual, imports …) | frozen on the row at acceptance (`ellsms_bulk_items.unit_price_millicredits`) | the row's own ledger entry (`sms_debit`, idempotency key `commit:bulk_item:{id}`); no entry = no charge |
| direct / API / scheduled / auto-reply | the send's price snapshot (`ellsms_sms_price_snapshots`), the group of this recipient's operator and route | `ellsms_message_attempts.charged_credits`; for a send to a single recipient whose per-recipient charge was not written, the snapshot's committed cost |
| legacy backend sends | — | not recorded per message; the drawer says so (the total is in «تاریخچه مالی») |

A refund is the message's `ellsms_delivery_refunds` row (`docs/delivery-based-billing.md`,
`docs/delivery-states.md`). The amount in the formula is parts × unit price rounded up once, which is
how a message is priced (`docs/sms-pricing.md` §2).

## States

| Label | When |
|---|---|
| «کسر شد» | charged, nothing returned |
| «برگشت شد» | the whole charge came back |
| «بخشی برگشت شد» | part of it came back (delivery-based billing below 100%) |
| «کسر شد — در صف برگشت» | admins and delivery-based-billing customers only: a failed / rejected / expired report on a message sent after «failed messages are free», refunded within minutes by the status path or the `delivery-refunds` job |
| «کسر نشد» | the message failed at the provider or the send was cancelled (the reservation came back) |
| «بدون هزینه» | sent without a charge (an admin's own send) |
| «رزرو شده» | a bulk row still waiting to be sent: its price is reserved, charged when it goes |
| «نامشخص» | the charge of this one message is not recorded (legacy sends; a multi-recipient direct send whose per-recipient charge was not written) |

Tests: `tests/Unit/MessageCostStateTest.php`, `tests/Integration/MessageCostBreakdownTest.php`.
