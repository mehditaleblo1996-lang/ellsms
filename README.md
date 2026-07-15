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
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Open **http://localhost:8080/bootstrap-admin.php** — this is a one-time page: type the username/password of any *existing* account and it becomes the first ELLSMS admin. After that, log in normally at `/login.php`, and grant access to other accounts from **Users**.

## Configuration

| Where | What |
|---|---|
| `.env` | `BACKEND_NETWORK`, `BACKEND_DB_*` (must match the backend's own DB config), `API_BASE_URL` default |
| Panel → Settings (admin) | API base URL (overrides `.env`, stored in `ellsms_settings`), default sender line |
| Panel → Users | Grant/revoke panel access, admin flag, per-user sender line, credit (writes to the shared `user_.currentcredit`) |

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
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Every later update:
```bash
cd /opt/ellsms && ./deploy.sh
```
`deploy.sh` pulls, rebuilds, restarts the containers, and re-applies `db/ellsms_extra.sql` (harmless if nothing changed — every statement is `CREATE TABLE IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE`).

If MySQL isn't reachable via `docker exec` (e.g. it's a managed/remote server, not a local container), apply the schema directly instead:
```bash
mysql -h <host> -u <user> -p <database> < db/ellsms_extra.sql
```

## Language & calendar

The panel is Persian (Farsi) and right-to-left throughout — every menu, label, button, and message. Dates and times are shown in the Jalali (Shamsi) calendar with Persian digits everywhere they're read, while phone numbers, credit amounts, and other raw figures stay in Latin digits and left-to-right so they remain scannable and copyable inside RTL text. Date pickers (scheduling a send, filtering reports/inbox) are plain year/month/day/hour/minute dropdowns using the Jalali calendar — no JavaScript date-picker library or CDN dependency, so it works the same with or without outside network access. The Jalali↔Gregorian conversion is a small pure-PHP implementation in `app/bootstrap.php` (`gregorian_to_jalali()` / `jalali_to_gregorian()`), verified against known Nowruz dates.

## Numbers, bulk categories, KYC profiles, and SMS 2FA

- **Numbers pool** (admin, Numbers page) — create sender lines and assign each to one panel user. A user with assigned numbers gets a dropdown instead of free-text entry when sending or setting up منشی پیامک rules; a user with none falls back to the legacy single `originator` field for backward compatibility.
- **Bulk number categories** (admin, Number Categories page) — upload a newline-separated `.txt` file of numbers under a name. Every panel user (not just admins) sees these as a selectable option on Send, alongside their own private Contacts groups.
- **KYC profile layer** (Users page for admins, Profile page for self-service) — father's name, address, and two document photo uploads (ID card + a second document such as a passport) live entirely in ELLSMS's own tables, layered on top of a granted-access account. ELLSMS does not create or edit the backend's own `user_` row for this — see "Login model" above for why. Photos are stored outside the web root (`storage/kyc/`, gitignored) and served only through `public/kyc-photo.php`, which checks the viewer is either that user or an admin before streaming anything.
- **SMS-based 2FA** — admin can enable it per user (Users → edit) or for everyone at once (Users → "فعال‌سازی ورود دومرحله‌ای برای همه"). When enabled, a correct password redirects to a 6-digit code sent to the account's `user_.mobile` (5-minute expiry, 5 wrong attempts before being sent back to login, 60-second resend cooldown) before a session is actually created.

## Production notes

- Put the panel behind HTTPS (Caddy/nginx reverse proxy in front of port 8080).
- The SHA-256 password hashing is a known weak point inherited from the backend platform's current placeholder implementation — track any migration to real hashing on that side and update `backend_hash_password()` / `backend_verify_password()` in `app/bootstrap.php` alongside it.
- Back up the shared database the same way you already back up the backend platform's own data: `docker exec <db-container> mysqldump -u root -p <database> > backup.sql`. ELLSMS's own tables (`ellsms_*`) are included in that same dump — no separate backup needed.

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
