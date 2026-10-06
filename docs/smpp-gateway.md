# SMPP gateways — #46

A gateway can now use **SMPP** instead of HTTP. SMPP needs long-lived TCP sessions, which PHP-FPM
cannot hold, so they live in a small Java service, **`smpp-bridge`** (`smpp-bridge/`, Java 21 + jSMPP).
Everything is configured and monitored from **Admin → درگاه‌های پیامک**.

```
 PHP (app / workers)                       smpp-bridge (Java)                     operator SMSC
 gateway_send() ──HTTP /v1/submit──►  sessions (TRX | TX+RX | TX) ──submit_sm──►
                                         rate limit (TPS), window, retry on throttle
 status-worker ◄── ellsms_smpp_events ◄── deliver_sm (receipt / MO) ◄────────────
 panel        ◄── ellsms_smpp_sessions ◄── session state every 5 s
                 shared MySQL: the bridge reads gateway config + encrypted password from it
```

## Setup

1. Apply the migration: `make db-migrations-apply` (`db/migrations/2026_10_06_smpp_gateway.sql`).
2. In `.env`:
   - Set `SMPP_BRIDGE_TOKEN` (≥ 16 chars; `openssl rand -hex 24`).
   - Make sure `SMS_GATEWAY_MASTER_KEY` is set. The bridge decrypts SMPP passwords with the same
     key derivation PHP uses.
3. Build and start the bridge: `docker compose build smpp-bridge && docker compose up -d smpp-bridge`,
   then restart `app`, `worker` and `bulk-worker` so they pick up `SMPP_BRIDGE_URL` / `SMPP_BRIDGE_TOKEN`.
4. Create the gateway in the panel:
   - **درگاه جدید**: choose protocol **SMPP**.
   - On **تنظیمات SMPP و مانیتورینگ**, enter host, port, `system_id` and password (≤ 8 characters,
     an SMPP limit), plus bind type, number of sessions, TPS and TON/NPI as the operator specifies.
   - Press **اتصال آزمایشی** to test the bind.
   - Pick the operators the gateway carries, then assign it to a route or to lines, exactly as for an HTTP gateway.
5. Sending through gateways requires `SMS_GATEWAY_TRANSPORT=1`, as for every gateway.

## What the bridge does

- **Sessions.** It keeps 1–10 sessions per gateway bound: TRX, TX + RX pairs, or TX only.
  - `enquire_link` is sent at the configured interval.
  - After a disconnect it reconnects with backoff (from `reconnect_delay_s`, doubling up to 60 s).
  - A panel change bumps `config_version`. The bridge picks it up within `SMPP_BRIDGE_RELOAD_SECONDS`
    (the panel also asks for an immediate reload) and rebuilds only that gateway.
- **Sending.**
  - Encoding is GSM 7-bit when every character fits, otherwise UCS-2 (or forced from the panel).
  - Long messages are split on character boundaries, never through a GSM escape or an emoji
    surrogate pair. They are sent as UDH, SAR TLVs or a single `message_payload`.
  - One token-bucket TPS limit applies per gateway, and each session has its own window.
  - `ESME_RTHROTTLED` / `ESME_RMSGQFUL` pause the gateway and retry the part (up to 3 times).
  - Other negative responses fail that recipient. Destination/source errors count as permanent;
    the rest are retryable.
  - Once the first part of a message is accepted it is reported as sent, so the beginning is never
    sent twice.
- **Delivery receipts.**
  - The bridge parses the `receipted_message_id` / `message_state` TLVs, or else the text receipt.
  - Receipts for later parts are mapped back to the first part's id.
  - Each receipt is stored in `ellsms_smpp_events` **before** `deliver_sm_resp`. If the database is
    down the bridge answers `ESME_RSYSERR` and the SMSC redelivers.
- **Received messages (MO).**
  - Decoded by data coding (GSM / UCS-2 / Latin-1 / binary as hex).
  - Concatenated parts (8- or 16-bit UDH, or SAR) are reassembled. After 3 minutes whatever arrived is stored.
- **API.**
  - Internal only, on the Docker network, not published; every call except `/health` needs the token.
  - Endpoints: `/v1/submit`, `/v1/status`, `/v1/reload`, `/v1/gateways/{id}/reconnect`, `/v1/gateways/{id}/test`.

## PHP side (app/Sms/Smpp.php)

- `gateway_compile()` → `smpp_gateway_compile()` builds the compiled gateway for protocol `smpp`.
  The cache, versioning, routes, number pinning and operator restrictions are unchanged.
- `gateway_send()` → `smpp_gateway_send()` calls the bridge and returns exactly the shape the HTTP
  path returns. Bulk batching, wallet settlement, retries, provider health and per-recipient content
  therefore work the same way.
- The **status-worker** runs `smpp_events_process_pass()`:
  - **Receipts** are matched to `ellsms_bulk_items` / `ellsms_message_attempts` by the gateway and the
    provider id, as-is or converted between hex and decimal according to `dlr_id_format`. They are
    applied with `gateway_status_record()`, which uses the same monotonic rules and delivery webhooks
    as polled statuses.
  - A receipt that arrives before its send was recorded is retried for about an hour.
  - **MOs** go into `ellsms_inbound_messages`, where the inbox, auto-reply and the customer database
    connector (#45) read them. A leading `98` is removed from the destination when the shorter number is a line.
- The active health checker probes the SMSC host and port over TCP, as it does for HTTP endpoints.

## Monitoring (panel)

- **Per session:** state, connected since, accepted / failed submits, throttled, receipts, MOs, in-flight, reconnects, last error.
- **Last 24 h:** receipts (delivered / failed / unmatched), MOs, events waiting to be processed.
- **Warnings** when the bridge is unreachable, when it is not running the gateway, or when the password cannot be decrypted.
- **Buttons:** test bind, reconnect all sessions.

## Development / tests

- `SMPP_SIMULATOR_PORT=2775` starts a fake SMSC inside the bridge:
  - It accepts any bind and acknowledges submits.
  - One second later it sends a receipt: `DELIVRD`, or `UNDELIV` for numbers ending in `0000`.
  - The text `THROTTLE` is refused once with `ESME_RTHROTTLED`.
  - `POST /v1/simulator/mo {from,to,text}` injects a received message.
  - Point a gateway at `smpp-bridge:2775`. **Never enable this in production.**
- `cd smpp-bridge && SMPP_TEST_DB_HOST=127.0.0.1 SMPP_TEST_DB_USER=… SMPP_TEST_DB_PASS=… mvn test`:
  codec, vault (against a PHP-encrypted vector), receipt parsing, rate limiter, and end to end against
  the simulator and a real MySQL.
- `tests/Integration/SmppGatewayBridgeTest.php`: PHP → bridge → simulator → receipts / MO → PHP.
  Needs a running bridge (`ELLSMS_TEST_SMPP_BRIDGE_URL`).
- `tests/Unit/SmppGatewayTest.php`: status mapping, id variants, settings validation.
