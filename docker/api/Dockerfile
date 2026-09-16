FROM php:8.4-cli-alpine

RUN apk add --no-cache git unzip libpq-dev icu-dev sqlite-dev bash docker-cli \
    && docker-php-ext-install pdo_pgsql pdo_sqlite intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY docker/api/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
