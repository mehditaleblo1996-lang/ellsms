# ellsms — Python client for the ELLSMS public API

Needs Python 3.8+. Uses only the standard library.

A step-by-step guide in Persian is in `integrations/GUIDE.fa.md`. It is also on the panel's
**افزونه ووکامرس و SDK** page.

```python
from ellsms import Client, EllsmsError, verify_webhook

sms = Client("ellsms_live_…", "https://panel.example.com")
sms.send_message(["09121234567"], "Hello", client_message_id="order-1001-shipped")
```

## Methods

| Area | Methods |
|---|---|
| Account | `me()`, `organization()`, `balance()` |
| Messages | `send_message()`, `get_message()`, `preview_message()` |
| Bulk jobs | `create_bulk_job(job, idempotency_key=None)`, `get_bulk_job()`, `preview_bulk_job()` |
| Contacts | `list_contacts()`, `each_contact()`, `create_contact()`, `get_contact()`, `update_contact()`, `delete_contact()` |
| Webhooks | `list_webhooks()`, `create_webhook()`, `get_webhook()`, `update_webhook()`, `delete_webhook()`, `rotate_webhook_secret()`, `test_webhook()` |
| Signatures | `verify_webhook(secret, timestamp, raw_body, signature)` |

## Errors

Every error is raised as `EllsmsError`. It has these attributes:

- `status`
- `code`
- `fields`
- `request_id`
- `retry_after`

## Retries

Retries work the same way as in the PHP SDK:

- An answer with status 429 is always retried.
- A network failure or a 5xx answer is retried only when repeating the request cannot send twice:
  - GET requests
  - previews
  - requests that carry an idempotency key or a `client_message_id`
