FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Sensible PHP defaults. post_max_size covers two KYC document uploads
# (id card + second document, 8MB cap each in app/bootstrap.php) in one
# submission, plus form overhead.
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 24M'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini

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
