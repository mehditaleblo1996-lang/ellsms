FROM php:8.2-apache

# pcntl is needed by cron/worker.php for graceful SIGTERM/SIGINT handling.
# ext-mongodb backs the dedicated MongoDB audit trail. Build dependencies are removed after PECL
# compilation so the runtime image does not carry a compiler toolchain.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libzip-dev default-mysql-client gnupg libssl-dev pkg-config $PHPIZE_DEPS \
 && docker-php-ext-install pdo_mysql zip pcntl \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite headers \
 && apt-get purge -y --auto-remove $PHPIZE_DEPS \
 && rm -rf /var/lib/apt/lists/*

# Serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Sensible PHP defaults. post_max_size covers two KYC document uploads
# (id card + second document, 8MB cap each in app/bootstrap.php) in one
# submission, plus form overhead.
# AuditPrepend registers a shutdown hook for every HTTP PHP entrypoint. It does no Mongo I/O before
# the application runs and is fail-safe, so MongoDB can never make an ELLSMS request unavailable.
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 24M'; \
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

# All URL rewrites for this app in ONE <Directory> block/file. Public API requests are routed to
# public/api/index.php; health endpoints remain extension-less for monitors.
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

# Defense in depth: the document root already excludes .env/.git/app/cron/docs/tests; this also
# denies dotfiles, editor backups and SQL files if one is accidentally placed under public/.
RUN { \
      echo '<Directory "${APACHE_DOCUMENT_ROOT}">'; \
      echo '    <FilesMatch "(^\.|\.bak$|~$|\.sql$)">'; \
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
