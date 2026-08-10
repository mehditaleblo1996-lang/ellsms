# ELLSMS — Feature Inventory (pre-refactor audit)

Repo: 42 PHP files, ~6,026 lines total (excl. views/assets it's smaller still). No `vendor/`,
no framework — plain PHP 8.2 + PDO, Apache, Docker Compose. Shares its MySQL database with an
external "backend" SMS platform that ELLSMS does not own (`user_`, `outbound_message`,
`inbound_message`, `domain`, `customer`, `role`, `access`). ELLSMS owns everything prefixed
`ellsms_` (`db/ellsms_extra.sql`).

Core shared modules (read in full, grounding every feature below):
- `app/bootstrap.php` (520 lines) — env/config, PDO singleton, `ellsms_settings` cache, backend
  password hashing (SHA-256 placeholder), session bootstrap, `current_user()`/`require_login()`/
  `require_admin()`, CSRF, flash messages, MSISDN/originator normalization, SMS part counting,
  audit log, full Jalali calendar implementation, KYC upload validation/storage, blacklist filter.
- `app/backend.php` (627 lines) — backend REST API client (`backend_api_send`,
  `backend_create_account`), `dispatch_message()` (the one send path everything funnels through),
  `run_due_schedules()`, the منشی پیامک auto-reply engine (`run_autoreply_pass` +
  `autoreply_process_one/matches/render`), SMS 2FA (`send_2fa_code`/`verify_2fa_code`), and the
  bulk-send engine (`bulk_queue_job`, `bulk_send_one_item`, `run_bulk_send_pass`).
- `cron/worker.php` (35 lines) — infinite loop, 8s tick, calls the three worker passes above
  each in their own try/catch; also runnable once via `--once` for external cron.
- `db/ellsms_extra.sql` — all `ellsms_*` tables, additive migrations guarded by
  `information_schema` checks, seed settings.
- `docker-compose.yml` / `docker/Dockerfile` / `docker/entrypoint.sh` — two containers (`app`,
  `worker`) built from the same image, bind-mounting the full repo at runtime; Apache docroot is
  `/public`; `public/sms/` gets a `.html`→PHP handler override for the legacy URL API.

## Features

| # | Feature | Entry points | Core files |
|---|---|---|---|
| 1 | Bootstrap / config / DB connection | `app/bootstrap.php:19` (`env`), `:25` (`db`) | `app/bootstrap.php` |
| 2 | Auth, session, CSRF | `public/login.php`, `app/bootstrap.php:86` (`current_user`) | `app/bootstrap.php`, `public/login.php`, `public/logout.php` |
| 3 | SMS 2FA | `public/verify-2fa.php`, `app/backend.php:372` (`send_2fa_code`) | `public/login.php`, `public/verify-2fa.php`, `app/backend.php` |
| 4 | First-admin bootstrap | `public/bootstrap-admin.php` | same |
| 5 | Backend API integration / messaging core | `app/backend.php:34` (`backend_api_send`), `:100` (`dispatch_message`) | `app/backend.php` |
| 6 | Direct + legacy send | `public/send.php`, `public/sms/url_send.html` | both call `dispatch_message()` |
| 7 | پنل جدید ارسال (combined send: direct/recurring/gradual/campaigns/whitelist) | `public/new-send.php` | `app/backend.php`, `app/bootstrap.php` (blacklist), `ellsms_campaigns` |
| 8 | Bulk personalized sending (p2p + smart) | `public/p2p-send.php`, `public/smart-send.php` | `app/xlsx_reader.php`, `app/backend.php:474-627` |
| 9 | Scheduled & recurring messaging | `public/schedules.php`, `app/backend.php:156` (`run_due_schedules`) | worker |
| 10 | Auto-reply engine (منشی پیامک) | `public/autoreply.php`, `app/backend.php:224` (`run_autoreply_pass`) | worker |
| 11 | Inbound messages / inbox | `public/inbox.php` | reads `inbound_message` (backend-owned) |
| 12 | Contacts | `public/contacts.php` | `ellsms_contacts` |
| 13 | Blacklist (do-not-contact) | `public/blacklist.php` | `ellsms_blacklist` |
| 14 | Sender numbers + number categories | `public/numbers.php`, `public/number-categories.php` | `ellsms_numbers`, `ellsms_number_categories(_items)` |
| 15 | Payments (ZarinPal) | `public/buy-credit.php`, `public/zarinpal-callback.php` | `app/zarinpal.php`, `ellsms_payments` |
| 16 | Credit management | spread across `dispatch_message()`, `buy-credit.php`, `users.php` | `user_.currentcredit` (backend-owned column) |
| 17 | KYC | `public/profile.php`, `public/users.php`, `public/kyc-photo.php` | `ellsms_user_kyc`, `app/bootstrap.php` upload fn |
| 18 | Users / admin / access grant / account creation | `public/users.php` | `ellsms_meta`, `app/backend.php:428` (`backend_create_account`) |
| 19 | Settings (admin config surface) | `public/settings.php` | `ellsms_settings` |
| 20 | Reports & analytics | `public/reports.php`, `public/analytics.php` | reads `outbound_message`/`inbound_message` |
| 21 | Tickets (in-panel support) | `public/tickets.php` | `app/tickets.php`, `ellsms_tickets(_replies)` |
| 22 | Public marketing site | `public/index.php`, `landing.php`, `pricing.php`, `guide.php`, `guide-admin.php`, `slides.php` | `ellsms_slides`, `ellsms_pricing_packages`, `ellsms_guide_articles` |
| 23 | Contact form → Telegram relay | `public/contact.php` | `app/telegram.php` (stateless, no DB) |
| 24 | Worker process / job runner | `cron/worker.php` | ties together #9, #10, #8 |
| 25 | Docker / env / infra config | `docker-compose.yml`, `docker/*`, `.env.example` | cross-cutting |

Features 2–4 are grouped as **Auth** in flowcharting; 6–8 as **Send/Bulk**; 12–14 as
**Contacts & Numbers**; 22–23 as **Public site**; 25 folded into the audit report directly
(infra, not a page flow). This gives 6 fan-out groups for Phase 1/2, on top of the 3 core files
already read in full by the orchestrator.
