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

1. Apply the migrations: `make db-migrations-apply` (`db/migrations/2026_10_06_smpp_gateway.sql`, and
   `2026_11_20_smpp_status_mapping.sql` for the receipt mapping; `./deploy.sh` applies them).
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
   - Check **نگاشت وضعیت رسید تحویل** (below).
   - Press **اتصال آزمایشی** to test the bind.
   - Pick the operators the gateway carries, then assign it to a route or to lines, exactly as for an HTTP gateway.
   - Optionally give it its own prices on **تعرفه‌ی پیامک → تعرفه‌ی درگاه‌ها** (`docs/gateway-price-matrix.md`);
     without them the default row prices it.
5. Sending through gateways requires `SMS_GATEWAY_TRANSPORT=1`, as for every gateway.

## The 192.168.111.80 gateway

`db/migrations/2026_11_21_smpp_gateway_192_168_111_80.sql` creates the gateway **`smpp_192_168_111_80`**
(«SMPP 192.168.111.80») for the SMSC at **`192.168.111.80:40112`**, exactly as «درگاه جدید» → SMPP
would. It is not the default gateway and no route or line uses it, so nothing sends through it yet. It is
idempotent and never overwrites the row, so what is entered in the panel survives a re-run.

It has the panel defaults: one TRX session, 50 TPS, window 10, source TON/NPI 5/0, destination 1/1 in
international format (`98912…`), automatic GSM/UCS-2, UDH for long messages, receipts requested,
received messages stored, the default receipt mapping. **No credentials are stored**: until an admin
enters `system_id` and the password on its «تنظیمات SMPP», the panel (and `/v1/status`) shows
`no smpp_password secret stored` and nothing binds. Then:

1. Admin → درگاه‌های پیامک → «SMPP 192.168.111.80» → enter `system_id` and password; adjust bind type,
   TPS, sessions and TON/NPI to the operator's specification → «ذخیره‌ی تنظیمات SMPP».
2. «اتصال آزمایشی». The bridge container must reach `192.168.111.80:40112` (a private address: the
   Docker host needs a route to it; the bridge is not subject to the public-address rule of HTTP gateways).
   A failure is diagnosed (see «اتصال آزمایشی» and its diagnosis below). "Time out waiting for bind
   response" means TCP connected but the SMSC did not answer the bind — most often the server's address
   is not on the SMSC's allow list.
3. Assign it to a route or to a line (numbers page), send a test message and watch its receipt in the
   session table and on «لیست پیام‌های ارسالی».

To remove it, archive it in the panel; the migration does not recreate an archived gateway (it checks
the code, whatever the status).

## «اتصال آزمایشی» and its diagnosis

The button opens one extra, throw-away session with the stored settings (`POST /v1/gateways/{id}/test`,
`smpp-bridge` `BindTester`). When the bind fails it finds out why, and the panel says what to change
(`smpp_test_bind_message()` in `app/Sms/Smpp.php`). Steps, each bounded (worst case about 25 s):

1. TCP connect to host:port (5 s).
2. bind as configured (15 s).
   If none of the gateway's own sessions is bound, they are stopped during the test (they would only
   hold the connection an SMSC with a one-session limit allows) and rebuilt right after it
   (`gateway_sessions_paused`).
3. Only when the bind got **no answer**: the gateway's own sessions; a bare `enquire_link` on a new
   connection (any SMPP server answers it, even before a bind); a TLS handshake when TLS is off and the
   port stayed silent; a `bind_transmitter` when a transceiver bind went unanswered on a port that
   speaks SMPP.

| `diagnosis` | Meaning / what to do |
|---|---|
| `ok` | bound; the SMSC's `system_id` is shown |
| `no_password` | no password stored yet: enter `system_id` and password |
| `tcp_refused` | nothing listens on that port: check host and port with the operator |
| `tcp_failed` | no TCP path from the `smpp-bridge` container (routing, VPN, firewall) |
| `invalid_password` / `invalid_system_id` | the SMSC refused the credentials (`0x0E` / `0x0F`) |
| `already_bound` | `ESME_RALYBND`: session limit reached, or the account is bound from elsewhere |
| `bind_failed` / `bind_rejected` | `ESME_RBINDFAIL` (often: source IP not allowed, account disabled) / another `command_status` |
| `closed_by_peer` | the SMSC closed the connection at once: usually the source IP is not allowed or the sessions are full |
| `session_limit` | the gateway's own session IS bound — the settings work; the SMSC just does not answer an extra session |
| `use_tx_rx` | no answer to `bind_transceiver`, but `bind_transmitter` binds: set «نوع اتصال» to TX + RX |
| `enable_tls` / `not_smpp_tls` | the port speaks TLS: turn on «اتصال TLS» |
| `not_smpp_http` | an HTTP service answers on that port: wrong port |
| `smsc_ignores_bind` | the port answers SMPP but not this bind: usually the source IP is not allowed for this account, or `system_id` / `system_type` is wrong |
| `second_connection_closed` | the bind went unanswered and a second connection was hung up at once (a transmitter bind was tried too): usually a stale connection still held by the SMSC or a TCP proxy (HAProxy) on an account that allows one session, a source IP not yet applied, or `system_id` / `system_type` / version not what the operator expects |
| `silent` | TCP connects but nothing answers at all (not bind, not `enquire_link`, not TLS): most often the source IP is not on the SMSC's allow list (some SMSCs drop such clients silently), else a wrong port, or a proxy / VPN in between that is not connected to the real SMSC |

**Checks on the server** (the bridge connects from the Docker host, NATed to the host's address on the
route to the SMSC — that is the address the operator must allow):

```bash
ip route get 192.168.111.80
docker exec ellsms-smpp-bridge bash -c 'timeout 5 bash -c "</dev/tcp/192.168.111.80/40112" && echo TCP-OK'
docker compose logs --tail=50 smpp-bridge
```

The first prints the source address (`src …`) the SMSC sees. The session table on the gateway page
shows the gateway's own sessions and their last error; they retry on their own with a growing delay.

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
  - Other negative responses fail that recipient with its `command_status`, which becomes the row's
    error code (e.g. `[0x0000000B]` for an invalid destination). Destination/source errors count as
    permanent; the rest are retryable.
  - Once the first part of a message is accepted it is reported as sent, so the beginning is never
    sent twice.
  - Recipients start in order, at most two seconds' worth (2 × TPS) at a time per request.
  - **Deadline.** PHP sends `deadline_ms` with every submit; after it the bridge starts no new message
    and answers the rest as `not_sent`, so PHP gets the reply — with the id of every message that went
    out — before its own HTTP timeout. A timeout would leave sent messages recorded as failed and uncharged.
  - **`not_sent`.** Each result says whether the SMSC never received the message: no bound session,
    rate or window wait ran out, deadline, sending switched off, throttled 4 times, or a negative
    `submit_sm_resp`. A `submit_sm` that got no answer, or a session that broke mid-write, is not
    `not_sent`: the SMSC may have taken it.
- **Delivery receipts.**
  - The bridge parses the `receipted_message_id` / `message_state` TLVs, or else the text receipt.
  - The `stat` becomes a delivery state with the gateway's **نگاشت وضعیت رسید تحویل** (see below).
  - Receipts for later parts are mapped back to the first part's id.
  - Each receipt is stored in `ellsms_smpp_events` **before** `deliver_sm_resp`. If the database is
    down the bridge answers `ESME_RSYSERR` and the SMSC redelivers.
- **Received messages (MO).**
  - Decoded by data coding (GSM / UCS-2 / Latin-1 / binary as hex).
  - Concatenated parts (8- or 16-bit UDH, or SAR) are reassembled. After 3 minutes whatever arrived is stored.
- **API.**
  - Internal only, on the Docker network, not published; every call except `/health` needs the token.
  - Endpoints: `/v1/submit`, `/v1/status`, `/v1/reload`, `/v1/gateways/{id}/reconnect`, `/v1/gateways/{id}/test`
    (test bind plus diagnosis; answers `diagnosis`, `tcp`, `enquire_link_probe`, `tls_handshake`, `alt_bind_tx`).

## PHP side (app/Sms/Smpp.php)

- `gateway_compile()` → `smpp_gateway_compile()` builds the compiled gateway for protocol `smpp`.
  The cache, versioning, routes, number pinning and operator restrictions are unchanged.
- `gateway_send()` → `smpp_gateway_send()` calls the bridge and returns exactly the shape the HTTP
  path returns. Bulk batching, wallet settlement, retries, provider health and per-recipient content
  therefore work the same way. Pricing is the gateway price matrix's, as for any gateway, and a message
  is charged only once the SMSC accepted it.
- **Time budget.** One send may spend at most the bulk lease minus 30 s in the bridge
  (`WORKER_JOB_LEASE_SECONDS`, default 300 → 270 s), so a long send never outlives its rows' lease (which
  would fail them as `[SEND_UNCERTAIN]` while they were going out). Each request's timeout is three
  times what its messages need at the gateway's TPS (recipients × parts ÷ TPS) plus 30 s, at least 60 s,
  never beyond what is left of the budget (`smpp_submit_timeout_ms()`); the bridge's deadline is that
  timeout minus one `submit_timeout_ms` and 20 s (`smpp_submit_deadline_ms()`).
- **What happens to a recipient that did not go out** (the bulk worker's at-most-once rule,
  `docs/job-queue-architecture.md`):

  | Situation | Row |
  |---|---|
  | bridge unreachable (connection refused), bridge answers 503 (gateway not running yet) | back to **pending**, retried with backoff (`not_sent`) |
  | bridge result `not_sent` with class `unavailable` (no session, throttled, deadline, send budget used up) | back to **pending**, even when other recipients of the same request went out (`not_sent_destinations`) |
  | SMSC refused it (`command_status`) | **failed** with `[0x…]`, never retried |
  | HTTP timeout to the bridge, `submit_sm` without answer, session broken mid-write | **failed** with its code and the `bulk_send_failed` alert — the SMSC may have it, so it is never resent automatically |

  Each failed recipient's error code is the SMSC's `command_status` with its Persian meaning
  (`0x0000000A فرستنده نامعتبر`, `SMPP_COMMAND_STATUS_LABELS`), and its error text says what to do
  (e.g. for `0x0A`: check «TON/NPI فرستنده», the line's format and that the line is on the account).
  The session table's last error and the test-bind message carry the same meanings. Otherwise the code is `smpp_<class>`,
  `smpp_bridge_unreachable`, `smpp_gateway_not_running`, `smpp_bridge_timeout`, `smpp_bridge_http_<n>`,
  `smpp_budget` or `smpp_no_result`.
- The **status-worker** runs `smpp_events_process_pass()`:
  - **Receipts** are matched to `ellsms_bulk_items` / `ellsms_message_attempts` by the gateway and the
    provider id, as-is or converted between hex and decimal according to `dlr_id_format`. They are
    applied with `gateway_status_record()`, which uses the same monotonic rules and delivery webhooks
    as polled statuses.
  - A receipt that arrives before its send was recorded is retried for about an hour.
  - **MOs** go into `ellsms_inbound_messages`, where the inbox, auto-reply and the customer database
    connector (#45) read them. A leading `98` is removed from the destination when the shorter number is a line.
- The active health checker probes the SMSC host and port over TCP, as it does for HTTP endpoints.

## Delivery receipts → delivery states (نگاشت وضعیت رسید تحویل)

The SMPP settings card has a JSON field: the receipt's `stat` is the key, an ELLSMS delivery state the
value (`docs/delivery-states.md`). It shows the effective mapping; an empty field, or the default saved
unchanged, keeps the gateway on the default (`status_mapping_json` NULL, column added by
`db/migrations/2026_11_20_smpp_status_mapping.sql`). Only the entries that differ are stored.

| `stat` | Default state | Billing |
|---|---|---|
| `DELIVRD` | `delivered` | charged |
| `UNDELIV` | `undelivered` («تحویل داده‌نشده») | final, **charged, not refunded** — the operator bills it |
| `EXPIRED` | `undelivered` | final, **charged, not refunded** |
| `REJECTD` | `rejected` | final, refunded in full |
| `DELETED` | `failed` | final, refunded in full |
| `ACCEPTD` | `accepted` | not final |
| `ENROUTE` | `sent` | not final |
| `UNKNOWN` | `unknown` | not final |

`DELIVERED`, `UNDELIVERABLE`, `REJECTED` and `ACCEPTED` are read as the short forms. A `stat` that is
neither in the gateway's mapping nor in this table stays `unknown` (not final, charged) and is logged
as `gateway.smpp.unmapped_stat` (metric `gateway_status_unmapped_token{connector=smpp}`); add it to the
mapping. Before `2026_11_20_smpp_status_mapping.sql`, `UNDELIV` was `failed` and `EXPIRED` `expired`,
which since `2026_11_19_undelivered_state.sql` are refunded in full. Rows already final keep their state.

Example — refund `UNDELIV` but not `EXPIRED`:

```json
{"UNDELIV": "failed"}
```

The raw `stat` is kept in `provider_status` and shown to admins with its meaning
(«کد سرویس‌دهنده», e.g. `UNDELIV — نرسیده به گوشی`, `SMPP_DLR_STAT_LABELS`).

## Monitoring (panel)

- **Per session:** state, connected since, accepted / failed submits, throttled, receipts, MOs, in-flight, reconnects, last error.
- **Last 24 h:** receipts (delivered / failed / unmatched), MOs, events waiting to be processed.
- **Warnings** when the bridge is unreachable, when it is not running the gateway, or when the password cannot be decrypted.
- **Buttons:** test bind, reconnect all sessions.

## Development / tests

- `SMPP_SIMULATOR_PORT=2775` starts a fake SMSC inside the bridge:
  - It accepts any bind and acknowledges submits.
  - One second later it sends a receipt: `DELIVRD`, or `UNDELIV` for numbers ending in `0000`,
    `EXPIRED` for `9999`, `REJECTD` for `8888`.
  - A number ending in `7777` is refused at submit with `ESME_RINVDSTADR` (`0x0B`).
  - The text `THROTTLE` is refused once with `ESME_RTHROTTLED`.
  - `POST /v1/simulator/mo {from,to,text}` injects a received message.
  - Point a gateway at `smpp-bridge:2775`. **Never enable this in production.**
- `cd smpp-bridge && SMPP_TEST_DB_HOST=127.0.0.1 SMPP_TEST_DB_USER=… SMPP_TEST_DB_PASS=… mvn test`:
  codec, vault (against a PHP-encrypted vector), receipt parsing, rate limiter, and end to end against
  the simulator and a real MySQL.
- `tests/Integration/SmppGatewayBridgeTest.php`: PHP → bridge → simulator → receipts / MO → PHP.
  Needs a running bridge (`ELLSMS_TEST_SMPP_BRIDGE_URL`).
- `tests/Integration/SmppBulkDeliveryTest.php`: a bulk job through the bridge — priced by the gateway's
  price cell, charged only for accepted messages, receipts applied with the default and a custom
  mapping, only refunded states refunded, the SMSC's refusal code on the row, and rows that never
  reached the SMSC (gateway not running, bridge down) back to pending and sent once it answers.
  Same environment as above.
- `tests/Unit/SmppGatewayTest.php`: receipt mapping and its validation, id variants, settings
  validation, timeout/deadline budget, error codes, the test-bind advice text.
- `smpp-bridge` `BindTesterTest` (no database): refused port, silent port, session limit, an SMSC
  without transceiver support, the simulator's good and wrong password.

Running the bridge for these tests without Docker: `cd smpp-bridge && mvn -DskipTests package`, then
`BACKEND_DB_HOST=127.0.0.1 BACKEND_DB_NAME=ellsms_test BACKEND_DB_USER=… BACKEND_DB_PASS=…
SMS_GATEWAY_MASTER_KEY=… SMPP_BRIDGE_TOKEN=… SMPP_SIMULATOR_PORT=2775 java -jar target/smpp-bridge.jar`.
