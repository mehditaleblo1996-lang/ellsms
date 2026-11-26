# Characters per SMS part, per gateway («تعداد کاراکتر هر پارت»)

How many characters one SMS part holds can be set **per gateway**. A message is counted (parts,
price, wallet charge, the stored `parts` column) with the numbers of the gateway its **sender line**
goes out through; an SMPP gateway's bridge also **splits** the text with them.

## Default

| Text | One-part message up to | Each part of a longer message |
|---|---|---|
| Persian / Unicode (any character outside printable ASCII) | 70 characters | 67 characters |
| Latin / GSM-7 | 160 characters | 153 characters |

A gateway that sets nothing uses these. So does a send with no sender line, and every send while
`SMS_GATEWAY_TRANSPORT=0` (the legacy backend API is not a gateway). A newline counts as one
character (`sms_text_from_form()`, see `docs/message-parts.md`).

## Setting it — Platform Admin → «درگاه‌های پیامک» → (gateway) → «تعداد کاراکتر هر پارت»

Four fields on the gateway's settings tab (`public/sms-gateways.php`, `do=part_limits_save`):
«فارسی — پیام تک‌پارتی», «فارسی — هر پارت پیام چندپارتی», «انگلیسی — پیام تک‌پارتی», «انگلیسی — هر
پارت پیام چندپارتی». An empty field means the default. Rules (`sms_part_limits_validate()`):

- each value is 1–1000;
- a per-part size may not be larger than the one-part size of the same alphabet;
- on an **SMPP** gateway each value is at most 70 / 67 / 160 / 153, the most one SMS can carry —
  the bridge cannot put more in a part. Smaller values make the bridge split into smaller parts.

Saving bumps the gateway's `config_version` (audited as `gateway.part_limits` in «تاریخچه‌ی
تغییرات»), so every worker and the SMPP bridge pick the new numbers up within
`SMS_GATEWAY_VERSION_CHECK_SECONDS`.

On an **HTTP** gateway the provider splits the text itself; set the numbers to what the provider
charges by, so the panel charges the customer the same number of parts.

## Which gateway a send is counted with

`sms_gateway_id_for_sender()` (`app/SmsPartLimits.php`), the same order the send path uses
(`gateway_for_sender()`):

1. the gateway pinned to the sender number on the numbers page (`ellsms_numbers.gateway_id`);
2. else the gateway of the route the sender line resolves to (its own route assignment, else the
   default route), else the default gateway;
3. no sender line, gateway transport off, or nothing resolves → the default numbers.

The count follows the **sender line only**: the message type and destination-operator routes are not
consulted, so one text from one line is counted the same in the preview, the charge and the stored
`parts`. (A sender without its own route that is routed by destination operator to different gateways
is counted with its default route's gateway.)

Where the gateway is known exactly, its own numbers are used: the `parts` stored on
`ellsms_message_attempts` (the gateway that took the message), on `ellsms_inbound_messages` (the
gateway that received it), and the SMPP send's TPS budget.

## Where it applies

- **Panel send forms** — the live counter on «ارسال پیامک» (`send.php`, `new-send.php`) reads the
  sizes of the selected line from the form's `data-part-limits` (`sms_part_limits_for_senders()`,
  only lines that differ from the default are listed); the live cost (`/send-estimate.php`) and the
  confirmation preview count server-side (`cost_estimate_segments($content, $limits)`).
- **Pricing and charging** — `sms_pricing_price_single_content()`, `dispatch_message_raw()`, bulk jobs
  (`bulk_queue_job()`, file imports, «ارسال با کد پستی», the bulk worker's fallback count).
- **Stored part count** — `ellsms_bulk_items.parts`, `ellsms_message_attempts.parts`,
  `ellsms_inbound_messages.parts`, `ellsms_send_drops.parts`, remote-DB rows.
- **API** — `/api/v1` sends and previews price and count the same way (no route or field changes; the
  `segments` numbers simply follow the sender's gateway).
- **AI secretary** — the answer is cut to the assistant's part limit with its line's sizes
  (`ai_secretary_part_limits()`).
- **SMPP bridge** — `Codec.split(enc, singleChars, partChars)`: UCS-2 sizes ×2 bytes, GSM/Latin-1 ×1,
  capped to the protocol's 140/134 and 160/153 bytes (`smpp-bridge/`, `Store.loadGateways()` reads the
  columns; 0 = protocol size).

Not affected: LBA (its own gateways and prices, `app/Lba.php`), and rows written before a gateway's
numbers changed — their stored `parts` stay as charged. A row without a stored count (older than the
`parts` column) is counted from its text with the default numbers (`sms_parts_sql()`).

## Code

| | |
|---|---|
| `app/SmsPartLimits.php` | defaults, normalization, cached gateway map, sender → gateway → limits, validation, save |
| `app/bootstrap.php` | `sms_parts($content, ?array $limits = null)` |
| `public/sms-gateways.php` | the admin card |
| `public/assets/js/sms-composer.js` | live counter per selected line |
| `smpp-bridge/…/Codec.java`, `GatewayConfig.java`, `GatewayRuntime.java`, `Store.java` | splitting with the gateway's sizes |
| `tests/Unit/SmsPartLimitsTest.php`, `smpp-bridge/…/CodecAndVaultTest.java` | tests |

## Migration

`db/migrations/2026_11_29_gateway_part_limits.sql` — four nullable `SMALLINT UNSIGNED` columns on
`ellsms_sms_gateways` (`part_fa_single`, `part_fa_multi`, `part_en_single`, `part_en_multi`; NULL =
default). Additive and rerun-safe. Until it runs the admin card asks for it and everything counts with
the default; the bridge reads the columns only once they exist.

No new setting, periodic job or API route.
