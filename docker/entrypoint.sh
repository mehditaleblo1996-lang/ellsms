#!/bin/sh
# Ensures storage/kyc is writable by Apache (www-data) before the app
# starts. This runs on every container start, not just build, because
# storage/kyc lives inside the host bind-mount (docker-compose.yml
# mounts ./:/var/www/html) — whatever ownership the host directory has
# wins over anything set at image build time.
set -e
mkdir -p /var/www/html/storage/kyc
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
chmod -R u+rwX,g+rwX /var/www/html/storage 2>/dev/null || true

exec docker-php-entrypoint "$@"
