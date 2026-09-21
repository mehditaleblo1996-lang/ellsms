FROM php:8.2-apache

# pcntl is needed by cron/worker.php for graceful SIGTERM/SIGINT handling.
# ext-mongodb backs the dedicated MongoDB audit trail. qrencode renders TOTP provisioning QR codes
# locally inside the app container so MFA secrets are never sent to an external QR service.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev default-mysql-client gnupg libssl-dev pkg-config qrencode $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql zip pcntl \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite headers \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/*

# wkhtmltopdf renders the ELLSMS-branded invoice/price-quote PDF (public/price-quote.php,
# public/telegram-webhook.php's /invoice command — see app/PriceQuote.php) locally, the same
# "shell out to one pinned local binary with a controlled argv" pattern qrencode already uses
# above for TOTP QR codes. This project ships zero production Composer dependencies by design
# (composer.json's require has none; PHPUnit is require-dev only, see Makefile's
# composer-install target), so a PHP PDF library was deliberately not added for this.
#
# The official static-Qt "wkhtmltox" build is used instead of Debian's own wkhtmltopdf package:
# it's self-contained (patched, headless-capable QtWebKit) and needs no Xvfb/X11 server, unlike
# the plain apt package. Pinned by exact version + sha256 so a build never silently picks up a
# different binary. Bump both together when intentionally upgrading:
# https://github.com/wkhtmltopdf/packaging/releases
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates fontconfig libxrender1 libjpeg62-turbo libxext6 \
 && curl -fsSL -o /tmp/wkhtmltox.deb \
      "https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6.1-3/wkhtmltox_0.12.6.1-3.bookworm_amd64.deb" \
 && echo "98ba0d157b50d36f23bd0dedf4c0aa28c7b0c50fcdcdc54aa5b6bbba81a3941d  /tmp/wkhtmltox.deb" | sha256sum -c - \
 && apt-get install -y --no-install-recommends /tmp/wkhtmltox.deb \
 && rm -f /tmp/wkhtmltox.deb \
 && apt-get purge -y --auto-remove curl \
 && rm -rf /var/lib/apt/lists/*

# Serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Sensible PHP defaults. post_max_size covers two KYC document uploads
# (id card + second document, 8MB cap each in app/bootstrap.php) in one
# submission, plus form overhead.
#
# Large send-cost previews can hold normalized recipients plus route/operator pricing resolutions
# in memory. Keep a finite per-request ceiling, but allow up to 4G for very large operator workloads.
# AuditPrepend registers a shutdown hook for every HTTP PHP entrypoint. It does no Mongo I/O before
# the application runs and is fail-safe, so MongoDB can never make an ELLSMS request unavailable.
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'memory_limit = 4G'; \
      echo 'upload_max_filesize = 20M'; \
      echo 'post_max_size = 30M'; \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'auto_prepend_file = /var/www/html/app/Support/AuditPrepend.php'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini

# The legacy-style SMS API deliberately lives at /sms/url_send.html (not .php) so integrations
# already pointed at a url_send.html-shaped endpoint work unchanged.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}/sms">'; \
      echo '    AddHandler application/x-httpd-php .html'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/ellsms-sms-api.conf

# All app URL rewrites live in one reviewed configuration file. This includes:
# - clean browser URLs for public/user/admin pages
# - compatibility redirects from legacy GET/HEAD .php URLs
# - unchanged public API routing
# - unchanged /health and /health/ready monitor contracts
COPY docker/apache-clean-urls.conf /etc/apache2/conf-enabled/ellsms-rewrites.conf

# Defense in depth: the document root already excludes .env/.git/app/cron/docs/tests; this also
# denies dotfiles, editor backups and SQL files if one is accidentally placed under public/.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}">'; \
      echo '    <FilesMatch "(^\\.|\\.bak$|~$|\\.sql$)">'; \
      echo '        Require all denied'; \
      echo '    </FilesMatch>'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-enabled/ellsms-deny-sensitive.conf

WORKDIR /var/www/html
COPY . /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/ellsms-entrypoint.sh
RUN chmod +x /usr/local/bin/ellsms-entrypoint.sh
ENTRYPOINT ["ellsms-entrypoint.sh"]
CMD ["apache2-foreground"]
