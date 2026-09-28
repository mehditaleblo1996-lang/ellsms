# Regional bulk through Vesal (#43)

Send one message to every subscriber in a **province**, a **city**, a **postal-code area** or a
**number prefix**, optionally filtered by SIM type (prepaid / postpaid) and operator (MCI / Irancell).

The subscriber bank belongs to the operators and only Vesal's platform can target it. ELLSMS is purely a
client of Vesal's `/backend/bulk/*` API and **never sees, receives or stores a subscriber number**.

Code: `app/RegionalBulk.php`. Page: `/messages/regional` (`public/regional-bulk.php`). Table:
`ellsms_regional_bulk_requests`.

## Setup (platform admin)

1. Put the password of the Vesal bulk account in the environment, as `VESAL_BULK_PASSWORD` in `.env`.
   It is never stored in the database, and never logged: the request body carries it, so failures log
   only the endpoint and HTTP status.
2. On `/messages/regional`, fill in these settings:
   - **Enabled**
   - **Service address**, for example `https://vesal.example/api`. Calls go to `{address}/backend/bulk/<endpoint>`.
     It must be `https://` in production and it goes through the same SSRF check as gateway connectors.
   - **Username**
   - **Credits per price unit** and **margin %**
3. KYC gate `regional_bulk` is **required by default**, since regional bulk is promotional mass
   messaging. Change it on the KYC gates page like any other gate.

The menu entry is shown to customers only when everything is configured.

## Flow

| Step | ELLSMS | Vesal endpoint |
|---|---|---|
| Pick an area | Province list, and cities of a province | `provinces`, `citiesOfProvince` |
| "Count subscribers" | Shows the number | `countByProvince` / `countByCity` / `countByPostalCode` / `countByPrefix` |
| "Create and price" | Local checks, then request, then price. Status becomes **priced**. **Nothing is reserved yet.** | `requestBulkBy*`, `requestPrice` |
| "Confirm and send" | Reserves the price in the wallet, then confirms. Status becomes **confirmed**. | `confirmBulkRequest` |
| Worker, every minute | Polls progress. Status becomes **sending**, then **done** or **cancelled**. | `bulkStatus` |

- **Local checks before anything reaches Vesal:**
  - The feature is enabled.
  - The KYC gate passes.
  - Impersonation rules allow it.
  - The customer may use the originator line.
  - The content passes the prohibited-words policy (#40).
- **Cancelling:** a request can be cancelled only while it is still *priced*. Vesal has no call for
  cancelling a confirmed request.
- **Irancell:** Irancell (MTN) targeting uses the same endpoints with `mobileOperator = MTN`.
- **Not offered:** Vesal's "extended" and Irancell-only endpoints are empty stubs in its current code,
  so ELLSMS does not offer them.

## Money

- **Price:** Vesal's price × `regional_bulk_credits_per_price_unit` × (1 + `regional_bulk_margin_percent` / 100),
  rounded up. Platform admins are not charged.
- **At confirmation:** the full price is reserved. If Vesal refuses the confirmation, the reservation is
  released and the request is marked *failed*.
- **At settlement:** once Vesal reports a final state (5 TOTAL_SENT_READY, 6 CANCELED or 7 DELIVERED),
  or 7 days after confirmation:
  - The customer pays `ceil(price × totalSent / totalRequest)`.
  - The rest of the reservation is released.
  - The settlement is claimed by a conditional UPDATE, so it happens exactly once.

## Idempotency

- Every request carries `userSuppliedId = 880000000000 + row id`.
- If the answer to `requestBulkBy*` is lost (connection error or non-2xx), ELLSMS asks
  `checkDuplicateRequest` for that id. If Vesal did create the request, its reference is adopted, so no
  second request is ever made for the same row.
- A confirm whose answer is lost is left *confirmed*. Status polling then shows whether Vesal is sending.
- `-114 PRE_CONFIRMED` counts as success.

## Vesal error codes

Every `BulkErrorCode` is shown to the customer as a Persian message: -101 … -114 and -137. The full
table is `REGIONAL_BULK_ERRORS` in `app/RegionalBulk.php`.

## Tests

`tests/Integration/RegionalBulkTest.php` runs against a Vesal-shaped fixture
(`tests/fixtures/recording_gateway_server.php`, `/vesal/backend/bulk/*`). It covers:

- Credentials in the body.
- Field mapping.
- No reservation before confirmation.
- Double-confirm refusal.
- Sent-share settlement.
- Lost-answer recovery.
- Vesal refusals.
- Local checks.
- Insufficient credit.
- Isolation between customers.
