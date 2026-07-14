<p align="center"><img src="public/assets/img/logo.png" alt="ELLSMS — Smart SMS Panel" width="420"></p>

# ELLSMS — Smart SMS Panel

A self-hosted SMS panel (**PHP 8.2 + Docker Compose**) that shares its **database with the `negar-python` project** — no separate database, no duplicate user list. It logs in with existing negar accounts, sends SMS by calling the Vesal/Armaghan gateway directly (the same one negar-python's backend uses), and writes straight into negar's own `outbound_message` / reads `inbound_message` tables.

## How this fits together with negar-python

```
                 ┌─────────────────────────────┐
                 │      MySQL: negar-mysql      │
                 │   database `negar`            │
                 │                                │
                 │  owned by negar-python:        │
                 │    user_, outbound_message,     │
                 │    inbound_message, domain,      │
                 │    customer, role, access        │
                 │                                │
                 │  added by ELLSMS (ellsms_*):     │
                 │    ellsms_meta, ellsms_schedule,  │
                 │    ellsms_settings, ellsms_contacts,│
                 │    ellsms_audit_log                │
                 └───────────────┬─────────────────────┘
                                  │
             ┌────────────────────┼────────────────────┐
             │                    │                      │
    negar-python rest_api    ELLSMS app/worker      Vesal/Armaghan
    (still runs — owns       (this project)          SMS gateway
     /mo and /delivery,       sends by calling        (called directly
     receives inbound SMS     Vesal directly and       by both sides)
     & delivery reports)      writing outbound_message
```

**Key point:** ELLSMS does **not** call negar-python's REST API to send messages. It calls the Vesal/Armaghan gateway directly and inserts rows into `outbound_message` itself — the same table negar-python's own `/api/messages/send` would write to, and the same table negar-python's `/delivery` webhook updates by `reference_id`. So delivery-status updates keep working automatically for messages ELLSMS sends, and inbound messages (`/mo`) keep landing in `inbound_message` the same way — ELLSMS just reads them.

You still need the `negar-python` stack running (for `/mo`, `/delivery`, and the MySQL database itself) — ELLSMS attaches to its `negar_net` Docker network.

## Login model

ELLSMS does **not** have its own user database. It authenticates against negar's `user_` table (same username/password), using the same SHA-256 hashing negar-python's `rest_api/routers/users.py` uses today. **That hashing is explicitly a placeholder on the negar-python side (not salted, not production-grade)** — ELLSMS matches it for compatibility, not because it's the hashing scheme either project should keep long-term. Improving it needs a coordinated migration on both sides.

A negar account only gets into the ELLSMS panel once an admin **grants access** (Users → Grant access) — being a valid negar login is not enough by itself. ELLSMS does not create brand-new negar accounts (that requires a Customer/Domain graph on the negar side); grant access to an account created the normal way first.

## Quick start

Requirements: the `negar-python` stack already running (so `negar_net` and `negar-mysql` exist).

```bash
git clone <your-repo-url> ellsms
cd ellsms
cp .env.example .env
# Fill in NEGAR_DB_PASS to match negar-python's own .env (DATABASE__PASSWORD),
# and VESAL_REST_URL / VESAL_USERNAME / VESAL_PASSWORD to match its
# OPERATOR_LINK__VESAL_* values.

docker compose up -d --build

# Apply ELLSMS's supplementary tables into the shared database (safe to re-run):
docker exec -i negar-mysql mysql -u"$NEGAR_DB_USER" -p"$NEGAR_DB_PASS" "$NEGAR_DB_NAME" < db/ellsms_extra.sql
```

Open **http://localhost:8080/bootstrap-admin.php** — this is a one-time page: type the username/password of any *existing* negar account and it becomes the first ELLSMS admin. After that, log in normally at `/login.php`, and grant access to other negar accounts from **Users**.

## Configuration

| Where | What |
|---|---|
| `.env` | `NEGAR_DB_*` (must match negar-python's own DB credentials), `VESAL_*` defaults |
| Panel → Settings (admin) | Vesal REST URL / username / password (overrides `.env`, stored in `ellsms_settings`), default sender line |
| Panel → Users | Grant/revoke panel access, admin flag, per-user sender line, credit (writes to negar's `user_.currentcredit`) |

Credits = SMS parts, stored directly on the shared `user_.currentcredit` column — so it reflects the same balance negar-python itself would see. Admins send without a credit check.

## Git workflow & server deployment

```bash
git remote add origin git@github.com:YOURNAME/ellsms.git
git push -u origin main
```

On the server (first time, after negar-python's own stack is already up):
```bash
git clone git@github.com:YOURNAME/ellsms.git /opt/ellsms
cd /opt/ellsms && cp .env.example .env && nano .env
docker compose up -d --build
docker exec -i negar-mysql mysql -u"$NEGAR_DB_USER" -p"$NEGAR_DB_PASS" "$NEGAR_DB_NAME" < db/ellsms_extra.sql
```

Every later update:
```bash
cd /opt/ellsms && ./deploy.sh
```
`deploy.sh` pulls, rebuilds, restarts the containers, and re-applies `db/ellsms_extra.sql` (harmless if nothing changed — every statement is `CREATE TABLE IF NOT EXISTS` / `ON DUPLICATE KEY UPDATE`).

If MySQL isn't reachable via `docker exec` (e.g. it's a managed/remote server, not a `negar-mysql` container on this host), apply the schema directly instead:
```bash
mysql -h <host> -u <user> -p <database> < db/ellsms_extra.sql
```

## Production notes

- Put the panel behind HTTPS (Caddy/nginx reverse proxy in front of port 8080) — negar-python's own `deploy/Caddyfile` is a reasonable model to extend.
- The SHA-256 password hashing is a known weak point inherited from negar-python's current placeholder implementation — track their migration to real hashing and update `negar_hash_password()`/`negar_verify_password()` in `app/bootstrap.php` alongside it.
- Back up the shared database the same way you already back up negar-python's: `docker exec negar-mysql mysqldump -u root -p negar > backup.sql`. ELLSMS's own tables (`ellsms_*`) are included in that same dump — no separate backup needed.

## Project layout

```
app/                bootstrap (shared-DB connection, negar-compatible auth), Vesal gateway client, layout views
public/             web root — pages, assets (logo/css), bootstrap-admin.php
cron/worker.php     scheduler loop (runs in the worker container)
db/ellsms_extra.sql supplementary ELLSMS tables — never touches negar's own tables
docker/             PHP-Apache image
deploy.sh           git pull + rebuild + restart + re-apply supplementary schema
```
