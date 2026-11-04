# Telegram user-account service

Customers connect **their own Telegram accounts** to the panel, then use them to:

- **check numbers**: find out which numbers in a list have Telegram (`/telegram/check`),
- **send messages**: OTP codes, notifications or (with a warning) promotions, as text and/or one photo or file (`/telegram/send`),
- **follow delivery**: every message goes queued → sent → **read**, or failed with a reason. Reports can be downloaded as CSV, and the same states are exposed through the public API and webhooks.

Platform admins switch the service on, set prices and limits, and choose which customers may use it (`/admin/telegram`); customers connect accounts on `/telegram/accounts`.

## Architecture

```
 panel (PHP, app/TelegramUser.php)              tg-worker (Python + Telethon, tg-worker/)
 ─────────────────────────────────              ──────────────────────────────────────────
 who may use it, limits, prices                 every call to Telegram:
 login request rows ───────────────────────────▶  send code, sign in, 2FA, log out
 campaigns + messages (worst case reserved) ───▶  resolve number, send, flood handling
 lookup jobs + items (worst case reserved) ────▶  contact import (temporary), results
 settle pass in `worker` (cron/worker.php):  ◀───  status sent / read / failed / found …
   charge successful units, release the rest,     read receipts (live + sweep every 3 min)
   finish jobs, emit webhooks                     per-account daily counters, heartbeat
                     └──────── shared MySQL: ellsms_tg_* tables ────────┘
```

- Schema: `db/migrations/2026_11_01_telegram_user_service.sql`.
- Money uses the same pattern as Bale. When a campaign or lookup is created, `price × units` is reserved in the wallet (`telegram_campaign` / `telegram_lookup` reservations). `tg_settle_pass()` commits the price of each unit that succeeded: a message that was sent or read, or a number that was checked (found or not found). When the job finishes or is cancelled, the rest is released. Failed, cancelled or interrupted units cost nothing. Platform admins pay nothing.
- One API call (`POST /api/v1/telegram/messages`) creates a campaign with one recipient, so the API and the panel share one code path.

## Security

- **Login secrets.** The 2FA password travels from the panel to the worker encrypted with AES-256-GCM. The Telethon session is stored the same way in `ellsms_tg_accounts.session_enc` and only the worker can read it. Both sides use the same format, `v1:` + base64(nonce ‖ ciphertext ‖ tag).
- **The key** is `TELEGRAM_SESSION_KEY` (64 hex chars). If it is unset, a key is derived from `SMS_GATEWAY_MASTER_KEY`. Changing the key makes every stored session unreadable, so every account must log in again.
- **Cleanup.** The login code, the 2FA password and the temporary login session are wiped as soon as a login finishes, fails or expires (10 minutes).
- **Disconnecting.** «قطع اتصال» (customer or admin) makes the worker call `auth.logOut`, which ends the session on Telegram's side too, then wipes the stored session. A customer can also end it from Telegram itself (Settings › Devices, where it shows as *ELLSMS Panel*). The worker then notices the dead session and marks the account disconnected.
- **Contacts.** The worker never reads chats. A number it has to import as a contact (to resolve or check it) is removed again unless it was already one of the owner's contacts.
- **Media.** Attachments are stored in `storage/telegram-media/u<user>/…`, which is git-ignored. The worker mounts that folder read-only and refuses any path outside it.

## Anti-ban behaviour

Messages from a personal account to strangers are what Telegram treats as spam. The service reduces the risk, but it cannot remove it:

| Guard | Default | Where |
|---|---|---|
| Messages per account per day | 50 | admin, per customer |
| Number checks per account per day | 100 | admin, per customer |
| Gap between two messages (+ up to 30% jitter) | 15 s | admin, per customer |
| Connected accounts per customer | 1 | admin, per customer |
| `PEER_FLOOD` | account paused 24 h | worker |
| `FLOOD_WAIT_x` | account paused x s | worker |
| Contact import refused (`retry_contacts`) | account paused 6 h | worker |
| «Promotional» kind, or more than N recipients (default 30) | explicit «may get blocked» acknowledgement | panel + API (`acknowledge_block_risk`) |

How each kind of message is handled:

- **OTP messages jump the queue** and wait only 3 s after the previous send.
- **When the account is limited**, an OTP fails at once with `account_limited`, so the caller can fall back to SMS. Any other message goes back to the queue for another account, or for later.
- **An OTP nobody could send within 5 minutes** fails with `no_account`.
- **A message stuck in `sending` for 10 minutes** (the worker died mid-call) fails with `interrupted`. It is never retried, because a retry could deliver it twice.

Read receipts are tracked for 48 hours after sending (`TG_READ_WINDOW_HOURS`).

"Not found" in a number check means the number either has no Telegram, or its owner hid it (*Privacy › Phone number › Who can find me*). The pages say so.

## Setup

1. Create a Telegram application at <https://my.telegram.org> → *API development tools*. Put the values in `.env`:
   ```
   TELEGRAM_API_ID=123456
   TELEGRAM_API_HASH=0123456789abcdef0123456789abcdef
   # optional
   TELEGRAM_SESSION_KEY=<openssl rand -hex 32>
   TELEGRAM_PROXY=socks5://user:pass@host:1080
   ```
2. Deploy. `./deploy.sh` applies the migration, builds `tg-worker` and starts it. Run only one instance.
3. Open **مدیریت › سرویس تلگرام** (`/admin/telegram`). The status card should show the worker online and both keys set. Then switch the service on (it is **off** by default — until it is on, every page and the API answer «سرویس تلگرام خاموش است»), check the prices (defaults: text 10, media 20, check 10 rial) and the default limits.
4. Under «فعال‌سازی برای کاربر», enable the service for each customer, optionally with their own limits and prices.
5. The customer connects an account under **تلگرام › اکانت‌های تلگرام** (phone → login code in their Telegram app → 2FA password if they have one).

## Troubleshooting

| What you see | Cause and fix |
|---|---|
| «سرویس تلگرام خاموش است…» on the customer pages, or when connecting an account | The global switch is off. Tick «سرویس فعال باشد» under «تنظیمات عمومی» on `/admin/telegram` and save. The status card shows «سوییچ سرویس». |
| «جدول‌های سرویس تلگرام هنوز ساخته نشده‌اند» | Migration `db/migrations/2026_11_01_telegram_user_service.sql` is not applied: `make db-migrations-apply` (or redeploy). |
| «سرویس ارسال با تلگرام برای حساب شما فعال نیست» | The switch is on but this customer is not enabled: add them under «فعال‌سازی برای کاربر». |
| Status card: worker «خاموش» | The `tg-worker` container is not running: `docker compose up -d tg-worker`, then `docker compose logs tg-worker`. |
| Status card: API «تنظیم نشده» | `TELEGRAM_API_ID` / `TELEGRAM_API_HASH` missing from `.env`. Add them, then run `docker compose up -d tg-worker`. |
| A login stays on «در حال درخواست کد…» | The worker is offline, has no API credentials, or cannot reach Telegram (set `TELEGRAM_PROXY`). |

## Public API (`telegram:send` / `telegram:read` scopes)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/telegram/accounts` | The caller's accounts, today's usage, and prices |
| POST | `/api/v1/telegram/messages` | `{"to": "0912…", "text": "…", "kind": "otp"\|"notify"\|"promo", "account_id"?: n, "client_message_id"?: "…", "acknowledge_block_risk"?: true}` → **202** with the message, which starts as `queued` |
| GET | `/api/v1/telegram/messages/{id}` | `status`: queued, sending, sent, read, failed, cancelled; `error_code` when failed |
| POST | `/api/v1/telegram/lookups` | `{"phones": ["0912…", …]}` (≤ 5000) → 202 `{id, max_cost}` |
| GET | `/api/v1/telegram/lookups/{id}` | progress + `results[]` with `has_telegram` true / false / null (not checked yet) |

Webhook events (subscribe on `/webhooks.php`), each emitted once per message:

- `telegram.message.sent`
- `telegram.message.read`
- `telegram.message.failed`

All three carry the same payload as `GET /messages/{id}`, plus `kind` and `source`.

Error codes: `not_on_telegram`, `privacy_restricted`, `blocked`, `deactivated`, `account_limited`, `flood_wait`, `no_account`, `media_missing`, `interrupted`, `error`.

**Suggested OTP flow.** Call `POST /messages` with `kind=otp`. Wait for `telegram.message.sent`, or poll for up to ~30 s. On `failed` (any `error_code`), or with no `sent` in time, send the code by SMS instead.

## Tests

- `tests/Integration/TelegramUserServiceTest.php` (PHPUnit integration suite) covers the panel side: access, reservation and charging, acknowledgement, stuck/OTP rules, cancel, lookups, login limits, password sealing, and webhooks.
- `tg-worker/tests/test_worker.py` (`cd tg-worker && python -m unittest discover -s tests`) covers the worker side: crypto compatibility with PHP, error classification, and against a real database with a fake Telegram client: sending, the daily cap, OTP priority, flood handling, read sweep, and lookups with cache. Set `TG_TEST_DB_HOST` (and `_NAME/_USER/_PASS`) to the integration-test database.

## AI secretary on Telegram

When a customer switches on the «منشی هوشمند» of one of its Telegram accounts
([docs/ai-secretary.md](ai-secretary.md) §Telegram), the `tg-worker` keeps that account connected,
even with nothing to send, and stores every private text message from a person in
`ellsms_tg_inbound`. It re-checks whether the assistant is on every 20 seconds. The answers come back
as ordinary one-recipient campaigns of that account. Their messages carry the sender's
`tg_peer_id` / `tg_access_hash`, and the worker sends to that peer directly, without a phone lookup.
