# Saman Electronic Payment (پرداخت الکترونیک سامان)

Online card payments through SEP's token API (technical guide 3.6, Dey 1404), beside the existing
ZarinPal adapter. Amounts are Rial integers, the same unit as `ellsms_payments.amount_rial`.
SEP has no public sandbox.

Migration: `db/migrations/2026_11_25_sep_payment_gateway.sql` (`gateway_resnum`, `gateway_ref`).

## What the buyer does

`/buy-credit.php`, paying an invoice, buying a subscription, and a reseller package all call
`payment_gateway_name()`. When that returns `sep`:

1. `sep_request_token()` POSTs `action=token` to `https://sep.shaparak.ir/OnlinePG/OnlinePG`
   with the terminal, the invoice total, a new `ResNum` (`{paymentId}-{8 hex}`), and the callback URL.
   The token is stored on `ellsms_payments.authority`. A retry of the same row gets a new ResNum and
   clears any previous `gateway_ref`.
2. `public/sep-redirect.php` auto-submits a form to that same URL with `Token` and `GetMethod=true`.
   SEP rejects a plain `Location` redirect: the browser must send this site as `Referer`.
   `GetMethod=true` makes SEP return with GET, so the `SameSite=Lax` session cookie still arrives.
   The CSP's `form-action` must list `https://sep.shaparak.ir` (`send_security_headers()` in
   `app/bootstrap.php` and the override in `app/Support/AuditPrepend.php`); otherwise the browser
   blocks the auto-submit and the page stays on «در حال انتقال به پرداخت الکترونیک سامان».
3. `public/sep-callback.php` (also `/payments/sep/callback`) reads `State`, `Status`, `ResNum`,
   `Token`, `RefNum`. POST is accepted too. The callback does not require a login — SEP says the
   session may already have expired — and it is exempt from maintenance mode. The payment is found
   by `ResNum`. When SEP also sends `Token` it must match the stored token; the guide's main field
   list sometimes omits `Token`, so a callback without it is still accepted.
4. Only `State=OK` (and status empty or `2`) is verified. A state from SEP's failure table
   (`CanceledByUser`, `Failed`, `SessionIsNull`, and the token/terminal errors) marks the payment
   `failed`. An empty or unknown state does not. A later `OK` callback can still verify a payment
   that was marked failed, because a cancel request can arrive before the success redirect.
5. `VerifyTransaction` (`https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction`)
   must return `ResultCode` 0, or 2 when the same receipt was already verified. `OrginalAmount`
   must equal the invoice total. The terminal in the answer must be ours.
6. The same claim as ZarinPal (`payment_claim_and_credit()` or
   `payment_claim_and_activate_subscription()`) credits the wallet or activates the subscription
   exactly once.

SEP will happily verify one `RefNum` again. `ellsms_payments.gateway_ref` is UNIQUE so that receipt
cannot credit a second payment. A verified amount that does not match the invoice is reversed with
`ReverseTransaction` (allowed for about 50 minutes) and is not credited. If the reverse call itself
fails, the row stays `verification_failed` and `payments-reconcile` tries the reverse again.

## If the buyer never comes back

SEP will not look a payment up by `ResNum`. Until the callback has stored `RefNum` on `gateway_ref`,
`cron/payments-reconcile.php` leaves the row alone. A receipt is stored only after the bank
recognises it (`ResultCode` 0, 2, `-6`, or `5`). A not-found or a dropped connection does not
occupy `gateway_ref`, so a later callback with the real receipt can still complete. If this payment
already holds a different receipt, a newly verified one replaces it only when the stored receipt
does not itself verify for the invoice total; when both verify, the new one is reversed and the
stored one is what gets credited. SEP reverses an unverified transaction after 30 minutes. A
verify that says the stored transaction was not found (`-2`) becomes final after 40 minutes.

## Admin configuration

**Settings → پرداخت آنلاین** («Settings»):

| Field | Setting key | Env fallback |
|---|---|---|
| درگاه فعال | `payment_default_gateway` (`zarinpal` or `sep`) | `PAYMENT_DEFAULT_GATEWAY` |
| شماره ترمینال | `sep_terminal_id` | `SEP_TERMINAL_ID` |
| آدرس بازگشت | `sep_callback_url` | `SEP_CALLBACK_URL`, then `APP_URL/sep-callback.php` |
| مدت اعتبار توکن | `sep_token_expiry_min` (20–3600, empty = SEP's 20) | `SEP_TOKEN_EXPIRY_MIN` |

A saved setting wins over `.env`, except in the test suite, which reads the env only.
`payment_gateway_supports_refund('sep')` is false: reverse is only the amount-mismatch path above,
not the invoice refund screen.

The same page prints the three values SEP's merchant form asks for:

- **آدرس سایت** — `https://` plus the host, no path. `https://datisrayan.com` is valid;
  `datisrayan.com` is rejected by SEP.
- **آدرس کال‌بک** — the effective callback, normally `https://<host>/sep-callback.php`. Port **443**.
- **آی‌پی سرور** — the public IP of the server that calls SEP. Without it, token requests fail
  with error 8 and verify fails with `-106`.

On a reseller host, `RESELLER_HOST_PAYMENT_CALLBACK=1` builds the callback from that host, the same
way ZarinPal does. That host and its server IP must be registered on the terminal.

Staging deletes `sep_terminal_id`, `sep_callback_url`, `sep_token_expiry_min` and
`payment_default_gateway` on every database restore (`ops/staging/sanitize.sql`), so a copy of
production cannot charge cards on production's terminal. Enter the staging terminal again afterwards.
The callback to register there is `https://<staging-host>/sep-callback.php`.

## Result codes that matter

Callback `State`: `CanceledByUser` (1), `OK` (2), `Failed` (3), `SessionIsNull` (4), plus token
errors 5, 8, 10, 11, 12. Verify/reverse: `0` success, `2` duplicate (treated as success when the
amount is present), `-2` not found, `-6` older than 30 minutes, `5` already reversed, `-104`
terminal disabled, `-105` terminal unknown, `-106` IP not allowed.
