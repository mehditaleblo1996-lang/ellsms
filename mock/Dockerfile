FROM php:8.2-cli-alpine

WORKDIR /var/www/mock
COPY mock/gateway.php /var/www/mock/gateway.php

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "/var/www/mock/gateway.php"]
