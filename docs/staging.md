# Staging environment

A full second copy of ELLSMS on its **own server**, with its own MySQL (a container on the staging
server holding `ellsms_stage`, a copy of production's database), its own secrets, and the mock SMS
gateway. It tracks the `staging` branch: every push to `staging` is deployed within a minute.

```
production server  /opt/ellsms        branch main     DB ellsms        on 10.10.101.231
staging server     /opt/ellsms-stage  branch staging  DB ellsms_stage  in the "mysql" container (docker-compose.stage.yml)
```

Everything is driven by `ops/staging/stage.sh`, run on the **staging** server.

## What keeps staging away from production

| Risk | How it is avoided |
|---|---|
| Writing to production's database | Staging's `BACKEND_DB_HOST` is its own `mysql` container; `stage.sh` refuses any other host, and any database name without `stage`. Production is only ever read (one `mysqldump`). |
| Sending real SMS | All real gateways are archived; the mock gateway (`mock-sms-gateway` container) is the default and takes every route and pinned number. |
| Replaying production's queue | Pending bulk jobs/items, schedules, direct-send queue, imports and regional bulk are cancelled. |
| Calling customers | Webhook endpoints and remote-DB connections are disabled. |
| Production integrations | Fresh keys/tokens; no backend API URL, Telegram, Bale or Vesal settings; Zarinpal sandbox + fake gateway. DB-stored settings for these are deleted (they would override `.env`). |

The full list is in `ops/staging/sanitize.sql`; it runs after every restore.

## Prerequisites on the staging server

- Docker with the compose plugin (`docker compose version`), `git`, `gzip`.
- Read access to the GitHub repository (deploy key or token, as on production) — cron runs
  `git fetch` as root without a prompt.
- DNS for the staging domain pointing at this server, and nginx (or another proxy) with TLS.

## First-time setup

### 1. On the production server: dump the database

```bash
cd /opt/ellsms && ( set -o pipefail; set -a; . ./.env; set +a
  MYSQL_PWD="$BACKEND_DB_PASS" docker compose run --rm --no-deps -T -e MYSQL_PWD app \
    mysqldump --ssl-verify-server-cert=0 --default-character-set=utf8mb4 \
      --single-transaction --quick --routines --events --triggers --no-tablespaces --hex-blob \
      -h"$BACKEND_DB_HOST" -P"${BACKEND_DB_PORT:-3306}" -u"$BACKEND_DB_USER" "$BACKEND_DB_NAME" \
  | gzip -1 > /root/ellsms-prod.sql.gz ) && gzip -t /root/ellsms-prod.sql.gz && ls -lh /root/ellsms-prod.sql.gz

scp /root/ellsms-prod.sql.gz root@STAGING_SERVER:/root/
rm /root/ellsms-prod.sql.gz          # it holds every customer's data
```

`--single-transaction` takes a consistent snapshot without locking production.

### 2. On the staging server

```bash
git clone -b staging https://github.com/mehditaleblo1996-lang/ellsms /opt/ellsms-stage
cd /opt/ellsms-stage
STAGE_DOMAIN=stage.ellsms.ravixops.com DUMP=/root/ellsms-prod.sql.gz ops/staging/stage.sh setup
rm /root/ellsms-prod.sql.gz
```

`setup` writes `.env` (fresh secrets), creates the Docker network, builds the images, starts the
staging MySQL (which creates `ellsms_stage` and `ellsms_stage_user`), loads the dump, runs the
migrations, sanitizes, seeds the mock gateway, starts the stack with `./deploy.sh`, and installs
the every-minute auto-deploy cron entry.

Optional: `PROD_SSH=root@PRODUCTION_SERVER ops/staging/stage.sh files-sync` copies uploaded files
(KYC documents, slides, landing videos) over ssh.

### 3. Reverse proxy

The app listens on port 8080 of the staging server, e.g. nginx:

```nginx
server {
    server_name stage.ellsms.ravixops.com;
    # listen 443 ssl; + certificate, as for production
    client_max_body_size 128m;
    location / {
        proxy_pass http://127.0.0.1:8080;
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
# -> deployed on the staging server within a minute
#    (log: /opt/ellsms-stage/storage/logs/stage-autodeploy.log)

# when it is checked: release to production
git checkout main && git merge staging && git push origin main
# -> on the production server: cd /opt/ellsms && ./deploy.sh   (unchanged)
```

Refresh staging's data from production: repeat step 1, then on the staging server

```bash
cd /opt/ellsms-stage && ops/staging/stage.sh db-restore /root/ellsms-prod.sql.gz
```

The staging stack is stopped during the restore so no worker sees unsanitized rows. If the staging
server can reach production's MySQL directly (user `ellsms_user` allowed from this host and port
3306 open), it can dump by itself instead: `ops/staging/stage.sh db-refresh` (asks for the
production DB password; `PROD_DB_HOST`/`PROD_DB_USER`/`PROD_DB_NAME` default to 10.10.101.231 /
ellsms_user / ellsms).

Lighter copy without the big message tables' rows: add
`--ignore-table-data=ellsms.outbound_message --ignore-table-data=ellsms.inbound_message` (and so
on) to the `mysqldump` command in step 1.

Test sends show up in staging's own reports as delivered by the mock gateway; its request log is
`docker logs ellsms-stage-mock-sms-gateway`. `MOCK_SMS_MODE` in `.env` switches the mock to failure
modes for testing error handling.

Staging MySQL shell: `docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" ellsms_stage'`.

## Troubleshooting

- **`setup` stops at "no production data to load"**: pass `DUMP=/path/to/file.sql.gz`.
- **Import fails on an unknown collation**: production is newer than the staging MySQL (8.0); set
  the `mysql` image in `docker-compose.stage.yml` to production's version.
- **Auto-deploy says the branch was rewritten**: `staging` was force-pushed. On the staging server:
  `git reset --hard origin/staging && ./deploy.sh`.
- **Auto-deploy does nothing**: run `ops/staging/stage.sh autodeploy` by hand; `git fetch` must
  work without a password prompt.
- **New `.env` (`FORCE=1 ... env`)**: it generates new database passwords, so the existing MySQL
  volume no longer matches: `docker compose down -v`, then run `setup` again with `DUMP=...`.
