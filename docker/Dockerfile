FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql && a2enmod rewrite headers

# Serve from /public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Sensible PHP defaults
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 12M'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini

WORKDIR /var/www/html
COPY . /var/www/html
