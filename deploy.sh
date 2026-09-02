#!/usr/bin/env bash
# ELLSMS — pull the latest code from git and redeploy with Docker Compose.
# Usage on the server:  ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Pulling latest code..."
git pull --ff-only

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

echo "==> Rebuilding and restarting containers..."
docker compose build
docker compose up -d

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

echo "==> Applying ELLSMS supplementary schema (safe to re-run)..."
docker compose exec -T app \
  mysql "${MYSQL_SSL_OPTS[@]}" -h"${BACKEND_DB_HOST}" -P"${BACKEND_DB_PORT:-3306}" -u"${BACKEND_DB_USER}" -p"${BACKEND_DB_PASS}" "${BACKEND_DB_NAME}" \
  < db/ellsms_extra.sql

# cron/db-migrate.php --apply globs db/migrations/*.sql by each file's ACTUAL current name and
# tracks what's applied in a ledger table -- it fully replaced the two hardcoded
# "docker exec ... < db/migrations/<specific file>.sql" steps that used to live here (see the
# script's own docblock: "Replaces the previous bash loop"). Those two lines were never removed
# after that migration, and one of them (2026_08_26_registration_requests.sql) had gone stale --
# the file was later renamed to 2026_08_25_registration_requests.sql to fix migration ordering,
# breaking `./deploy.sh` outright for anyone who hadn't already applied it by the old name.
# Removed rather than fixed the filename again: a hardcoded filename here will only go stale the
# same way again on the next rename; the glob below is what's supposed to be the source of truth.
echo "==> Applying versioned DB migrations..."
docker compose exec -T app php cron/db-migrate.php --apply

echo "==> Done. Status:"
docker compose ps
