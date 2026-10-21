# WebEngage SMS service provider (SSP)

ELLSMS can be added in WebEngage as a custom **SMS Service Provider**. WebEngage then sends campaign
SMS through ELLSMS, and ELLSMS reports each message's delivery status back to WebEngage.

Code: `app/WebEngage.php` (payload, codes, settings, DSNs), `app/Api/Handlers/WebEngage.php` (the
endpoint), `cron/webhook-worker.php` (DSN delivery). Schema: `db/migrations/2026_10_24_webengage.sql`.

## Setup (per organization)

1. The public API must be on (`API_ENABLED=1`) and `WEBHOOK_MASTER_KEY` set (it encrypts the DSN token).
2. **/api-keys.php** — create a key with the `messages:send` scope.
3. **/integrations.php → WebEngage** — tick *active*, pick the WebEngage data center (US, India, KSA, or a
   custom URL for a Private SSP webhook) and enter the DSN token WebEngage issued. A blank token field
   keeps the stored token. Saving needs the `api_keys.manage` permission.
4. In WebEngage, add an SMS Service Provider with:
   - **Endpoint:** `https://<panel host>/api/v1/webengage/sms`
   - **Authentication:** Bearer with the API key, or Basic with any username and the API key as the password.

The key's owner is the sending account: its lines, credit, plan quota, blacklist and menu access all
apply, exactly as for `POST /api/v1/messages`. The IP allowlist on **/api-keys.php** applies too.

## Request

```json
{
  "version": "1.0",
  "smsData": { "toNumber": "+989121234567", "fromNumber": "5000123", "body": "…" },
  "metadata": { "campaignType": "PROMOTIONAL", "timestamp": "…", "messageId": "…", "custom": {} }
}
```

Versions `1.0` and `2.0` are accepted (`indiaDLT` and other extra metadata are ignored). An empty
`fromNumber` uses the key owner's default line. `toNumber` is normalized like any API destination and
echoed back unmodified in the DSN.

## Response

Accepted: `200 {"status":"sms_accepted"}`. Rejected: `{"status":"sms_rejected","statusCode":N,"message":"…"}`
with the HTTP status WebEngage's spec pairs with N:

| Code | HTTP | When |
|---|---|---|
| 2000 | 200 | Not enough credit, or the plan's message allowance is used up |
| 2001 | 401 | Source IP not in the organization's allowlist |
| 2002 | 400 | Empty body, or no `smsData`/`metadata` |
| 2003 | 400 | `toNumber` is not a valid mobile number |
| 2004 | 400 | `fromNumber` is not a line the key owner may send from |
| 2005 | 403 | Key lacks `messages:send`, plan/subscription/menu refusal, inactive account, or WebEngage not enabled for the organization |
| 2007 | 413 | Body longer than 2000 characters, or request too large |
| 2010 | 400 | Unsupported `version` (with `"supportedVersion":"2.0"`) |
| 2011 | 401 | Missing or invalid API key |
| 2015 | 429 | Rate limited, or the same `messageId` is still being processed — WebEngage retries |
| 3000 | 403 | Recipient is blacklisted or opted out of the line |
| 9988 | 200* | Anything else (prohibited content, gateway failure, …) — see `message` |

\* 9988 keeps the HTTP status of the failing gate where one applies (e.g. 400 for invalid JSON).

**Retries are safe.** Each `messageId` is recorded per organization (`ellsms_webengage_messages`); a
repeated request gets the first answer again and is never sent twice.

## Delivery status notifications (DSN)

Sent only for accepted messages, by the `webhook-worker` service, to the selected data center's
tracking endpoint with `Authorization: Bearer <DSN token>`:

```json
{ "version": "1.0", "messageId": "…", "toNumber": "+989121234567", "status": "sms_sent", "statusCode": 0, "smsCount": 1 }
```

| Gateway delivery state | DSN |
|---|---|
| `delivered` | `sms_sent`, 0 |
| `expired` | `sms_failed`, 2008 |
| `failed`, `rejected` | `sms_failed`, 2009 (with `message`) |
| no report within `WEBENGAGE_DSN_MAX_WAIT_HOURS` (24) | `sms_sent`, 0 — the provider accepted it |
| sent by the legacy backend API (no provider id to track) | `sms_sent`, 0, right away |

A message sent through a gateway waits for its real delivery report: `gateway_status_record()` queues
the DSN when the message's `ellsms_message_attempts` row reaches a final state (polled or pushed to
`/delivery`). Failed DSN calls are retried with the job backoff up to `WEBENGAGE_DSN_MAX_ATTEMPTS` (8);
4xx answers other than 408/425/429 are not retried. The last 20 messages, with their delivery state and
DSN status, are listed on **/integrations.php**.

| `.env` | Default | Effect |
|---|---|---|
| `WEBENGAGE_DSN_MAX_ATTEMPTS` | 8 | Attempts before a DSN is marked `failed` |
| `WEBENGAGE_DSN_MAX_WAIT_HOURS` | 24 | Wait for a delivery report before reporting `sms_sent` |
| `WEBENGAGE_MESSAGE_RETENTION_DAYS` | 90 | `make webhook-prune` removes finished message rows older than this |

A custom DSN URL passes the same SSRF check as webhook endpoints (HTTPS, public address), at save time
and before every call.
