# Provider push callbacks: `/mo` and `/delivery`

ELLSMS can receive inbound SMS (MO) and delivery reports (DLR) that the SMS provider **pushes** to it, with the same URL shapes the backend platform used. A provider panel only needs the new host. Code: `app/Sms/GatewayCallbacks.php`, entrypoints `public/mo.php` and `public/delivery.php`.

## Inbound SMS (MO)

```
GET|POST https://<host>/mo?from=989197684063&to=5000435800&content=test
```

| Parameter | Accepted names |
|---|---|
| sender | `from`, `originator`, `sender`, `src`, `source`, `source_addr`, `mobile`, `msisdn` |
| line | `to`, `destination`, `receiver`, `dst`, `dest`, `destination_addr`, `line`, `number` |
| text | `content`, `text`, `message`, `body`, `msg`, `sms`, `short_message` |
| optional | `id` (provider message id, used for de-duplication), `time` (receive time; no zone = Tehran), `coding` (`8`/`ucs2` = UCS-2 hex text), `gateway` (gateway code or id) |

Parameters can arrive as a query string, a form body or a JSON body. The message is stored in `ellsms_inbound_messages`, so the inbox, auto-reply, webhooks and number forwarding handle it exactly like a polled one. Gateway: `?gateway=` if given, else the gateway of the line's route, else the default gateway.

**Persian and emoji:** the table and the connection are utf8mb4. Text arrives as normal percent-encoded UTF-8 (`%D8%B3...`, emoji `%F0%9F%98%80`); `%uXXXX` escapes, UCS-2 hex (with `coding=8`) and Windows-1256 are converted too.

## Delivery reports (DLR)

```
GET|POST https://<host>/delivery?id=<provider message id>&status=DELIVRD&dl_time=2026-10-18 12:00:00
```

`/dlr` is an alias. Id names: `id`, `msgid`, `message_id`, `smsid`, `refid`, … · status names: `status`, `state`, `stat`, `dlr` · time: `dl_time`, `done_date`, `delivered_at`, `time`.

The report is matched by provider message id against messages ELLSMS sent itself through a gateway (`ellsms_bulk_items`, `ellsms_message_attempts`). Messages sent through the legacy backend API live in the backend's `outbound_message` and still get their reports from the backend. The status token is mapped with the gateway's own status mapping first (where numeric codes belong), then with built-in defaults (`DELIVRD`, `UNDELIV`, `REJECTD`, `EXPIRED`, `ENROUTE`, `delivered`, `failed`, …). An unmapped token becomes `unknown`, never `delivered`. States never go backwards: a late `sent` cannot overwrite `delivered`.

Responses: `200` stored/applied · `400` missing parameters · `403` refused by the guard · `404` unknown message id · `409` the id exists on two gateways (pass `gateway=`).

## Security

| `.env` | Effect |
|---|---|
| `SMS_CALLBACK_TOKEN` | Required as `?token=…` or an `X-Callback-Token` header |
| `SMS_CALLBACK_ALLOWED_IPS` | Comma-separated IPs/CIDRs the provider calls from |

With both empty the endpoints are open, as the backend platform's were. Set at least one in production. Both endpoints stay reachable during maintenance mode, because most providers do not retry an MO.
