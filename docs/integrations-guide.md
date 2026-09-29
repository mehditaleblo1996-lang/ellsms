# Integrations: WooCommerce plugin and SDKs

The step-by-step guide for customers is written in Persian. It lives in
[`integrations/GUIDE.fa.md`](../integrations/GUIDE.fa.md). The panel renders that same file on
`/integrations`. The same file is also bundled into the WooCommerce zip, so there is only one copy to
keep up to date.

## What is where

| Path | What |
|---|---|
| `integrations/woocommerce/ellsms-woocommerce/` | WordPress / WooCommerce plugin source |
| `sdk/php/`, `sdk/js/`, `sdk/python/` | SDKs (each has a README) |
| `app/Integrations.php` | Builds the zip packages from these folders. The WooCommerce zip gets the PHP SDK copied into `lib/ellsms-php`. Also renders the guide's Markdown. |
| `public/integrations.php` | Download page, shown to admins and to users who can see API keys |

## How the WooCommerce plugin sends

| Step | Behaviour |
|---|---|
| Trigger | `woocommerce_order_status_changed` |
| Queue | An Action Scheduler job goes into the `ellsms` group. Sending happens inline if Action Scheduler is missing. |
| Send | `POST /api/v1/messages` |
| Stale status | Before sending, the job checks the order still has that status. If not, it sends nothing. |
| Once per order | Each status is sent at most once per order. Order meta `_ellsms_wc_sent` records what was sent. |

### Idempotency

Every send carries this ID:

```
client_message_id = wc-{site}-{order}-{status}[-r{attempt}]
```

The attempt number decides what a retry does:

- **Same attempt number:** after an outcome that is unknown (a network error or a 5xx), the retry reuses the ID. The server then replays the first answer, so a message that did go out is never sent twice.
- **Next attempt number:** after a definite refusal (for example 402, no credit), the attempt number increases, stored in `_ellsms_wc_attempts`. The server keeps a refusal for 24 hours, so reusing the old ID would only replay it; the new ID lets a retry actually send.

### Resend and failures

- **Manual resend:** available from the order-actions box on the order page.
- **Temporary failures:** a 429, 5xx or network error is rethrown, so the Action Scheduler job shows as failed.

## Tests

- `tests/Integration/SdkConformanceTest.php` runs one scenario for all three SDKs against the real API.
- Retry rules:
  - `sdk/js/test/retry.test.js`: run it with `npm test` in `sdk/js`.
  - `sdk/python/tests/test_retry.py`
- `tests/Integration/WooCommercePluginTest.php` covers the plugin logic. It uses a WordPress/WooCommerce stand-in, but real HTTP calls to the real API.
- `tests/Integration/IntegrationsPageHttpTest.php` covers the download page and the zip contents.

Real WordPress could not be installed in the build environment, because its download hosts are blocked. Try the plugin on a staging store before production.
