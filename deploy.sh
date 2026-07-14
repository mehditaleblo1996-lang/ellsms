#!/usr/bin/env bash
# ELLSMS — pull the latest code from git and redeploy with Docker Compose.
# Usage on the server:  ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Pulling latest code..."
git pull --ff-only

echo "==> Rebuilding and restarting containers..."
docker compose build
docker compose up -d

echo "==> Applying ELLSMS supplementary schema (safe to re-run)..."
set -a; [ -f .env ] && . ./.env; set +a
docker exec -i "${BACKEND_DB_HOST}" \
  mysql -u"${BACKEND_DB_USER}" -p"${BACKEND_DB_PASS}" "${BACKEND_DB_NAME}" \
  < db/ellsms_extra.sql

echo "==> Done. Status:"
docker compose ps
