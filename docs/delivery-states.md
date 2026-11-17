# Delivery states, `undelivered` and free failed messages

Every message ELLSMS sends through a gateway carries a delivery state in `delivery_status`
(`ellsms_bulk_items`, `ellsms_message_attempts`). The state comes from the operator's delivery report,
after the gateway's status mapping translates the operator's own code. This page lists the states, says
what each one means for polling, billing and reports, and explains how operator codes are mapped.

Migration: `db/migrations/2026_11_19_undelivered_state.sql`. Code: `app/Sms/GatewayConnector.php`
(`GATEWAY_DELIVERY_STATES`, `gateway_status_map()`), `app/Sms/GatewayCallbacks.php`,
`app/DeliveryRefunds.php`. Tests: `tests/Unit/UndeliveredStateTest.php`,
`tests/Integration/DeliveryRefundsTest.php`.

## The states

| Mapping target | Stored as | Final? | Charged? | API `state` | «گزارش وضعیت‌ها» |
|---|---|---|---|---|---|
| `accepted`, `queued`, `sent` | itself | no, polling goes on | yes | `sent` | رسیده به مخابرات |
| `unknown` | `unknown` | no | yes | `unknown` | رسیده به مخابرات |
| `delivered` | `delivered` | yes | yes | `delivered` | رسیده به گوشی |
| `undelivered` | `undelivered` | yes | **yes, never refunded** | `undelivered` | نرسیده به گوشی |
| `failed` | `failed` | yes | **no: refunded in full** ¹ | `undelivered` | نرسیده به گوشی |
| `rejected` | `rejected` | yes | **no: refunded in full** ¹ | `undelivered` | نرسیده به گوشی |
| `expired` | `expired` | yes | **no: refunded in full** ¹ | `undelivered` | نرسیده به گوشی |
| `blacklisted` | `rejected` | yes | **no: refunded in full** ¹ | `undelivered` | لیست سیاه |

¹ For messages sent at or after the cutoff (below). Older messages keep the old rule: refunded only
under delivery-based billing, at its percentage.

A final state never changes afterwards: a later report (even `delivered`) is ignored.

`undelivered` («تحویل داده‌نشده») is the operator's "did not reach the handset" kept apart from
`failed`. It is final, so it stops polling, and the message stays charged. Use it for codes after which the
operator does not try again. A code the operator may still turn into delivered (PishgamRayan's `4` while
the operator is retrying the handset) belongs on `sent` instead.

In aggregated views (dashboard, operator report, status report, exports, API) `undelivered` is counted with
the other "not delivered" states. Per-message views show its own label «تحویل داده‌نشده». Webhooks send it
as `message.undelivered`, WebEngage as `sms_failed`, DLR forwards (Vesal format) as `3`, and the remote-DB
sync as `NOT_DELIVERED`. «ارسال مجدد ناموفق‌ها» offers it like the other not-delivered rows.

## Mapping operator codes

Platform Admin → «درگاه‌های پیامک» → gateway → status connector → «نگاشت وضعیت تحویل (JSON)»:
the operator's code is the key and one of the mapping targets above is the value. Example:

```json
{"0": "queued", "1": "sent", "2": "failed", "3": "blacklisted", "4": "undelivered", "5": "delivered", "-1": "sent", "10": "delivered", "13": "failed"}
```

### Codes that are not in the mapping

| Gateway | Code in the answer | Result |
|---|---|---|
| has a status mapping | a code not listed in it | **`failed`**: final and refunded. Logged as `gateway.status.unmapped_token` (metric `gateway_status_unmapped_token`); the raw code stays in `provider_status` for the admin |
| has a status mapping | empty, or no code at all | `unknown`, polling goes on |
| has no status mapping | anything | `unknown` (as before) |

Push callbacks (`/delivery`) follow the same rule. The gateway's mapping is checked first, then the built-in
tokens (`DELIVRD`, `UNDELIV`, `REJECTD`, `EXPIRED`, `ENROUTE`, …, `SMS_CALLBACK_DEFAULT_STATUSES`). Only
a token that neither of them knows becomes `failed`. No code ever becomes `delivered` by default.

### SMPP gateways

An SMPP gateway has no status connector: its receipts carry a standard `stat` (`DELIVRD`, `UNDELIV`,
`EXPIRED`, `REJECTD`, …), mapped by the gateway's own «نگاشت وضعیت رسید تحویل» on its SMPP settings
card (`db/migrations/2026_11_20_smpp_status_mapping.sql`). By default `UNDELIV` and `EXPIRED` are
`undelivered` (final, charged — the operator bills them), `REJECTD` is `rejected` and `DELETED` `failed`
(both refunded). A `stat` it does not know stays `unknown` rather than `failed`, because a receipt is
pushed once and never polled again. See `docs/smpp-gateway.md` §Delivery receipts.

After a mapping change, rows the old mapping made final can be reopened with
`cron/gateway-status-reopen.php` (see `docs/sms-gateway-connectors.md`). It now includes `undelivered`
rows.

## Free failed messages

The migration writes the setting `delivery_failed_free_since` (the moment it ran, database clock; never
overwritten on a rerun). A message **sent** at or after that moment whose final state is `failed`,
`rejected` or `expired` (`blacklisted` is stored as `rejected`) is refunded 100% of its charge for **every**
customer, whether or not «ارسال بر اساس تحویل» is on. The refund is recorded like any delivery refund
(`ellsms_delivery_refunds` with `period_id = 0`, `refund_percent = 100`, wallet type `delivery_refund`,
exactly once), so it shows in «گزارش مالی» and in the customer's refund list.

* A send the gateway refused outright was never charged, so there is nothing to refund.
* `undelivered`, `sent`, `unknown` and `delivered` are not refunded.
* Messages sent before the cutoff are untouched: the old delivery-billing rule still applies to them.
* The scheduler job `delivery-refunds` also sweeps these refunds (all customers, within
  `DELIVERY_REFUND_LOOKBACK_DAYS`), so a missed refund is still made.

«ارسال بر اساس تحویل» still decides who sees delivery states in the reports
(`docs/delivery-based-billing.md`).

## Migration

`db/migrations/2026_11_19_undelivered_state.sql` appends `undelivered` to both `delivery_status` ENUMs.
Appending keeps every stored value, so no existing row changes. It also inserts the
`delivery_failed_free_since` setting. Both steps are guarded and safe to run again.
