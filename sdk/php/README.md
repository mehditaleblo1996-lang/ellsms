# ellsms-php — PHP client for the ELLSMS public API

PHP 7.4+, only `ext-curl` and `ext-json`. Persian step-by-step guide: `integrations/GUIDE.fa.md`
(also on the panel's **افزونه ووکامرس و SDK** page).

```php
require 'src/EllsmsException.php';
require 'src/Webhook.php';
require 'src/Client.php';

$sms = new Ellsms\Client(getenv('ELLSMS_API_KEY'), 'https://panel.example.com');
$sms->sendMessage(['09121234567'], 'Hello', ['client_message_id' => 'order-1001-shipped']);
```

| Method | Endpoint |
|---|---|
| `me()`, `organization()`, `balance()` | `GET /me`, `/organization`, `/balance` |
| `sendMessage($to, $text, $options)`, `getMessage($id)`, `previewMessage(...)` | `/messages` |
| `createBulkJob($job, $idempotencyKey = null)`, `getBulkJob($id)`, `previewBulkJob($job)` | `/bulk-jobs` |
| `listContacts($limit, $after)`, `eachContact()`, `createContact`, `getContact`, `updateContact`, `deleteContact` | `/contacts` |
| `listWebhooks`, `createWebhook`, `getWebhook`, `updateWebhook`, `deleteWebhook`, `rotateWebhookSecret`, `testWebhook` | `/webhooks` |
| `Ellsms\Webhook::verify($secret, $timestamp, $rawBody, $signature)`, `Webhook::fromGlobals($secret)` | webhook signatures |

Errors: `Ellsms\EllsmsException` with `getStatus()`, `getErrorCode()`, `getFields()`, `getRequestId()`,
`getRetryAfter()`.

Retries (`$maxRetries`, default 2): answers with status 429 are retried. Network failures and 5xx
answers are retried only for requests that cannot send twice: GETs, previews, and requests that
carry an idempotency key or a `client_message_id`.
