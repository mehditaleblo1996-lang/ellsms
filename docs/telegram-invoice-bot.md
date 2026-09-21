# Telegram invoice/price-quote bot ("/invoice")

An admin (or anyone in a Telegram group the bot has been added to) can send `/invoice` to get
the ELLSMS price-quote document — the same one edited at **مدیریت → فاکتور / لیست قیمت**
(`public/price-quote.php`) — back as a PDF, without opening the admin panel.

Code: `app/PriceQuote.php` (document model, HTML render, wkhtmltopdf shell-out, message parser) ·
`app/telegram.php` (bot HTTP calls, allow-list) · Endpoint: `public/telegram-webhook.php` ·
Settings UI: **تنظیمات → ربات تلگرام فاکتور / لیست قیمت**.

## 1. One-time setup

1. Create a bot with [@BotFather](https://t.me/BotFather) if one doesn't already exist, and get
   its token. Enter that token (and the admin's own chat ID, used for the separate contact-form
   relay) under **تنظیمات → تماس با ما**.
2. Run `/setprivacy` in BotFather and choose **Disable** for the bot — otherwise Telegram won't
   deliver ordinary group messages to it. This step is actually optional here: `/invoice` is a
   slash command, and Telegram always delivers slash commands to bots in a group regardless of
   privacy mode. Disable it anyway if the bot will ever need to read plain messages for something
   else later.
3. On **تنظیمات → ربات تلگرام فاکتور / لیست قیمت**, click "ساخت رمز وب‌هوک" to generate
   `telegram_webhook_secret`, then list the chat IDs allowed to run `/invoice` in
   `telegram_bot_allowed_chats` (comma/newline separated — a group's chat ID is negative; forward
   a message from the group to a bot like @userinfobot to find it).
4. Run the `curl .../setWebhook` command shown on that same settings card once. It registers
   `https://<your-domain>/telegram-webhook.php` with Telegram, with the secret token attached so
   Telegram will echo it back on every update and the webhook can reject anything else.

Re-run step 4 any time the webhook secret is regenerated — the old secret stops being accepted the
moment a new one is saved, so an un-updated `setWebhook` starts getting silent 403s logged as
`telegram.webhook.bad_secret`.

## 2. Using it

Send `/invoice` alone to regenerate a PDF of exactly what's saved in the admin panel right now —
branding, contact footer, rows, notes, all of it. To customize a single message's rows or a few
fields without touching the saved template, add lines under the command:

```
/invoice
شماره: ELL-1405-002
تاریخ: 1405/07/05
ردیف: پیامک تبلیغاتی | ارسال عمومی | هر پیامک | 120
ردیف: پیامک بین‌المللی | ارسال برون‌مرزی | هر پیامک | 900
نکته: این قیمت‌ها شامل مالیات نمی‌شود.
```

Recognized labels (`app/PriceQuote.php`'s `price_quote_parse_telegram_message()`):
`شماره`/`شماره فاکتور`, `تاریخ`, `اعتبار`, `واحد`, `عنوان`, `زیرعنوان`, `پاورقی`, `تلفن`/`تلفن۱`,
`تلفن۲`, `وبسایت`/`سایت`, `ایمیل` each replace one field; `ردیف: عنوان | توضیح | واحد | قیمت`
(repeatable) replaces the whole row list; `نکته: متن` (repeatable) replaces the whole notes list.
Any line with none of these labels, or with no `:`, is ignored. **Nothing sent this way is saved**
— it only shapes that one generated PDF. To change the standing template, edit it in the admin
panel and click "ذخیره‌ی تغییرات" there instead.

## 3. Why a PDF, not the PNG the admin panel also offers

`public/price-quote.php`'s in-browser PNG export runs entirely client-side (canvas + SVG
`foreignObject`, see that file) because it has a real browser to draw with. The webhook runs
server-side with no browser: it shells out to **wkhtmltopdf**, a local binary installed in
`docker/Dockerfile`, the same "one pinned local binary, controlled argv, `proc_open` +
`bypass_shell`" pattern `app/TotpMfa.php` already uses for `qrencode`. This project ships zero
production Composer dependencies by design (see `composer.json` — PHPUnit is `require-dev` only,
never installed in the production image), so a PHP PDF/image library was deliberately not added
for this; converting a PDF page to a PNG server-side would need Imagick+Ghostscript on top of
that, which isn't installed either. If a PNG from the bot is ever needed, send `/invoice` and use
the admin panel's PNG button on the result, or ask for Imagick+Ghostscript to be added explicitly.

## 4. Security notes

- The webhook never trusts the network path alone: every request must carry
  `X-Telegram-Bot-Api-Secret-Token` matching `telegram_webhook_secret`, checked with
  `hash_equals()` before the request body is even parsed.
- Only chat IDs in `telegram_bot_allowed_chats()` (`app/telegram.php`) get a reply. A random user
  who finds the bot, or the bot being added to an unrelated group, produces no output — the
  webhook still returns HTTP 200 (Telegram would otherwise retry the same update) but does
  nothing.
- The webhook has no ELLSMS session and performs no `audit()` calls tied to an admin user;
  failures are logged via `Logger::warning('telegram.webhook.*', ...)` instead
  (`storage/logs/ellsms-YYYY-MM-DD.log`).
