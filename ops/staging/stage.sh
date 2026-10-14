#!/usr/bin/env bash
# ELLSMS staging helper. Run it from the STAGING checkout (e.g. /opt/ellsms-stage on branch
# `staging`), never from production's. Full walkthrough: docs/staging.md.
#
#   ops/staging/stage.sh setup               first-time: env + network + build + db-init + copy + start + autodeploy
#   ops/staging/stage.sh env                 write .env with fresh staging-only secrets (FORCE=1 to overwrite)
#   ops/staging/stage.sh network             create the staging Docker network
#   ops/staging/stage.sh db-init             create the staging database + its own MySQL user (asks for the MySQL admin password)
#   ops/staging/stage.sh db-backup           dump PRODUCTION's database to storage/backups/stage-db/*.sql.gz (read-only on production)
#   ops/staging/stage.sh db-restore [FILE]   wipe the staging database and load FILE (default: newest dump), then sanitize
#   ops/staging/stage.sh db-refresh          db-backup + db-restore in one go
#   ops/staging/stage.sh sanitize            re-apply ops/staging/sanitize.sql + mock gateway to the staging database
#   ops/staging/stage.sh files-sync          copy uploaded files (KYC, slides, landing videos) from production
#   ops/staging/stage.sh autodeploy          deploy if origin/<branch> has new commits (what cron runs every minute)
#   ops/staging/stage.sh autodeploy-install  add the every-minute cron entry for autodeploy
#
# Settings (environment variables): PROD_DIR (default /opt/ellsms), STAGE_DOMAIN, STAGE_BRANCH
# (default staging), DB_ADMIN_USER / DB_ADMIN_PASS (db-init only), SKIP_DATA_TABLES (db-backup:
# space-separated tables dumped structure-only, for a lighter copy), YES=1 (skip the restore prompt).
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
  [ "$(realpath "$STAGE_DIR")" != "$(realpath -m "$PROD_DIR")" ] || die "this checkout IS production ($PROD_DIR)"
  [ "$(envval "$STAGE_ENV" APP_ENV)" = "staging" ] || die "APP_ENV in $STAGE_ENV is not 'staging'"
  STAGE_DB_NAME="$(envval "$STAGE_ENV" BACKEND_DB_NAME)"
  STAGE_DB_USER="$(envval "$STAGE_ENV" BACKEND_DB_USER)"
  STAGE_DB_PASS="$(envval "$STAGE_ENV" BACKEND_DB_PASS)"
  STAGE_DB_HOST="$(envval "$STAGE_ENV" BACKEND_DB_HOST)"
  STAGE_DB_PORT="$(envval "$STAGE_ENV" BACKEND_DB_PORT)"; STAGE_DB_PORT="${STAGE_DB_PORT:-3306}"
  case "$STAGE_DB_NAME" in *stage*) ;; *) die "staging BACKEND_DB_NAME '$STAGE_DB_NAME' does not contain 'stage' -- refusing";; esac
  if [ -f "$PROD_ENV" ] && [ "$STAGE_DB_NAME" = "$(envval "$PROD_ENV" BACKEND_DB_NAME)" ]; then
    die "staging and production use the same database name ($STAGE_DB_NAME)"
  fi
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

ensure_image() {
  local img; img="$(envval "$STAGE_ENV" ELLSMS_APP_IMAGE)"
  docker image inspect "${img:-ellsms-app:latest}" >/dev/null 2>&1 || { log "Building the staging image..."; docker compose build app; }
}

stack_running() { [ -n "$(docker compose ps -q 2>/dev/null)" ]; }

cmd_env() {
  [ -f "$PROD_ENV" ] || die "production .env not found at $PROD_ENV (set PROD_DIR=...)"
  [ "$(realpath "$STAGE_DIR")" != "$(realpath -m "$PROD_DIR")" ] || die "this checkout IS production ($PROD_DIR)"
  if [ -f "$STAGE_ENV" ] && [ "${FORCE:-0}" != "1" ]; then
    die "$STAGE_ENV already exists. FORCE=1 regenerates every secret (then re-run db-init and db-restore)."
  fi
  local domain="${STAGE_DOMAIN:-stage.ellsms.ravixops.com}"
  # Only operational tuning is copied from production. Every credential, key, token and external
  # endpoint is freshly generated or left empty, so nothing staging does can reach production's
  # backend API, Telegram chat, payment account or SMS providers.
  local copy_keys='BACKEND_DB_HOST|BACKEND_DB_PORT|BACKEND_DB_SSL_VERIFY|BACKEND_DB_SSL_CA|API_ENABLED|GRAFANA_ADMIN_USER|TRUSTED_PROXY_IPS|[A-Z_]*_BATCH_SIZE|[A-Z_]*_BATCH|[A-Z_]*_REQUEST_MAX|[A-Z_]*_INTERVAL_SECONDS|ALERT_REPEAT_SECONDS_[A-Z]+|IMPORT_[A-Z_]+|RATE_LIMIT_[A-Z_]+|SESSION_[A-Z_]+|BILLING_ENABLED|BILLING_CURRENCY|BILLING_TAX_PERCENT|DEFAULT_PLAN_CODE'
  (
  umask 077
  {
    echo "# ELLSMS STAGING .env -- generated by ops/staging/stage.sh env on $(date -u +%FT%TZ)."
    echo "# Staging only. Never copy any of these values to production or the other way round."
    echo
    echo "# --- isolation from the production stack on this host ---"
    echo "COMPOSE_PROJECT_NAME=ellsms-stage"
    echo "ELLSMS_CONTAINER_PREFIX=ellsms-stage"
    echo "ELLSMS_APP_IMAGE=ellsms-stage-app:latest"
    echo "ELLSMS_SMPP_IMAGE=ellsms-stage-smpp-bridge:latest"
    echo "BACKEND_NETWORK=ellsms_stage_net"
    echo "APP_PORT=${STAGE_APP_PORT:-8081}"
    echo "MOCK_SMS_PORT=18081"
    echo "PROMETHEUS_PORT=19090"
    echo "GRAFANA_PORT=13000"
    echo
    echo "APP_ENV=staging"
    echo "APP_DEBUG=1"
    echo "APP_URL=https://$domain"
    echo
    echo "# --- staging database (same MySQL server, its own database and user) ---"
    echo "BACKEND_DB_NAME=${STAGE_DB_NAME:-ellsms_stage}"
    echo "BACKEND_DB_USER=${STAGE_DB_USER:-ellsms_stage_user}"
    echo "BACKEND_DB_PASS=$(rand_hex 24)"
    echo
    echo "# --- sending: gateway transport through the MOCK gateway only, no legacy backend API ---"
    echo "SMS_GATEWAY_TRANSPORT=1"
    echo "SMS_LEGACY_BACKEND_ENABLED=0"
    echo "ELLSMS_MOCK_GATEWAY_ENABLED=1"
    echo "BULK_WORKER_REPLICAS=0"
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
    echo "# --- tuning copied from $PROD_ENV ---"
    grep -E "^(${copy_keys})=" "$PROD_ENV" | grep -vE '^(SMS_GATEWAY_TRANSPORT|BULK_WORKER_REPLICAS)=' || true
  } > "$STAGE_ENV.tmp"
  )
  mv "$STAGE_ENV.tmp" "$STAGE_ENV"
  log "Wrote $STAGE_ENV (mode 600). Staging URL: https://$domain  (container port $(envval "$STAGE_ENV" APP_PORT))"
}

cmd_network() {
  local net; net="$(envval "$STAGE_ENV" BACKEND_NETWORK)"
  [ -n "$net" ] || die "BACKEND_NETWORK missing in $STAGE_ENV"
  [ "$net" != "$(envval "$PROD_ENV" BACKEND_NETWORK)" ] || die "staging must not join production's network ($net): its service names (mongo, smpp-bridge) would collide"
  docker network inspect "$net" >/dev/null 2>&1 || { log "Creating Docker network $net"; docker network create "$net" >/dev/null; }
}

cmd_db_init() {
  guard_stage; ensure_image
  local admin_user="${DB_ADMIN_USER:-root}" admin_pass="${DB_ADMIN_PASS:-}"
  if [ -z "$admin_pass" ]; then read -rsp "MySQL password for admin user '$admin_user' on $STAGE_DB_HOST: " admin_pass; echo; fi
  local prod_db; prod_db="$(envval "$PROD_ENV" BACKEND_DB_NAME)"
  log "Creating database $STAGE_DB_NAME and user $STAGE_DB_USER on $STAGE_DB_HOST (charset copied from ${prod_db:-utf8mb4})"
  local cs coll
  read -r cs coll < <(echo "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${prod_db}'" \
    | db_client mysql "$STAGE_DB_HOST" "$STAGE_DB_PORT" "$admin_user" "$admin_pass" -N -B) || true
  cs="${cs:-utf8mb4}"; coll="${coll:-utf8mb4_unicode_ci}"
  db_client mysql "$STAGE_DB_HOST" "$STAGE_DB_PORT" "$admin_user" "$admin_pass" <<SQL
CREATE DATABASE IF NOT EXISTS \`${STAGE_DB_NAME}\` CHARACTER SET ${cs} COLLATE ${coll};
CREATE USER IF NOT EXISTS '${STAGE_DB_USER}'@'%' IDENTIFIED BY '${STAGE_DB_PASS}';
ALTER USER '${STAGE_DB_USER}'@'%' IDENTIFIED BY '${STAGE_DB_PASS}';
GRANT ALL PRIVILEGES ON \`${STAGE_DB_NAME}\`.* TO '${STAGE_DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
  echo "SELECT 'staging user can connect' AS ok" | stage_mysql -N "$STAGE_DB_NAME"
}

cmd_db_backup() {
  guard_stage; ensure_image
  [ -f "$PROD_ENV" ] || die "production .env not found at $PROD_ENV"
  local name host port user pass
  name="$(envval "$PROD_ENV" BACKEND_DB_NAME)"; host="$(envval "$PROD_ENV" BACKEND_DB_HOST)"
  port="$(envval "$PROD_ENV" BACKEND_DB_PORT)"; port="${port:-3306}"
  user="$(envval "$PROD_ENV" BACKEND_DB_USER)"; pass="$(envval "$PROD_ENV" BACKEND_DB_PASS)"
  [ -n "$name" ] && [ -n "$user" ] || die "BACKEND_DB_NAME/USER missing in $PROD_ENV"
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
  [ -n "$file" ] && [ -f "$file" ] || die "no dump file found (run db-backup first, or pass a path)"
  if [ "${YES:-0}" != "1" ]; then
    local answer
    read -rp "This ERASES database '$STAGE_DB_NAME' on $STAGE_DB_HOST and loads $(basename "$file"). Type the database name to continue: " answer
    [ "$answer" = "$STAGE_DB_NAME" ] || die "aborted"
  fi
  # Workers must not see production's queued work before it has been sanitized.
  local was_running=0
  if stack_running; then was_running=1; log "Stopping the staging stack during the restore"; docker compose stop; fi

  local cs coll
  read -r cs coll < <(echo "SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()" \
    | stage_mysql -N -B "$STAGE_DB_NAME") || true
  cs="${cs:-utf8mb4}"; coll="${coll:-utf8mb4_unicode_ci}"
  log "Recreating $STAGE_DB_NAME"
  echo "DROP DATABASE IF EXISTS \`${STAGE_DB_NAME}\`; CREATE DATABASE \`${STAGE_DB_NAME}\` CHARACTER SET ${cs} COLLATE ${coll};" | stage_mysql

  log "Importing $(basename "$file") (this can take a while for a large database)"
  # DEFINER clauses name production's MySQL user, which the staging user may not impersonate.
  gunzip -c "$file" | sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' | stage_mysql "$STAGE_DB_NAME"

  log "Applying schema + migrations from this checkout"
  stage_mysql "$STAGE_DB_NAME" < db/ellsms_extra.sql
  docker compose run --rm --no-deps -T app php cron/db-migrate.php --apply

  cmd_sanitize
  if [ "$was_running" = "1" ]; then log "Starting the staging stack again"; docker compose up -d; fi
  log "Restore done. Log in with any production username/password; 2FA is reset on staging."
}

cmd_files_sync() {
  guard_stage
  local d
  for d in storage/kyc public/assets/img/slides public/assets/video/landing public/assets/img/landing-video-posters; do
    [ -d "$PROD_DIR/$d" ] || continue
    mkdir -p "$STAGE_DIR/$d"
    log "Copying $d"
    cp -a "$PROD_DIR/$d/." "$STAGE_DIR/$d/"
  done
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
  cmd_db_backup
  YES=1 cmd_db_restore
  cmd_files_sync
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
  *) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
