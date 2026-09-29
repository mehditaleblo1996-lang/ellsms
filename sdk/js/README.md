# ellsms — Node.js client for the ELLSMS public API

Node.js 18+ with no dependencies, usable from CommonJS and ESM. TypeScript types are included.
A step-by-step guide in Persian is in `integrations/GUIDE.fa.md`, and on the panel's
**افزونه ووکامرس و SDK** page.

```js
const { EllsmsClient, EllsmsError, verifyWebhook } = require('ellsms');
const sms = new EllsmsClient({ apiKey: process.env.ELLSMS_API_KEY, baseUrl: 'https://panel.example.com' });
await sms.sendMessage(['09121234567'], 'Hello', { client_message_id: 'order-1001-shipped' });
```

## Methods

| Area | Methods |
|---|---|
| Account | `me()`, `organization()`, `balance()` |
| Messages | `sendMessage(to, text, options)`, `getMessage(id)`, `previewMessage(...)` |
| Bulk jobs | `createBulkJob(job, idempotencyKey?)`, `getBulkJob(id)`, `previewBulkJob(job)` |
| Contacts | `listContacts({limit, after})`, `eachContact()` (async iterator), `createContact`, `getContact`, `updateContact`, `deleteContact` |
| Webhooks | `listWebhooks`, `createWebhook`, `getWebhook`, `updateWebhook`, `deleteWebhook`, `rotateWebhookSecret`, `testWebhook` |

## Webhook signatures

`verifyWebhook(secret, timestamp, rawBody, signature)` checks that a webhook call came from ELLSMS. Pass it the raw request body, not the parsed JSON.

## Errors

Failed calls throw `EllsmsError`, which carries `status`, `code`, `fields`, `requestId` and `retryAfter`.

## Retries

Retries follow the same rules as the PHP SDK:
- A 429 answer is always retried.
- A network failure or 5xx answer is retried only when the request cannot send twice: GET requests, previews, and requests that carry an idempotency key or a `client_message_id`.

Use this client on the server only. Never put an API key in browser code.
