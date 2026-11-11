#!/usr/bin/env bash
# ELLSMS — pull the latest code from git and deploy it with Docker Compose.
#
#   ./deploy.sh          deploy what changed since the last deploy, doing only what that needs
#   ./deploy.sh --full   rebuild every image, apply the schema, recreate every container
#   ./deploy.sh --plan   pull nothing and change nothing: print what a deploy would do
#
# Why a deploy does not have to restart everything: every PHP container bind-mounts this checkout
# (docker-compose.yml, ./:/var/www/html), so the code a container RUNS is the code on disk, not the
# copy baked into its image.
#   - The web container (app) serves the new files as soon as `git pull` has written them (PHP
#     re-checks a file's timestamp every couple of seconds). It is never restarted for a code change.
#   - The workers are long-running PHP processes that loaded the old code into memory: they are
#     restarted (not rebuilt, not recreated) when app/ or cron/ changed.
#   - An image is rebuilt only when what is baked into it changed: docker/ (Dockerfile, entrypoint,
#     Apache config) for the PHP image, smpp-bridge/, tg-worker/ or mock/ for theirs.
#   - The schema is applied only when db/ changed.
#   - Documentation, tests, guides, PDFs, SDK sources and public/ assets need nothing after the pull.
#
# "What changed" is measured from the commit of the last successful deploy (.git/ellsms-deployed-rev),
# not from this pull alone, so a `git pull` done by hand in between is still deployed. When that
# cannot be trusted — first deploy, no record, history rewritten, the stack not running — the
# deploy is a full one. --full is always available and does exactly what this script always did.
set -euo pipefail
cd "$(dirname "$0")"

MODE=auto
for arg in "$@"; do
  case "$arg" in
    --full) MODE=full ;;
    --plan|--dry-run) MODE=plan ;;
    -h|--help) sed -n '2,6p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $arg (see ./deploy.sh --help)" >&2; exit 2 ;;
  esac
done

DEPLOYED_REV_FILE="$(git rev-parse --git-dir)/ellsms-deployed-rev"
# Services that do not run the PHP code of this checkout: never restarted for an app/ or cron/ change.
NON_PHP_SERVICES=" app mysql mongo mock-sms-gateway smpp-bridge tg-worker prometheus node-exporter cadvisor grafana "

if [ "$MODE" != "plan" ]; then
  echo "==> Pulling latest code..."
  SELF_BEFORE="$(git rev-parse HEAD:deploy.sh 2>/dev/null || true)"
  git pull --ff-only
  # The pull may have brought a newer deploy.sh: run that one, not the copy bash already has open.
  if [ -z "${ELLSMS_DEPLOY_REEXEC:-}" ] && [ "$SELF_BEFORE" != "$(git rev-parse HEAD:deploy.sh 2>/dev/null || true)" ]; then
    echo "==> deploy.sh itself changed; continuing with the new one."
    ELLSMS_DEPLOY_REEXEC=1 exec bash "./$(basename "$0")" "$@"
  fi
fi

if [ "$MODE" = "plan" ]; then
  # Read-only: use the settings that are there, generate nothing.
  if [ -f .env ]; then set -a; . ./.env; set +a; fi
else
# docker-compose.override.yml requires an authenticated private MongoDB for Audit. Generate the
# password once on first deploy and persist it in .env without ever printing it to stdout/history.
touch .env
set -a; . ./.env; set +a
if [ -z "${AUDIT_MONGO_PASSWORD:-}" ]; then
  if command -v openssl >/dev/null 2>&1; then
    AUDIT_MONGO_PASSWORD="$(openssl rand -hex 32)"
  else
    AUDIT_MONGO_PASSWORD="$(head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48)"
  fi
  printf '\nAUDIT_MONGO_PASSWORD=%s\n' "$AUDIT_MONGO_PASSWORD" >> .env
  export AUDIT_MONGO_PASSWORD
  echo "==> Generated private MongoDB audit password in .env"
fi

# Re-read env after first-run generation so Compose and schema commands share the same values.
set -a; . ./.env; set +a
fi


# ---------- what does this deploy need? ----------

# Prints the plan for the files listed on stdin, one per line: the words build_app build_smpp
# build_tg build_mock schema migrations workers, each only when needed.
deploy_plan_for_files() {
  local plan="" f
  while IFS= read -r f; do
    [ -n "$f" ] || continue
    case "$f" in
      docker/prometheus/*|docker/grafana/*) ;;                       # mounted into their containers, not baked in
      docker/*|.dockerignore)        plan="$plan build_app" ;;
      smpp-bridge/*)                 plan="$plan build_smpp" ;;
      tg-worker/*)                   plan="$plan build_tg" ;;
      mock/*)                        plan="$plan build_mock" ;;
      db/ellsms_extra.sql)           plan="$plan schema" ;;
      db/migrations/*)               plan="$plan migrations" ;;
      app/*|cron/*|composer.json)    plan="$plan workers" ;;
    esac
  done
  for word in build_app build_smpp build_tg build_mock schema migrations workers; do
    case " $plan " in *" $word "*) printf '%s ' "$word" ;; esac
  done
}

stack_running() {
  docker compose ps --status running --services 2>/dev/null | grep -qx app
}

FULL_REASON=""
BASE_REV=""
if [ "$MODE" = "full" ]; then
  FULL_REASON="--full was given"
elif [ ! -s "$DEPLOYED_REV_FILE" ]; then
  FULL_REASON="there is no record of a previous deploy"
else
  BASE_REV="$(cat "$DEPLOYED_REV_FILE")"
  if ! git cat-file -e "${BASE_REV}^{commit}" 2>/dev/null || ! git merge-base --is-ancestor "$BASE_REV" HEAD; then
    FULL_REASON="the last deployed commit is not an ancestor of this one"
  elif ! stack_running; then
    FULL_REASON="the app container is not running"
  fi
fi

PLAN=""
if [ -z "$FULL_REASON" ]; then
  PLAN="$(git diff --name-only "$BASE_REV" HEAD | deploy_plan_for_files)"
  CHANGED_COUNT="$(git diff --name-only "$BASE_REV" HEAD | wc -l | tr -d ' ')"
  echo "==> ${CHANGED_COUNT} file(s) changed since the last deploy (${BASE_REV:0:8} -> $(git rev-parse --short=8 HEAD)). Needed: ${PLAN:-nothing but the new files}"
else
  echo "==> Full deploy: ${FULL_REASON}."
fi
needs() { [ -n "$FULL_REASON" ] || case " $PLAN " in *" $1 "*) true ;; *) false ;; esac; }

if [ "$MODE" = "plan" ]; then
  echo "==> --plan: nothing was pulled or changed."
  exit 0
fi

# ---------- build ----------
# Build first, migrate with the NEW image, and only then restart the workers: started before the
# migrations, a worker would run the new code against the old schema until they finished (failing
# its passes, or writing half-shaped rows, in that window). Migrations are additive, so the code
# that is still running keeps working against the new schema for those few seconds.
if [ -n "$FULL_REASON" ]; then
  echo "==> Building the images..."
  docker compose build
else
  BUILD_SERVICES=""
  needs build_app  && BUILD_SERVICES="$BUILD_SERVICES app"
  needs build_smpp && BUILD_SERVICES="$BUILD_SERVICES smpp-bridge"
  needs build_tg   && BUILD_SERVICES="$BUILD_SERVICES tg-worker"
  needs build_mock && BUILD_SERVICES="$BUILD_SERVICES mock-sms-gateway"
  if [ -n "$BUILD_SERVICES" ]; then
    echo "==> Building:$BUILD_SERVICES"
    # shellcheck disable=SC2086
    docker compose build $BUILD_SERVICES
  fi
fi

# ---------- schema ----------
# Run the mysql CLI from INSIDE the app container (which already has default-mysql-client -- see
# docker/Dockerfile) and connects to BACKEND_DB_HOST over the network, rather than `docker exec`ing
# directly into a container assumed to BE the database. `docker exec -i "$BACKEND_DB_HOST"` only
# ever worked when the backend DB happened to be a local container sharing this host's Docker
# daemon and named exactly by BACKEND_DB_HOST -- it fails outright ("No such container: <ip>") for
# the equally common case of a separate/managed/remote database host, which is exactly what
# BACKEND_DB_HOST is FOR (a hostname or IP, not a container name). Also now honors
# BACKEND_DB_PORT, silently ignored before.
#
# --ssl-verify-server-cert=0 by default, same reasoning and same BACKEND_DB_SSL_VERIFY=1 opt-out as
# app/bootstrap.php's db(): the server may offer TLS with a self-signed/internal-CA certificate
# (common on an internal network) independent of whether it's mandatory, and the client otherwise
# aborts on an unverifiable chain rather than just connecting encrypted-but-unverified.
MYSQL_SSL_OPTS=()
if [ "${BACKEND_DB_SSL_VERIFY:-0}" != "1" ]; then
  MYSQL_SSL_OPTS=(--ssl-verify-server-cert=0)
fi

# A full deploy uses a throwaway container of the image just built. A quick one runs inside the
# app container that is already up: no container start, and the code it runs is this checkout.
in_app() {
  if [ -z "$FULL_REASON" ] && ! needs build_app; then
    docker compose exec -T app "$@"
  else
    docker compose run --rm --no-deps -T app "$@"
  fi
}

if needs schema; then
  echo "==> Applying ELLSMS supplementary schema (safe to re-run)..."
  in_app mysql "${MYSQL_SSL_OPTS[@]}" -h"${BACKEND_DB_HOST}" -P"${BACKEND_DB_PORT:-3306}" -u"${BACKEND_DB_USER}" -p"${BACKEND_DB_PASS}" "${BACKEND_DB_NAME}" \
    < db/ellsms_extra.sql
fi

# cron/db-migrate.php --apply globs db/migrations/*.sql by each file's ACTUAL current name and
# tracks what's applied in a ledger table -- it fully replaced the two hardcoded
# "docker exec ... < db/migrations/<specific file>.sql" steps that used to live here (see the
# script's own docblock: "Replaces the previous bash loop"). Those two lines were never removed
# after that migration, and one of them (2026_08_26_registration_requests.sql) had gone stale --
# the file was later renamed to 2026_08_25_registration_requests.sql to fix migration ordering,
# breaking `./deploy.sh` outright for anyone who hadn't already applied it by the old name.
# Removed rather than fixed the filename again: a hardcoded filename here will only go stale the
# same way again on the next rename; the glob below is what's supposed to be the source of truth.
if needs migrations; then
  echo "==> Applying versioned DB migrations..."
  in_app php cron/db-migrate.php --apply

  # One-off data steps that belong to a migration but must run in PHP, in small chunks. Each records
  # that it ran and does nothing on later deploys. A failure is reported and does not stop the
  # deploy (the step can be run again by hand, see its script).
  #   - PishgamRayan: rows the old status mapping marked failed go back to «ارسال‌شده»
  #     (db/migrations/2026_11_16_pishgamrayan_status_sent.sql, docs/sms-gateway-connectors.md).
  echo "==> One-off data steps..."
  in_app php cron/gateway-status-reopen.php --gateway=pishgamrayan --apply --once \
    || echo "!! gateway-status-reopen failed — run it again: docker compose exec app php cron/gateway-status-reopen.php --gateway=pishgamrayan --apply --once"
fi

# ---------- containers ----------
if [ -n "$FULL_REASON" ]; then
  echo "==> Restarting containers on the new code..."
  docker compose up -d
else
  # Recreates only a container whose image was just rebuilt or whose compose definition changed,
  # and starts one that is down. With nothing of that kind it changes nothing.
  docker compose up -d
  if needs workers && ! needs build_app; then
    # The PHP workers hold the old code in memory. `restart` stops each with its stop_grace_period
    # (SIGTERM, so a worker finishes the item it is on) and starts the same container again.
    WORKERS=""
    for service in $(docker compose ps --status running --services); do
      case "$NON_PHP_SERVICES" in *" $service "*) ;; *) WORKERS="$WORKERS $service" ;; esac
    done
    if [ -n "$WORKERS" ]; then
      echo "==> Restarting the workers on the new code:$WORKERS"
      # shellcheck disable=SC2086
      docker compose restart $WORKERS
    fi
  fi
fi

git rev-parse HEAD > "$DEPLOYED_REV_FILE"

echo "==> Done. Status:"
docker compose ps
