#!/usr/bin/env bash
# ELLSMS staging helper. Run it on the STAGING server, from the staging checkout (/opt/ellsms-stage,
# branch `staging`). Staging has its own MySQL container holding `ellsms_stage`. Walkthrough: docs/staging.md.
#
#   ops/staging/stage.sh setup               first time: env + network + build + MySQL + load DUMP + start + autodeploy
#   ops/staging/stage.sh env                 write .env with fresh staging-only secrets (FORCE=1 to overwrite)
#   ops/staging/stage.sh network             create the staging Docker network
#   ops/staging/stage.sh db-init             start the staging MySQL container and wait until it is ready
#   ops/staging/stage.sh db-backup           dump PRODUCTION's database over the network (needs PROD_DB_PASS; read-only)
#   ops/staging/stage.sh db-restore [FILE]   wipe ellsms_stage and load FILE (default: newest dump), migrate, sanitize
#   ops/staging/stage.sh db-refresh          db-backup + db-restore
#   ops/staging/stage.sh sanitize            re-apply ops/staging/sanitize.sql + the mock gateway
#   ops/staging/stage.sh files-sync          copy uploads (KYC, slides, landing videos) from production over ssh (PROD_SSH)
#   ops/staging/stage.sh autodeploy          deploy if origin/<branch> has new commits (what cron runs every minute)
#   ops/staging/stage.sh autodeploy-install  add the every-minute cron entry for autodeploy
#
# Settings (environment variables): STAGE_DOMAIN, STAGE_BRANCH (default staging), DUMP (setup: a dump
# file to load), PROD_DB_HOST / PROD_DB_PORT / PROD_DB_NAME / PROD_DB_USER / PROD_DB_PASS (db-backup;
# defaults 10.10.101.231 / 3306 / ellsms / ellsms_user), PROD_SSH (files-sync, e.g. root@prod-server),
# PROD_DIR (production's path, default /opt/ellsms), SKIP_DATA_TABLES (db-backup: tables dumped
# structure-only), YES=1 (skip the restore prompt).
set -euo pipefail

STAGE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$STAGE_DIR"
PROD_DIR="${PROD_DIR:-/opt/ellsms}"
STAGE_BRANCH="${STAGE_BRANCH:-staging}"
DUMP_DIR="$STAGE_DIR/storage/backups/stage-db"
STAGE_ENV="$STAGE_DIR/.env"
PROD_ENV="$PROD_DIR/.env"

die() { echo "ERROR: $*" >&2; exit 1; }
log() { echo "==> $*"; }

# Last value of KEY in an env file, surrounding quotes stripped. Never sources the file, so reading
# production's .env cannot leak its values into this shell's environment.
envval() {
  local v
  v="$(grep -E "^$2=" "$1" 2>/dev/null | tail -n1 | cut -d= -f2-)" || true
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
  printf '%s' "$v"
}
rand_hex() { od -An -vtx1 -N"$1" /dev/urandom | tr -d ' \n'; }
rand_uuid() { local h; h="$(rand_hex 16)"; printf '%s-%s-%s-%s-%s' "${h:0:8}" "${h:8:4}" "${h:12:4}" "${h:16:4}" "${h:20:12}"; }

# Every destructive step re-checks that it is pointed at staging, not production.
guard_stage() {
  [ -f "$STAGE_ENV" ] || die "$STAGE_ENV missing -- run: ops/staging/stage.sh env"
  [ "$(envval "$STAGE_ENV" APP_ENV)" = "staging" ] || die "APP_ENV in $STAGE_ENV is not 'staging'"
  STAGE_DB_NAME="$(envval "$STAGE_ENV" BACKEND_DB_NAME)"
  STAGE_DB_USER="$(envval "$STAGE_ENV" BACKEND_DB_USER)"
  STAGE_DB_PASS="$(envval "$STAGE_ENV" BACKEND_DB_PASS)"
  STAGE_DB_HOST="$(envval "$STAGE_ENV" BACKEND_DB_HOST)"
  STAGE_DB_PORT="$(envval "$STAGE_ENV" BACKEND_DB_PORT)"; STAGE_DB_PORT="${STAGE_DB_PORT:-3306}"
  case "$STAGE_DB_NAME" in *stage*) ;; *) die "staging BACKEND_DB_NAME '$STAGE_DB_NAME' does not contain 'stage' -- refusing";; esac
  [ "$STAGE_DB_HOST" = "mysql" ] || die "staging BACKEND_DB_HOST must be the staging MySQL container 'mysql', not '$STAGE_DB_HOST'"
  # A production checkout on this machine: never act on it.
  if [ -f "$PROD_ENV" ] && [ "$(realpath "$STAGE_DIR")" = "$(realpath "$PROD_DIR")" ]; then die "this checkout IS production ($PROD_DIR)"; fi
}

# Same TLS default as deploy.sh / app/bootstrap.php.
ssl_opts() {
  if [ "$(envval "$STAGE_ENV" BACKEND_DB_SSL_VERIFY)" != "1" ]; then echo "--ssl-verify-server-cert=0"; fi
}

# mysql/mysqldump run inside the staging app image (it ships default-mysql-client). The password
# travels in MYSQL_PWD, never on a command line where `ps` would show it.
db_client() {
  local bin="$1" host="$2" port="$3" user="$4" pass="$5"; shift 5
  # shellcheck disable=SC2046
  MYSQL_PWD="$pass" docker compose run --rm --no-deps -T -e MYSQL_PWD app \
    "$bin" $(ssl_opts) --default-character-set=utf8mb4 -h"$host" -P"$port" -u"$user" "$@"
}
stage_mysql() { db_client mysql "$STAGE_DB_HOST" "$STAGE_DB_PORT" "$STAGE_DB_USER" "$STAGE_DB_PASS" "$@"; }
stage_root_mysql() { db_client mysql "$STAGE_DB_HOST" "$STAGE_DB_PORT" root "$(envval "$STAGE_ENV" STAGE_DB_ROOT_PASSWORD)" "$@"; }

ensure_image() {
  local img; img="$(envval "$STAGE_ENV" ELLSMS_APP_IMAGE)"
  docker image inspect "${img:-ellsms-app:latest}" >/dev/null 2>&1 || { log "Building the staging image..."; docker compose build app; }
}

stack_running() { [ -n "$(docker compose ps -q app 2>/dev/null)" ]; }

cmd_env() {
  if [ -f "$STAGE_ENV" ] && [ "${FORCE:-0}" != "1" ]; then
    die "$STAGE_ENV already exists. FORCE=1 regenerates every secret (then: docker compose down -v && ops/staging/stage.sh setup)."
  fi
  local domain="${STAGE_DOMAIN:-stage.ellsms.ravixops.com}"
  # No credential, key, token or external endpoint of production is used: all are freshly generated
  # or left empty, so nothing staging does can reach production's backend API, Telegram chat,
  # payment account or SMS providers. Only operational tuning matches production.
  (
  umask 077
  {
    echo "# ELLSMS STAGING .env -- generated by ops/staging/stage.sh env on $(date -u +%FT%TZ)."
    echo "# Staging only. Never copy any of these values to production or the other way round."
    echo
    echo "COMPOSE_FILE=docker-compose.yml:docker-compose.override.yml:docker-compose.stage.yml"
    echo "COMPOSE_PROJECT_NAME=ellsms-stage"
    echo "ELLSMS_CONTAINER_PREFIX=ellsms-stage"
    echo "ELLSMS_APP_IMAGE=ellsms-stage-app:latest"
    echo "ELLSMS_SMPP_IMAGE=ellsms-stage-smpp-bridge:latest"
    echo "BACKEND_NETWORK=ellsms_stage_net"
    echo "APP_PORT=${STAGE_APP_PORT:-8080}"
    echo
    echo "APP_ENV=staging"
    echo "APP_DEBUG=1"
    echo "APP_URL=https://$domain"
    echo
    echo "# --- staging database: the 'mysql' container of docker-compose.stage.yml ---"
    echo "BACKEND_DB_HOST=mysql"
    echo "BACKEND_DB_PORT=3306"
    echo "BACKEND_DB_NAME=ellsms_stage"
    echo "BACKEND_DB_USER=ellsms_stage_user"
    echo "BACKEND_DB_PASS=$(rand_hex 24)"
    echo "STAGE_DB_ROOT_PASSWORD=$(rand_hex 24)"
    echo
    echo "# --- sending: gateway transport through the MOCK gateway only, no legacy backend API ---"
    echo "SMS_GATEWAY_TRANSPORT=1"
    echo "SMS_LEGACY_BACKEND_ENABLED=0"
    echo "ELLSMS_MOCK_GATEWAY_ENABLED=1"
    echo "BULK_WORKER_REPLICAS=0"
    echo "API_ENABLED=1"
    echo "API_BASE_URL="
    echo "BACKEND_SERVICE_ID="
    echo "BACKEND_SERVICE_SECRET="
    echo
    echo "# --- fresh keys and tokens ---"
    echo "SMS_GATEWAY_MASTER_KEY=$(rand_hex 32)"
    echo "MFA_MASTER_KEY=$(rand_hex 32)"
    echo "AUDIT_MONGO_PASSWORD=$(rand_hex 32)"
    echo "SMPP_BRIDGE_TOKEN=$(rand_hex 24)"
    echo "SMPP_BRIDGE_URL=http://smpp-bridge:8090"
    echo "METRICS_TOKEN=$(rand_hex 24)"
    echo "GRAFANA_ADMIN_USER=admin"
    echo "GRAFANA_ADMIN_PASSWORD=$(rand_hex 16)"
    echo
    echo "# --- payments: Zarinpal sandbox + the fake gateway, never the real merchant ---"
    echo "ZARINPAL_MERCHANT_ID=$(rand_uuid)"
    echo "ZARINPAL_SANDBOX=1"
    echo "ZARINPAL_CALLBACK_URL=https://$domain/zarinpal-callback.php"
    echo "ELLSMS_FAKE_PAYMENT_GATEWAY_ENABLED=1"
    echo
    echo "# --- alerts off ---"
    echo "TELEGRAM_BOT_TOKEN="
    echo "TELEGRAM_CHAT_ID="
    echo "ALERT_EMAIL_RECIPIENT="
    echo
    echo "# --- tuning, same as production ---"
    echo "WORKER_BULK_BATCH_SIZE=1000"
    echo "SMS_PROVIDER_BATCH_SIZE=200"
    echo "SMS_GATEWAY_STATUS_REQUEST_MAX=50"
    echo "SMS_GATEWAY_STATUS_BATCH=2000"
    echo "SMS_GATEWAY_STATUS_WORKER_INTERVAL_SECONDS=5"
  } > "$STAGE_ENV.tmp"
  )
  mv "$STAGE_ENV.tmp" "$STAGE_ENV"
  log "Wrote $STAGE_ENV (mode 600). Staging URL: https://$domain -> port $(envval "$STAGE_ENV" APP_PORT) on this server"
}

cmd_network() {
  local net; net="$(envval "$STAGE_ENV" BACKEND_NETWORK)"
  [ -n "$net" ] || die "BACKEND_NETWORK missing in $STAGE_ENV"
  docker network inspect "$net" >/dev/null 2>&1 || { log "Creating Docker network $net"; docker network create "$net" >/dev/null; }
}

cmd_db_init() {
  guard_stage; cmd_network
  log "Starting the staging MySQL container (creates $STAGE_DB_NAME and $STAGE_DB_USER on first start)"
  docker compose up -d mysql
  local i
  for i in $(seq 1 90); do
    if echo "SELECT 1" | stage_mysql -N "$STAGE_DB_NAME" >/dev/null 2>&1; then log "MySQL is ready"; return 0; fi
    sleep 2
  done
  die "staging MySQL did not become ready -- see: docker compose logs mysql"
}

cmd_db_backup() {
  guard_stage; ensure_image; cmd_network
  local name="${PROD_DB_NAME:-ellsms}" host="${PROD_DB_HOST:-10.10.101.231}" port="${PROD_DB_PORT:-3306}"
  local user="${PROD_DB_USER:-ellsms_user}" pass="${PROD_DB_PASS:-}"
  if [ -z "$pass" ]; then read -rsp "Password of PRODUCTION MySQL user '$user' on $host: " pass; echo; fi
  mkdir -p "$DUMP_DIR"; chmod 700 "$DUMP_DIR"
  local file; file="$DUMP_DIR/${name}-$(date +%Y%m%d-%H%M%S).sql.gz"
  # Same mysqldump flags as cron/backup.php. SKIP_DATA_TABLES keeps those tables' structure only.
  local opts=(--single-transaction --quick --routines --events --triggers --no-tablespaces --hex-blob)
  local t
  for t in ${SKIP_DATA_TABLES:-}; do opts+=("--ignore-table-data=${name}.${t}"); done
  log "Dumping production database $name@$host (consistent snapshot, read-only) -> $file"
  db_client mysqldump "$host" "$port" "$user" "$pass" "${opts[@]}" "$name" | gzip -1 > "$file.part"
  gzip -t "$file.part"
  mv "$file.part" "$file"; chmod 600 "$file"
  log "Backup done: $file ($(du -h "$file" | cut -f1))"
}

cmd_sanitize() {
  guard_stage; ensure_image
  log "Sanitizing $STAGE_DB_NAME (real gateways archived, queues cancelled, webhooks/remote DBs/2FA off)"
  stage_mysql "$STAGE_DB_NAME" < ops/staging/sanitize.sql
  log "Seeding the mock SMS gateway"
  docker compose run --rm --no-deps -T -e MOCK_SMS_GATEWAY_URL=http://mock-sms-gateway:8080 worker php cron/mock-gateway-seed.php
  # Second pass now that the mock exists: it becomes the default and takes every route/pinned number.
  stage_mysql "$STAGE_DB_NAME" < ops/staging/sanitize.sql
}

cmd_db_restore() {
  guard_stage; ensure_image
  local file="${1:-}"
  if [ -z "$file" ]; then file="$(ls -1t "$DUMP_DIR"/*.sql.gz 2>/dev/null | head -n1 || true)"; fi
  [ -n "$file" ] && [ -f "$file" ] || die "no dump file found in $DUMP_DIR (copy one there from production, see docs/staging.md, or pass a path)"
  gzip -t "$file" || die "$file is not a complete .gz file"
  if [ "${YES:-0}" != "1" ]; then
    local answer
    read -rp "This ERASES database '$STAGE_DB_NAME' on $STAGE_DB_HOST and loads $(basename "$file"). Type the database name to continue: " answer
    [ "$answer" = "$STAGE_DB_NAME" ] || die "aborted"
  fi
  # Workers must not see production's queued work before it has been sanitized.
  local was_running=0
  if stack_running; then was_running=1; log "Stopping the staging stack during the restore"; docker compose stop; fi

  cmd_db_init
  # New tables from migrations take the database default, so match the collation production's
  # tables mostly use (read from the dump itself).
  local coll
  coll="$(gunzip -c "$file" | grep -oE 'COLLATE=[A-Za-z0-9_]+' | cut -d= -f2 | sort | uniq -c | sort -rn | awk 'NR==1{print $2}')" || true
  coll="${coll:-utf8mb4_unicode_ci}"
  log "Recreating $STAGE_DB_NAME (collation $coll)"
  echo "DROP DATABASE IF EXISTS \`${STAGE_DB_NAME}\`; CREATE DATABASE \`${STAGE_DB_NAME}\` CHARACTER SET ${coll%%_*} COLLATE ${coll};
GRANT ALL PRIVILEGES ON \`${STAGE_DB_NAME}\`.* TO '${STAGE_DB_USER}'@'%';" | stage_root_mysql

  log "Importing $(basename "$file") (this can take a while for a large database)"
  # DEFINER clauses name production's MySQL user, which the staging user may not impersonate.
  gunzip -c "$file" | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' | stage_root_mysql "$STAGE_DB_NAME"

  log "Applying schema + migrations from this checkout"
  stage_mysql "$STAGE_DB_NAME" < db/ellsms_extra.sql
  docker compose run --rm --no-deps -T app php cron/db-migrate.php --apply

  cmd_sanitize
  if [ "$was_running" = "1" ]; then log "Starting the staging stack again"; docker compose up -d; fi
  log "Restore done. Log in with any production username/password; 2FA is reset on staging."
}

cmd_files_sync() {
  guard_stage
  [ -n "${PROD_SSH:-}" ] || die "set PROD_SSH=user@production-server (ssh access to production is needed to copy uploads)"
  local dirs="storage/kyc public/assets/img/slides public/assets/video/landing public/assets/img/landing-video-posters"
  log "Copying uploads from $PROD_SSH:$PROD_DIR ($dirs)"
  # shellcheck disable=SC2029
  ssh "$PROD_SSH" "cd '$PROD_DIR' && ls -d $dirs 2>/dev/null | xargs -r tar cf -" | tar xf - -C "$STAGE_DIR"
}

cmd_autodeploy() {
  guard_stage
  exec 9>"$STAGE_DIR/storage/.stage-autodeploy.lock"
  flock -n 9 || { echo "another deploy is running"; exit 0; }
  git fetch -q origin "$STAGE_BRANCH"
  local local_rev remote_rev
  local_rev="$(git rev-parse HEAD)"; remote_rev="$(git rev-parse "origin/$STAGE_BRANCH")"
  [ "$local_rev" != "$remote_rev" ] || exit 0
  if ! git merge-base --is-ancestor HEAD "origin/$STAGE_BRANCH"; then
    die "$(date -u +%FT%TZ) origin/$STAGE_BRANCH was rewritten (not a fast-forward of $local_rev). Fix by hand: git reset --hard origin/$STAGE_BRANCH && ./deploy.sh"
  fi
  log "$(date -u +%FT%TZ) deploying ${local_rev:0:8} -> ${remote_rev:0:8}"
  cmd_network
  docker compose up -d mysql
  ./deploy.sh
}

cmd_autodeploy_install() {
  guard_stage
  mkdir -p "$STAGE_DIR/storage/logs"
  local line="* * * * * cd $STAGE_DIR && ops/staging/stage.sh autodeploy >> storage/logs/stage-autodeploy.log 2>&1"
  if crontab -l 2>/dev/null | grep -Fq "ops/staging/stage.sh autodeploy"; then
    log "cron entry already present"
  else
    (crontab -l 2>/dev/null || true; echo "$line") | crontab -
    log "Installed cron: $line"
  fi
}

cmd_setup() {
  [ -f "$STAGE_ENV" ] || cmd_env
  guard_stage
  cmd_network
  log "Building images"; docker compose build
  cmd_db_init
  if [ -n "${DUMP:-}" ]; then
    YES=1 cmd_db_restore "$DUMP"
  elif [ -n "${PROD_DB_PASS:-}" ]; then
    cmd_db_backup; YES=1 cmd_db_restore
  elif ls "$DUMP_DIR"/*.sql.gz >/dev/null 2>&1; then
    YES=1 cmd_db_restore
  else
    die "no production data to load: pass DUMP=/path/to/ellsms.sql.gz (see docs/staging.md), or PROD_DB_PASS=... to dump over the network"
  fi
  if [ -n "${PROD_SSH:-}" ]; then cmd_files_sync; else log "Skipping uploads copy (set PROD_SSH=user@prod-server and run files-sync later)"; fi
  ./deploy.sh
  cmd_autodeploy_install
  log "Staging is up on port $(envval "$STAGE_ENV" APP_PORT). Every push to '$STAGE_BRANCH' deploys within a minute."
}

case "${1:-}" in
  setup) cmd_setup ;;
  env) cmd_env ;;
  network) guard_stage; cmd_network ;;
  db-init) cmd_db_init ;;
  db-backup) cmd_db_backup ;;
  db-restore) shift; cmd_db_restore "${1:-}" ;;
  db-refresh) cmd_db_backup; cmd_db_restore ;;
  sanitize) cmd_sanitize ;;
  files-sync) cmd_files_sync ;;
  autodeploy) cmd_autodeploy ;;
  autodeploy-install) cmd_autodeploy_install ;;
  *) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
