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

echo "==> Applying ELLSMS supplementary schema (safe to re-run)..."
docker exec -i "${BACKEND_DB_HOST}" \
  mysql -u"${BACKEND_DB_USER}" -p"${BACKEND_DB_PASS}" "${BACKEND_DB_NAME}" \
  < db/ellsms_extra.sql

echo "==> Applying report-summary cache schema (safe to re-run)..."
docker exec -i "${BACKEND_DB_HOST}" \
  mysql -u"${BACKEND_DB_USER}" -p"${BACKEND_DB_PASS}" "${BACKEND_DB_NAME}" \
  < db/migrations/2026_08_25_report_summary_cache.sql

echo "==> Done. Status:"
docker compose ps
