<p align="center"><img src="public/assets/img/logo.png" alt="ELLSMS — Smart SMS Panel" width="420"></p>

# ELLSMS — Smart SMS Panel

A self-hosted SMS panel (**PHP 8.2 + Docker Compose**) that shares its **database with a connected backend SMS platform** — no separate user database, no duplicate accounts. It logs in with existing backend accounts, and sends SMS by calling the backend's own REST API.

## How this fits together with the backend platform

```
                 ┌─────────────────────────────┐
                 │      shared MySQL database    │
                 │                                │
                 │  owned by the backend platform: │
                 │    user_, outbound_message,      │
                 │    inbound_message, domain,       │
                 │    customer, role, access          │
                 │                                      │
                 │  added by ELLSMS (ellsms_*):          │
                 │    ellsms_meta, ellsms_schedule,       │
                 │    ellsms_settings, ellsms_contacts,    │
                 │    ellsms_audit_log                      │
                 └───────────────┬───────────────────────────┘
                                  │
             ┌────────────────────┼────────────────────┐
             │                    │                      │
    Backend REST API          ELLSMS app/worker      SMS gateway
    (POST /api/messages/send,  (this project) —        (called by the
     also owns the /mo and     calls the backend's       backend platform,
     /delivery endpoints        REST API to send           not directly
     that receive inbound       and reads the shared        by ELLSMS)
     SMS & delivery reports)    tables for everything else
```

**Key point:** ELLSMS sends by calling the backend platform's own `POST {API_BASE_URL}/api/messages/send` — it does not talk to the underlying SMS gateway directly. The backend API performs the actual send and writes the resulting rows into the shared `outbound_message` table; ELLSMS reads those rows back from the response, so there's a single place that owns "what was actually sent." Delivery-status updates and inbound messages keep arriving the normal way through the backend's own receiver endpoints — ELLSMS just reads `inbound_message` / `outbound_message`, it doesn't need its own webhook for those.

You still need the backend platform's stack running (for its REST API, its `/mo` and `/delivery` endpoints, and the database itself) — ELLSMS attaches to its Docker network to reach the shared database.

## Login model

ELLSMS does **not** have its own user database. It authenticates against the shared `user_` table (same username/password), using the same SHA-256 hashing the backend's own account system currently uses. **That hashing is a known placeholder, not something ELLSMS chose** — it matches it purely for compatibility. Improving it needs a coordinated change on both sides.

An account only gets into the ELLSMS panel once an admin **grants access** or **creates it** (Users page). Creating a new account calls the backend's own `POST /api/users/` endpoint (the same one that already existed for this purpose) rather than ELLSMS writing directly into `user_` — that endpoint already knows the exact required columns and applies the backend's own password hashing and uniqueness rules, so ELLSMS doesn't have to guess at any of it. A domain (multi-tenant scope) must already exist on the backend side; ELLSMS lets you pick one but doesn't create domains.

## Quick start

Requirements: the backend platform's own stack already running (so its Docker network and database exist).

```bash
git clone <your-repo-url> ellsms
cd ellsms
cp .env.example .env
# Fill in BACKEND_NETWORK, BACKEND_DB_* to match the backend's own
# deployment, and API_BASE_URL to the backend's REST API address.

docker compose up -d --build

# Apply ELLSMS's supplementary tables into the shared database (safe to re-run):
docker compose exec -T app mysql --ssl-verify-server-cert=0 -h"$BACKEND_DB_HOST" -P"${BACKEND_DB_PORT:-3306}" -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Open **http://localhost:8080/bootstrap-admin.php** — this is a one-time page: type the username/password of any *existing* account and it becomes the first ELLSMS admin. After that, log in normally at `/login.php`, and grant access to other accounts from **Users**.

## Configuration

| Where | What |
|---|---|
| `.env` | `APP_ENV` (production forcibly disables debug output regardless of `APP_DEBUG`), `APP_DEBUG`, `APP_URL` (canonical base URL, used as a safer fallback than the request's Host header for deriving callback URLs), `APP_VERSION` (build identifier shown in logs, `/health`, and the panel footer — falls back to the baked-in `ELLSMS_VERSION` constant), `WORKER_POLL_INTERVAL_SECONDS` (worker container tick rate, default 8), `WORKER_JOB_LEASE_SECONDS`/`JOB_MAX_ATTEMPTS`/`JOB_RETRY_BASE_SECONDS`/`JOB_RETRY_MAX_SECONDS` (Phase 4 job-queue claim lease and retry policy — see `docs/job-queue-architecture.md`), `BACKEND_NETWORK`, `BACKEND_DB_*` (must match the backend's own DB config), `API_BASE_URL` default |
| Panel → Settings (admin) | API base URL (overrides `.env`, stored in `ellsms_settings`), default sender line |
| Panel → Users | Grant/revoke panel access, admin flag, per-user sender line, credit (writes to the shared `user_.currentcredit`) |

All sensitive/runtime configuration is read from environment variables (`.env`, wired into each
container via `docker-compose.yml`), with most integrations additionally overridable at runtime
from Panel → Settings (stored in `ellsms_settings`, which wins over `.env` when both are set — see
each integration's own section below for exactly which). No secret ever needs to be committed;
`.env` is gitignored and `.env.example` contains placeholders only.

Credits = SMS parts, stored directly on the shared `user_.currentcredit` column — so it reflects the same balance the backend platform itself would see. Admins send without a credit check.

## Git workflow & server deployment

```bash
git remote add origin git@github.com:YOURNAME/ellsms.git
git push -u origin main
```

On the server (first time, after the backend's own stack is already up):
```bash
git clone git@github.com:YOURNAME/ellsms.git /opt/ellsms
cd /opt/ellsms && cp .env.example .env && nano .env
docker compose up -d --build
docker compose exec -T app mysql --ssl-verify-server-cert=0 -h"$BACKEND_DB_HOST" -P"${BACKEND_DB_PORT:-3306}" -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Every later update:
```bash
cd /opt/ellsms && ./deploy.sh
```
`deploy.sh` pulls, rebuilds, restarts the containers, and re-applies `db/ellsms_extra.sql` (harmless if nothing changed — every statement is `CREATE TABLE IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE`). It runs the `mysql` CLI from inside the already-running `app` container and connects to `BACKEND_DB_HOST` over the network, so this works the same whether that's a local container on the same Docker network or a separate/managed/remote database host — no special-casing needed either way.

If `BACKEND_DB_HOST` presents a self-signed or internal-CA TLS certificate (common for an internal-network database), `deploy.sh` and the snippets above already pass `--ssl-verify-server-cert=0` by default — the connection is still encrypted whenever the server offers TLS, just not certificate-chain-validated. Set `BACKEND_DB_SSL_VERIFY=1` in `.env` (and `BACKEND_DB_SSL_CA=<path-inside-the-container>` if the CA isn't in the system trust store) to require a properly verified certificate instead; `app/bootstrap.php`'s own database connection honors the same two variables.

## Language & calendar

The panel is Persian (Farsi) and right-to-left throughout — every menu, label, button, and message. Dates and times are shown in the Jalali (Shamsi) calendar with Persian digits everywhere they're read, while phone numbers, credit amounts, and other raw figures stay in Latin digits and left-to-right so they remain scannable and copyable inside RTL text. Date pickers (scheduling a send, filtering reports/inbox) are plain year/month/day/hour/minute dropdowns using the Jalali calendar — no JavaScript date-picker library or CDN dependency, so it works the same with or without outside network access. The Jalali↔Gregorian conversion is a small pure-PHP implementation in `app/bootstrap.php` (`gregorian_to_jalali()` / `jalali_to_gregorian()`), verified against known Nowruz dates.

## Numbers, bulk categories, KYC profiles, and SMS 2FA

- **Numbers pool** (admin, Numbers page) — create sender lines and assign each to one panel user. A user with assigned numbers gets a dropdown instead of free-text entry when sending or setting up منشی پیامک rules; a user with none falls back to the legacy single `originator` field for backward compatibility.
- **Bulk number categories** (admin, Number Categories page) — upload a newline-separated `.txt` file of numbers under a name. Every panel user (not just admins) sees these as a selectable option on Send, alongside their own private Contacts groups.
- **KYC profile layer** (Users page for admins, Profile page for self-service) — father's name, address, and two document photo uploads (ID card + a second document such as a passport) live entirely in ELLSMS's own tables, layered on top of a granted-access account. ELLSMS does not create or edit the backend's own `user_` row for this — see "Login model" above for why. Photos are stored outside the web root (`storage/kyc/`, gitignored) and served only through `public/kyc-photo.php`, which checks the viewer is either that user or an admin before streaming anything.
- **SMS-based 2FA** — admin can enable it per user (Users → edit) or for everyone at once (Users → "فعال‌سازی ورود دومرحله‌ای برای همه"). When enabled, a correct password redirects to a 6-digit code sent to the account's `user_.mobile` (5-minute expiry, 5 wrong attempts before being sent back to login, 60-second resend cooldown) before a session is actually created.

## Buying credit — ZarinPal

Any logged-in user can buy credit from `/buy-credit.php` — pick a preset package or a custom amount, pay through ZarinPal, and credit lands on `user_.currentcredit` automatically once the payment is confirmed. Built against ZarinPal's real v4 REST API (endpoints and request/response shapes verified against their own sample code and official docs, not reconstructed from memory) — `app/zarinpal.php` handles the request/verify calls, `public/zarinpal-callback.php` is where ZarinPal redirects the user back to.

**Important — the unit is Rial, not Toman.** ZarinPal's v4 API defaults to Rial unless a request explicitly opts into Toman; this integration never sends that opt-in, so `چند ریال معادل ۱ واحد اعتبار است` in Settings, `ellsms_payments.amount_rial`, and every amount ELLSMS sends to ZarinPal are all unambiguously Rial. Don't introduce a Toman value anywhere in this path without converting it first.

Admin configures, from **Settings → پرداخت (زرین‌پال)**:
- Rial-per-credit exchange rate
- Minimum purchase (in credits)
- Suggested package sizes (comma-separated, shown as quick-pick buttons)
- Merchant ID, callback URL, and sandbox mode (test payments, no real money) — these three can also come from `.env`:
  ```
  ZARINPAL_MERCHANT_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx   # from https://next.zarinpal.com
  ZARINPAL_CALLBACK_URL=https://your-domain/zarinpal-callback.php
  ZARINPAL_SANDBOX=0                                            # 1 for test mode
  ```
  Settings (`ellsms_settings`) always wins over `.env` if both are set — same pattern as every other config in this project.

**Double-crediting is explicitly guarded against.** ZarinPal can call the callback URL more than once for the same payment (retries, a user refreshing the result page). The callback handler only credits an account on the specific database update that actually flips a payment row from `pending` to `paid` — a repeat hit finds the row already `paid`, does nothing, and shows "already processed" instead of adding credit twice.

## پنل جدید ارسال — combined send panel

A single three-column page (`/new-send.php`) matching a reference design: message composer on the right (character/part counter, emoji insert, a Persian→Latin digit converter to save SMS parts, live preview, quick-send shortcut), recipient building in the middle (manual entry with a live valid/invalid split as you type, file upload, clipboard paste — all three funnel into the same textarea), and send-mode settings on the left. It doesn't duplicate logic — every mode routes to something that already existed:

- **ارسال مستقیم** (direct) → the same `dispatch_message()` call the regular Send page uses.
- **ارسال دوره‌ای** (recurring) → `ellsms_schedule`, same as Schedules.
- **ارسال تدریجی** (gradual/throttled) → a new pacing mode on the bulk-send engine (`ellsms_bulk_jobs.throttle_count`/`throttle_minutes`): "send N rows every M minutes" instead of the worker's normal as-fast-as-it-can batching. p2p and smart jobs are unaffected — they simply don't set a throttle, so they keep the original behavior.

Two new per-user concepts came with this: a **do-not-contact list** (`/blacklist.php`, `ellsms_blacklist`) that the "فقط ارسال به لیست سفید" toggle filters against before sending, and **saved campaigns** (`ellsms_campaigns`) — a reusable sender+message template reloadable from a dropdown on the new panel.

## Bulk personalized sending — نظیر به نظیر and پیامک هوشمند

Two upload-a-spreadsheet features that share one engine (`ellsms_bulk_jobs` / `ellsms_bulk_items`, processed by the worker's `run_bulk_send_pass()`):

- **ارسال نظیر به نظیر** (`/p2p-send.php`) — column A is the mobile number, column B is that row's complete message text, already written out. Every row can say something completely different.
- **پیامک هوشمند** (`/smart-send.php`) — column A is the mobile number, column B is that row's message template written with `{column_name}` placeholders (e.g. `سلام {نام}، مبلغ {مبلغ} تومان`), and every column from C onward is a variable's value, named by its header in row 1. There's no separate template field in the panel — the whole thing lives in the spreadsheet, so different rows could even use different templates if you wanted. An unmatched placeholder is left literal in the message rather than silently going blank, so a typo in a column name is obvious immediately.

Both accept `.xlsx` or `.csv`. XLSX reading is a small hand-written parser in `app/xlsx_reader.php` (ZipArchive + SimpleXML, both PHP built-ins) rather than a Composer package — this project has no `vendor/` directory anywhere else, and the actual need is narrow (plain cell values, no formulas/styles). Requires the PHP `zip` extension, which `docker/Dockerfile` installs.

Uploads don't send synchronously — a large file sending row-by-row inside one HTTP request risks a PHP timeout. Instead the upload is parsed, costed against your credit up front, and queued; the worker sends up to 20 rows per 8-second tick (the same loop already running schedules and منشی پیامک) and the page shows live sent/failed/total counts. Cancelling a job stops any rows still pending.

## Mobile & general UX polish

The panel now has a real mobile navigation drawer instead of stacking the entire sidebar above page content — as the nav grew past a dozen links, that stacking became genuinely unusable on a phone. Below 900px a hamburger button in the topbar slides the sidebar in from the right with a dismissible backdrop (tap outside, press Escape, or tap any link to close); above that it's the normal fixed sidebar. Other changes: form inputs are 16px to stop iOS Safari's auto-zoom-on-focus, buttons and nav items get larger touch targets on small screens, tables and cards tighten their padding progressively at 900px and 480px breakpoints, and every page (not just login) now has the same subtle brand-color background wash instead of a flat canvas. All of this lives in `public/assets/css/style.css` plus a small vanilla-JS toggle in `app/views/footer.php` — no framework, no build step.

## Public marketing site — landing, تماس با ما, راهنمای استفاده

`/index.php` (the site root) now shows `/landing.php` to anyone not logged in instead of bouncing straight to `/login.php` — the dashboard behavior for logged-in users is unchanged. Login is still reachable from the landing page's own CTA. Everything under this section is public, unauthenticated, and shares one chrome (`app/views/public_header.php` / `public_footer.php`) separate from the logged-in app shell in `app/views/header.php`.

- **Hero slider** — an admin-managed (Settings → اسلایدر صفحه‌ی اصلی, `/slides.php`) image carousel spanning the full page width directly under the nav, above the hero text. Each slide is an image + title + optional short text + optional link. Falls back to a static decorative mockup when no slides are configured, so the page never looks broken on a fresh install. Images upload straight into `public/assets/img/slides/` (gitignored, like `storage/kyc/`) since these are public marketing banners, not sensitive documents — no access-control script needed the way `kyc-photo.php` has one.
- **بسته‌های پیامک / pricing cards** (`/pricing.php` to manage, shown on `/landing.php#pricing`) — a separate marketing-only table (`ellsms_pricing_packages`: name, credit amount, price in Rial, feature bullets, a "پیشنهاد ویژه" badge). Deliberately independent from the real `rial_per_credit` rate in Settings → پرداخت — this is just what's advertised, not what `buy-credit.php` actually charges, so keep them in sync by hand if the real rate changes.
- **راهنمای استفاده** — a public FAQ/guide page (`/guide.php`, plain `<details>`/`<summary>` accordion, no JS needed) backed by admin-editable articles (`/guide-admin.php`, `ellsms_guide_articles`). Body text is plain, rendered with `nl2br()` — no markdown parser, consistent with this project having no `vendor/` directory anywhere else.
- **تماس با ما** (`/contact.php`) — shows an address/phone block from Settings → تماس با ما (hidden entirely if never configured) plus a ticket form. Submitting the form doesn't write to a table — `app/telegram.php` relays it as a plain chat message to a Telegram bot via the official `sendMessage` endpoint, using a Bot Token + Chat ID configured the same `.env`-with-Settings-override pattern as ZarinPal (`TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID`). Create the bot with `@BotFather`.

## Legacy-style URL send API

`GET`/`POST /sms/url_send.html?username=...&password=...&originator=...&destination=...&content=...` — a single-message send endpoint shaped like the URL API a lot of other Iranian SMS panels already expose, so an integration written against one of those can point at ELLSMS with just a URL change. Authentication is the request's own `username`/`password` against the shared `user_` table (same check as logging into the panel, including requiring `ellsms_meta.panel_access`) — it does **not** use the browser session/cookie, every request stands alone. Sending itself goes through the exact same `dispatch_message()` as the regular Send page, so credit accounting and `outbound_message` logging are identical to a normal panel send.

The response is JSON: `{"status":"ok","reference_id":123456789,"error_code":null}` on success, or `{"status":"not ok","reference_id":null,"error_code":-2}` on failure. `error_code` is one of: `-1` missing parameter, `-2` authentication failed, `-3` account has no ELLSMS panel access, `-4` invalid destination, `-5` insufficient credit, `-6` the gateway rejected the send.

`reference_id` is a random 9-digit token, deliberately **not** the real `outbound_message.id` — handing back a raw sequential id would let a caller estimate the total number of messages the whole system has ever sent. The actual row id is still recorded in `ellsms_audit_log` alongside the token (action `api.url_send`), so support can trace a reported reference back to the real message if needed.

The `.html` extension is intentional, matching that same convention, not a typo — `public/sms/url_send.html` is real PHP, executed via an `AddHandler application/x-httpd-php .html` override that `docker/Dockerfile` scopes to just the `public/sms/` directory. **Because credentials travel as request parameters, always call this over HTTPS** (put the panel behind a TLS-terminating reverse proxy, per Production notes below) — anything else exposes the account password in transit and in plain server access logs.

## Public API & webhooks

Phase 12 adds a small, versioned, tenant-scoped REST API (`/api/v1/*`) for customer integrations —
API keys, scopes, idempotent writes, and signed webhook delivery — completely separate from both
the panel's web session and the internal backend-platform HMAC scheme above. **Disabled by default**
(`API_ENABLED=0`) so every existing install is unaffected until an operator opts in. See
`docs/public-api.md` and `docs/webhooks.md` for the full reference, and
`docs/phase-12-final-report.md` for the acceptance-criteria record.

## Plans, subscriptions & quotas

Phase 13 adds a SaaS control plane — plans, subscriptions, per-organization entitlements and usage
quotas, with a plan-aware public API. It is a third authorization layer that sits beside (never
inside) RBAC and API scopes: an owner cannot bypass a plan limit, a paid plan grants no permission,
and platform administration is never governed by any customer's plan.

**Disabled by default** (`BILLING_ENABLED=0`): every entitlement passes, every limit is unlimited,
and the quota subsystem writes no rows, so an existing install is completely unaffected until an
operator opts in. Enablement requires running `make billing-backfill` first, which grandfathers every
existing organization onto an unlimited `legacy` plan. See `docs/plans-and-entitlements.md`,
`docs/billing-operations.md`, and `docs/phase-13-final-report.md`.

## Developer Commands

A `Makefile` wraps the commands below so a new developer doesn't need to read `app/`/`docker/` to
get going — run `make help` for the full list with descriptions. Composer continues to own the
PHPUnit-related commands (`composer test`); `make test` just delegates to it.

| What | Command | Notes |
|---|---|---|
| Install test dependencies | `composer install` (or `make composer-install`) | Dev-only — see "Composer & dependencies" below |
| Lint (PHP syntax check) | `make lint` | Checks every `.php` file under `app/`, `public/`, `cron/`, `tests/`; exits non-zero on any parse error |
| Run tests | `make test` or `composer test` | Runs the PHPUnit suite from STEP 14 (`tests/Unit/`); both commands are equivalent, use whichever fits your workflow |
| Lint + tests together | `make check` | Runs lint, then tests — stops at the first failure |
| Build the Docker image | `make docker-build` | `docker compose build` |
| Start the app (+ worker) | `make up` | `docker compose up -d` |
| Stop | `make down` | `docker compose down` — stops containers, touches no data (ELLSMS has no DB volume of its own) |
| App logs | `make logs` | Tails the `app` container |
| Worker logs | `make worker-logs` | Tails the `worker` container — this is where scheduled/bulk/auto-reply activity shows up |
| Run one worker pass | `make worker-once` | Runs `php cron/worker.php --once` in a throwaway container. Safe to run even while the persistent worker service (started by `make up`) is also running — Phase 4's atomic per-row claiming (`docs/job-queue-architecture.md`) makes concurrent workers safe by construction; running both is redundant/wasted work, not unsafe. The Docker worker service remains the one authoritative way the worker runs continuously; this is for one-off manual runs. |
| Queue health | `make jobs-status` | Read-only status/lease/retry counts across bulk items, bulk jobs, schedules, auto-reply log — see `docs/job-queue-architecture.md` |
| List stuck/expired-lease rows | `make jobs-recover` | Read-only — every row it lists is already self-healing on the next normal worker tick; this is for visibility |
| Force-recover stuck rows | `make jobs-recover-force` | Clears expired leases immediately so the next tick reclaims them right away, instead of waiting on worker timing. Never touches a row whose lease is still valid |
| Inspect the ELLSMS schema | `make db-schema-show` | Read-only — just prints `db/ellsms_extra.sql`, no DB connection made |
| List ELLSMS tables in the live DB | `make db-tables` | Read-only `SHOW TABLES LIKE 'ellsms\_%'` — needs `.env` and a reachable database |
| Apply the ELLSMS schema | `make db-schema-apply` | **Mutation.** Every statement in `db/ellsms_extra.sql` is `CREATE TABLE IF NOT EXISTS`/guarded `ALTER TABLE`/`ON DUPLICATE KEY UPDATE`, so it's safe to re-run — but it is a real write against the shared database. Never run automatically by any other command, by `make up`, or by container startup (`docker/entrypoint.sh` only ever touches the filesystem). Read `docs/database-audit.md` before adding anything to this file. |
| Inspect Phase 2 migrations | `make db-migrations-show` | Read-only — prints every file under `db/migrations/`, no DB connection made |
| Apply Phase 2 migrations | `make db-migrations-apply` | **Mutation.** Applies every `db/migrations/*.sql` file in order (2FA hardening, rate limiting, password-verifier infrastructure — see `db/migrations/README.md`). Same safety properties and the same "never automatic" rule as `db-schema-apply`. |

### Composer & dependencies

Composer is currently used **only** for development/testing dependencies (PHPUnit) —
`require-dev` in `composer.json`, nothing in `require` beyond a PHP version floor. The production
application itself does not require `vendor/` to exist or run; every page and the worker run on
plain PHP with no autoloader dependency, exactly as before this was introduced. That would only
change in a deliberate future phase, not silently.

Test coverage today is deliberately narrow: pure, side-effect-free business logic that's safe to
test without a database or the backend API — phone-number/originator normalization, SMS part
costing, the Jalali calendar conversion, per-operator detection, auto-reply matching/templating,
and `Logger`'s redaction rule. See `docs/technical-debt.md` for what's still untested and why (most
of it needs either a test database or a mocked backend API, neither of which exists yet).

## Production notes

- Put the panel behind HTTPS (Caddy/nginx reverse proxy in front of port 8080), **and set
  `TRUSTED_PROXY_IPS`** to that proxy's address/CIDR (`.env`) — without it, HTTPS detection and
  rate-limit IP resolution fall back to trusting only the direct connection (safe, but not what a
  reverse-proxied deployment wants). See `docs/production-hardening.md`.
- Before deploying: `make config-check`, then `make predeploy-check`. After deploying:
  `make smoke-test URL=https://your-domain`. See `docs/production-hardening.md` for the full
  checklist and `docs/phase-10-final-report.md` for the production-readiness decision (backend HMAC
  verification is still PARTIAL — no verifier exists on the backend platform side of this
  integration; do not treat request signing as an active control until one does).
- The SHA-256 password hashing is a known weak point inherited from the backend platform's current placeholder implementation — track any migration to real hashing on that side and update `backend_hash_password()` / `backend_verify_password()` in `app/bootstrap.php` alongside it.
- Backup/restore/disaster-recovery is now built (Phase 11): `make backup`, `make restore
  BACKUP=<id>`, `make restore-test BACKUP=<id>`, `make backup-status`, `make dr-drill`. Restore is
  **CLI-only** — no web/admin restore button exists or should exist. See
  `docs/backup-and-disaster-recovery.md` for the full reference (encryption, retention, RPO/RTO,
  offsite guidance) and `docs/production-runbook.md` for the release deployment sequence and
  migration rollback matrix. No backup schedule is installed automatically — see that doc's
  scheduled-backup section before relying on this in production.

## A note on the shared infrastructure's real names

ELLSMS's own code, UI, and this README deliberately avoid naming the specific backend project it connects to — all of that lives in `.env`, which you fill in with your deployment's real values (network name, database host/name/credentials, API URL). One thing that's outside ELLSMS's control: the shared **database itself** was created and named by the backend platform's own setup, not by ELLSMS — whatever that name is, it shows up in connection strings and `docker exec` commands by necessity, since that's the literal database being connected to. Renaming it would mean coordinating a migration on the backend side too, which is outside this project's scope unless you'd like that done separately.

## Project layout

```
app/                bootstrap (shared-DB connection, backend-compatible auth), backend API client, layout views
public/             web root — pages, assets (logo/css), bootstrap-admin.php
cron/worker.php     scheduler loop (runs in the worker container)
db/ellsms_extra.sql supplementary ELLSMS tables — never touches the backend's own tables
docker/             PHP-Apache image
deploy.sh           git pull + rebuild + restart + re-apply supplementary schema
```
# ellsms
