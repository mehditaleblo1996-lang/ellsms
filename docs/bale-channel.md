# Bale messenger channel (#42)

A second sending channel next to SMS: the message is delivered to the recipient's **Bale** account
through Bale's business API — typically cheaper than SMS for OTPs and notifications.
`app/BaleChannel.php`.

## Setup

1. Put the Bale account's access key in `.env` as `BALE_API_ACCESS_KEY` (it is read only from the
   environment and never stored in the database) and restart the `app` container.
2. As a platform admin open **/messages/bale** and set: enabled, the service base URL, the numeric bot id,
   the per-second cap, and the price per message in credits.

The channel appears on the send page and in the API only while all of that is in place
(`bale_configured()`).

## Using it

| Where | How |
|---|---|
| Public API `POST /api/v1/messages` | `"channel": "sms"` (default) \\| `"bale"` \\| `"bale_sms"`; the response adds `channel` and `sent_by_channel: {"bale": n, "sms": n}` |
| Send page, direct mode | "کانال ارسال" select |

- `bale` — Bale only; a recipient without a Bale account is not reached.
- `bale_sms` — Bale first, then **SMS for whoever Bale did not accept** (no account, error). Each channel is
  charged only for what it delivered: Bale per message at the Bale price, SMS by segments at the SMS rate.

## Behaviour

- Request (as Vesal's `BaleDispatchJob` sends it): `POST {base}/api/v3/send_message`, header
  `api-access-key`, body `{"bot_id": <number>, "phone_number": "98912…", "message_data": {"message": {"text": "…"}}}`;
  success = HTTP 2xx with a `message_id`.
- Quota and money mirror `dispatch_message()`: quota and price × recipients are reserved first, only accepted
  messages are committed, the rest is released; the same reference replayed (API `client_message_id`) is not
  sent again.
- Prohibited words (#40) apply. Per-line opt-out (#38) does not — a Bale message has no sender line.
- The endpoint goes through the same SSRF checks as SMS gateways (HTTPS and public addresses in production).
- Per-second cap: a token bucket per process (`bale_tps`).
- Every recipient is recorded in `ellsms_channel_messages` (status, Bale message id, error, cost), shown on
  /messages/bale — customers see their own organization's messages, admins see all and the errors.

Not covered here: bulk / scheduled jobs still go by SMS; Bale delivery receipts are not polled (Bale's
acceptance is the final state recorded).

Tests: `tests/Integration/BaleChannelTest.php` (against a Bale-shaped endpoint), plus the `channel`
validation in `tests/Integration/PublicApiHttpTest.php`.
