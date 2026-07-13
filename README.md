<p align="center"><img src="public/assets/img/logo.png" alt="ELLSMS — Smart SMS Panel" width="420"></p>

# ELLSMS — Smart SMS Panel

A self-hosted SMS panel built with **PHP 8.2 + MySQL 8**, deployed with **Docker Compose**. It sends messages through the ravixops REST gateway (`POST /api/messages/send`) and gives you scheduling, user management, credit control, and detailed sent/received reports.

## Features

- **Send SMS now** to one or thousands of numbers (paste numbers or pick a contact group); 09… numbers are normalized to 98… automatically; live character/part counter with Persian (Unicode) support.
- **Scheduled sending** — pick a date & time, optionally repeat daily/weekly/monthly; a worker container dispatches due messages automatically; cancel any time.
- **Users & roles** — admins create users, set each user's sender line and gateway `sender_user_id`, reset passwords, enable/disable accounts, and add/subtract SMS credit. Every user can change their own password from Profile.
- **Detailed sent report** — filter by date range, status, user, destination, or message text; summary cards (total / sent / failed / credits used); CSV export (Excel-friendly UTF-8).
- **Inbox (received messages)** — a webhook endpoint stores incoming SMS; browse, filter, and export them.
- **Delivery reports** — a DLR webhook updates message status to delivered/undelivered.
- **Contacts & groups** with bulk paste import.
- **Audit log** of logins, sends, credit changes and admin actions (table `audit_log`).

## Quick start (local or server)

Requirements: Docker + Docker Compose plugin.

```bash
git clone <your-repo-url> ellsms
cd ellsms
cp .env.example .env          # edit passwords/ports!
docker compose up -d --build
```

Open **http://localhost:8080** (or your server IP/domain).

First login: **admin / admin123** — the account is created automatically on the first visit to the login page. **Change this password immediately** (Profile → Change password).

phpMyAdmin (optional) is on port **8081**. Remove that service from `docker-compose.yml` in production, or firewall it.

## Configuration

| Where | What |
|---|---|
| `.env` | DB credentials, ports, default gateway base URL |
| Panel → Settings (admin) | Gateway base URL, default `sender_user_id`, default sender line, webhook token |
| Panel → Users | Per-user sender line, per-user `sender_user_id`, credit |

Credits = SMS parts. Admins send without credit limits; normal users need enough credit (parts × destinations).

## Webhooks (give these to your SMS provider)

Shown with your token in **Settings**:

- Incoming SMS: `https://your-domain/api/incoming.php?token=…`
  Body: `{"sender":"98912…","recipient":"5000435800","content":"…"}` (aliases `from/to/text/message` also accepted; single object or array).
- Delivery reports: `https://your-domain/api/dlr.php?token=…`
  Body: `{"message_id":"…","status":"delivered"}`.

## Git workflow & server deployment

1. Create an empty repository on GitHub/GitLab.
2. From this project folder:
   ```bash
   git remote add origin git@github.com:YOURNAME/ellsms.git
   git push -u origin main
   ```
3. On the server (first time):
   ```bash
   git clone git@github.com:YOURNAME/ellsms.git /opt/ellsms
   cd /opt/ellsms && cp .env.example .env && nano .env
   docker compose up -d --build
   ```
4. Every time you want to update the server:
   ```bash
   cd /opt/ellsms && ./deploy.sh
   ```
   `deploy.sh` runs `git pull`, rebuilds the images, and restarts the containers. The database lives in the `dbdata` volume and survives redeploys.

### Schema changes after the first install

`db/init.sql` only runs when the database volume is created. If a later update changes the schema, apply the migration manually, e.g.:

```bash
docker compose exec -T db mysql -u ellsms -p"$DB_PASS" ellsms < db/migrations/xxxx.sql
```

## Production notes

- Put the panel behind HTTPS (e.g., Caddy or nginx reverse proxy in front of port 8080).
- Change `DB_PASS`, `DB_ROOT_PASS`, the admin password, and regenerate the webhook token.
- Back up the database: `docker compose exec db mysqldump -u root -p ellsms > backup.sql`.

## Project layout

```
app/            bootstrap (db, auth, helpers), SMS gateway client, layout views
public/         web root — pages, assets (logo/css), webhook API
cron/worker.php scheduler loop (runs in the worker container)
db/init.sql     schema + seed settings
docker/         PHP-Apache image
deploy.sh       git pull + rebuild + restart
```
