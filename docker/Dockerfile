FROM php:8.2-apache

# pcntl is needed by cron/worker.php for graceful SIGTERM/SIGINT
# handling (finish the current pass, then stop, instead of Docker's
# default hard-kill after the stop grace period) — no extra apt
# dependency required, it's a core extension.
# default-mysql-client (Phase 11) provides the `mysql`/`mysqldump` CLI binaries
# cron/backup.php and cron/restore.php shell out to — PDO alone can run
# individual statements but has no equivalent of mysqldump's consistent
# whole-database logical dump, and reimplementing that in PHP would be
# exactly the kind of "invent custom tooling instead of using an
# established one" this phase's own instructions say not to do.
# gnupg provides authenticated (integrity-protected) symmetric backup encryption
# (cron/backup.php, BACKUP_ENCRYPTION_ENABLED=1) — chosen over `openssl enc`,
# which this image's OpenSSL 3.x build does not support in AEAD/authenticated
# modes through the `enc` subcommand (confirmed: "AEAD ciphers not supported").
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev default-mysql-client gnupg \
 && docker-php-ext-install pdo_mysql zip pcntl \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Sensible PHP defaults. post_max_size covers two KYC document uploads
# (id card + second document, 8MB cap each in app/bootstrap.php) in one
# submission, plus form overhead.
# display_errors=Off / log_errors=On are set here as the static baseline, not left to only the
# runtime ini_set() ErrorHandler::register() does (app/bootstrap.php) — that call happens as early
# as this app can control, but a fatal parse/compile error IN bootstrap.php itself, before
# ErrorHandler ever gets to run, would otherwise still hit PHP's own default display_errors (which
# php:8.2-apache leaves On) and could leak a stack trace/file path. Phase 10, STEP 31.
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 24M'; \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini

# The legacy-style SMS API deliberately lives at /sms/url_send.html (not
# .php) so integrations already pointed at a "url_send.html"-shaped
# endpoint from another provider work unchanged — this makes Apache
# actually run it as PHP instead of serving it as a static file.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}/sms">'; \
      echo '    AddHandler application/x-httpd-php .html'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/ellsms-sms-api.conf

# All URL rewrites for this app in ONE <Directory> block/file, deliberately — empirically confirmed
# during Phase 12 development that Apache 2.4 on this image does NOT reliably merge RewriteRule
# directives across multiple SEPARATE <Directory "${APACHE_DOCUMENT_ROOT}"> blocks declared in
# different conf-enabled/*.conf files (a second block's rules silently never got tried, despite
# `apachectl -t` reporting valid syntax and both files loading) — splitting rewrites across files
# is the bug, not a syntax error, so every RewriteRule for this docroot must live here.
#
# Health/readiness endpoints are plain .php files (public/health.php, public/health-ready.php —
# deliberately NOT public/health/ready.php: a real directory named "health" would collide with the
# bare /health path below and make Apache 301-redirect it to /health/ before this rewrite ever
# runs), same as every other page in this app — this rewrite just also answers the extension-less
# /health and /health/ready paths that most external monitors/orchestrators default to probing.
#
# Public API (Phase 12, docs/public-api.md) — public/api/index.php is the ONE front controller for
# every /api/v1/* route; it reads the path from REQUEST_URI itself (app/Api/Router.php), so this
# rewrite only has to get every request there, not carry PATH_INFO. [QSA] preserves any query
# string (pagination cursors, etc.) across the rewrite. CGIPassAuth is a defensive no-op under this
# image's mod_php SAPI (Authorization already reaches $_SERVER['HTTP_AUTHORIZATION'] without it,
# confirmed) but costs nothing to declare explicitly in case this ever moves to php-fpm/CGI, where
# it would be required.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}">'; \
      echo '    RewriteEngine On'; \
      echo '    RewriteRule ^health/ready/?$ health-ready.php [L]'; \
      echo '    RewriteRule ^health/?$ health.php [L]'; \
      echo '    RewriteCond %{REQUEST_FILENAME} !-f'; \
      echo '    RewriteRule ^api/v1(/.*)?$ api/index.php [QSA,L]'; \
      echo '    CGIPassAuth On'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/ellsms-rewrites.conf

# Defense in depth (Phase 10, STEP 26/30): APACHE_DOCUMENT_ROOT already points at public/, which
# structurally excludes .env/.git/app/cron/docs/tests (they live outside it entirely, so Apache
# can't serve them without an explicit alias, which doesn't exist here) — this block is a second,
# independent layer in case a dotfile or backup/editor-swap file (.git, .env, *.bak, *~) is ever
# accidentally placed inside public/ in the future.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}">'; \
      echo '    <FilesMatch "(^\.|\.bak$|~$|\.sql$)">'; \
      echo '        Require all denied'; \
      echo '    </FilesMatch>'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/ellsms-deny-sensitive.conf

WORKDIR /var/www/html
COPY . /var/www/html

# storage/kyc is bind-mounted from the host at runtime (see
# docker-compose.yml), so build-time ownership doesn't carry over —
# this ensures it exists and is writable by Apache (www-data) every
# time the container actually starts, regardless of host-side ownership.
COPY docker/entrypoint.sh /usr/local/bin/ellsms-entrypoint.sh
RUN chmod +x /usr/local/bin/ellsms-entrypoint.sh
ENTRYPOINT ["ellsms-entrypoint.sh"]
CMD ["apache2-foreground"]
