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

echo "==> Done. Status:"
docker compose ps
