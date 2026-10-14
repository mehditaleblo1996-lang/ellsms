# Staging environment

A full second copy of ELLSMS on the **same server** as production, with its own database
(`ellsms_stage` on the same MySQL server), its own containers, image, ports, Docker network and
secrets. It tracks the `staging` branch: every push to `staging` is deployed within a minute.

```
/opt/ellsms        branch main     project "ellsms"        port 8080   DB ellsms        network negar_net
/opt/ellsms-stage  branch staging  project "ellsms-stage"  port 8081   DB ellsms_stage  network ellsms_stage_net
```

Everything is driven by `ops/staging/stage.sh`, run from the **staging** checkout.

## What keeps staging away from production

| Risk | How it is avoided |
|---|---|
| Container / image name collisions | `ELLSMS_CONTAINER_PREFIX=ellsms-stage`, `ELLSMS_APP_IMAGE=ellsms-stage-app:latest` (production leaves both unset and keeps its names). |
| Service-name collisions (`mongo`, `smpp-bridge`) | Staging runs on its own network `ellsms_stage_net`, never on `negar_net`. |
| Writing to production's database | Own database **and** own MySQL user that only has rights on `ellsms_stage`. `stage.sh` refuses any database name without `stage` in it. |
| Sending real SMS | All real gateways are archived; the mock gateway (`mock-sms-gateway` container) is the default and takes every route and pinned number. |
| Replaying production's queue | Pending bulk jobs/items, schedules, direct-send queue, imports and regional bulk are cancelled. |
| Calling customers | Webhook endpoints and remote-DB connections are disabled. |
| Production integrations | Fresh keys/tokens; no backend API URL, Telegram, Bale or Vesal settings; Zarinpal sandbox + fake gateway. DB-stored settings for these are deleted (they would override `.env`). |

The full list is in `ops/staging/sanitize.sql`; it runs after every restore.

## First-time setup

```bash
# 1. A second checkout on the staging branch (reuse production's remote, including its credentials)
git clone -b staging "$(git -C /opt/ellsms remote get-url origin)" /opt/ellsms-stage
cd /opt/ellsms-stage

# 2. Everything else in one go: .env, network, build, DB + user, copy of production's data,
#    sanitize, uploaded files, start, every-minute auto-deploy.
STAGE_DOMAIN=stage.ellsms.ravixops.com ops/staging/stage.sh setup
```

`setup` asks for the MySQL **admin** password once (user `root` by default; `DB_ADMIN_USER=...` to
change it) to create `ellsms_stage` and `ellsms_stage_user`. The same steps can be run one by one:

```bash
STAGE_DOMAIN=stage.ellsms.ravixops.com ops/staging/stage.sh env   # writes .env (fresh secrets)
ops/staging/stage.sh network
docker compose build
ops/staging/stage.sh db-init        # CREATE DATABASE ellsms_stage + user
ops/staging/stage.sh db-backup      # dump production -> storage/backups/stage-db/ellsms-<date>.sql.gz
ops/staging/stage.sh db-restore     # load the newest dump into ellsms_stage, migrate, sanitize, mock gateway
ops/staging/stage.sh files-sync     # KYC files, slides, landing videos from /opt/ellsms
./deploy.sh
ops/staging/stage.sh autodeploy-install
```

Then point the staging domain at port 8081 in the reverse proxy, e.g. nginx:

```nginx
server {
    server_name stage.ellsms.ravixops.com;
    # listen 443 ssl; + certificate, as for production
    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Log in with any production username and password (the user table is copied). 2FA is reset on
staging because its `MFA_MASTER_KEY` is different.

## Daily use

```bash
# from your machine: put changes on staging
git checkout staging && git merge <feature-branch> && git push origin staging
# -> deployed on the server within a minute (log: /opt/ellsms-stage/storage/logs/stage-autodeploy.log)

# when it is checked: release to production
git checkout main && git merge staging && git push origin main
# -> on the server: cd /opt/ellsms && ./deploy.sh   (unchanged)
```

Refresh staging's data from production at any time (the stack is stopped during the restore so no
worker sees unsanitized rows):

```bash
cd /opt/ellsms-stage && ops/staging/stage.sh db-refresh
```

Restore a specific dump: `ops/staging/stage.sh db-restore storage/backups/stage-db/ellsms-20261014-101500.sql.gz`.
Lighter copy without the big message tables' rows:
`SKIP_DATA_TABLES="outbound_message inbound_message ellsms_bulk_items_archive ellsms_message_attempts" ops/staging/stage.sh db-backup`.

Test sends show up in staging's own reports as delivered by the mock gateway; its request log is
`docker logs ellsms-stage-mock-sms-gateway`. `MOCK_SMS_MODE` in `.env` switches the mock to failure
modes for testing error handling.

## Troubleshooting

- **`Access denied` / cannot connect from staging containers**: the staging network has a different
  subnet than `negar_net`; the user is created as `'ellsms_stage_user'@'%'`, but a firewall on the
  MySQL host may still need to allow the new subnet (`docker network inspect ellsms_stage_net`).
- **`db-init` cannot log in as root**: create the database and user by hand with the values from
  `/opt/ellsms-stage/.env` (`BACKEND_DB_NAME`, `BACKEND_DB_USER`, `BACKEND_DB_PASS`):
  `CREATE DATABASE ellsms_stage CHARACTER SET utf8mb4; CREATE USER 'ellsms_stage_user'@'%' IDENTIFIED BY '<pass>'; GRANT ALL PRIVILEGES ON ellsms_stage.* TO 'ellsms_stage_user'@'%';`
- **Import fails creating a trigger/function with binary logging on**: set
  `log_bin_trust_function_creators=1` on the MySQL server, or restore with the admin user.
- **Auto-deploy says the branch was rewritten**: `staging` was force-pushed. On the server:
  `git reset --hard origin/staging && ./deploy.sh`.
- **Auto-deploy does nothing**: `git fetch` must work non-interactively for root's cron (same
  credentials production's `git pull` uses). Check the log above.
- **New `.env` (`FORCE=1 ... env`)**: it generates a new database password and keys, so re-run
  `db-init` and `db-restore` afterwards.
