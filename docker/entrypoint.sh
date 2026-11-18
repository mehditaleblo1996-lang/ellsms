#!/bin/sh
# Ensures storage/kyc is writable by Apache (www-data) before the app
# starts. This runs on every container start, not just build, because
# storage/kyc lives inside the host bind-mount (docker-compose.yml
# mounts ./:/var/www/html) — whatever ownership the host directory has
# wins over anything set at image build time.
set -e
mkdir -p /var/www/html/storage/kyc /var/www/html/storage/logs
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
chmod -R u+rwX,g+rwX /var/www/html/storage 2>/dev/null || true

# Same reasoning for public upload directories that don't ship pre-created in the
# repo (public/assets/img/slides does, from earlier uploads, and already works;
# these are new as of the landing-page video feature and would otherwise make
# PHP's mkdir()+move_uploaded_file() fail on a fresh host checkout).
mkdir -p /var/www/html/public/assets/video/landing /var/www/html/public/assets/img/landing-video-posters
chown -R www-data:www-data /var/www/html/public/assets/video /var/www/html/public/assets/img/landing-video-posters 2>/dev/null || true
chmod -R u+rwX,g+rwX /var/www/html/public/assets/video /var/www/html/public/assets/img/landing-video-posters 2>/dev/null || true

# PHP sessions (named volume ellsms_sessions, see docker/Dockerfile): an existing volume keeps the
# ownership it was created with, so make sure Apache can still write it.
mkdir -p /var/lib/php/sessions
chown www-data:www-data /var/lib/php/sessions 2>/dev/null || true
chmod 0700 /var/lib/php/sessions 2>/dev/null || true

exec docker-php-entrypoint "$@"
