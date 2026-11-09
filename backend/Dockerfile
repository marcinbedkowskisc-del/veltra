FROM php:8.4-cli-alpine AS tooling

RUN apk add --no-cache libpq oniguruma libxml2 unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev oniguruma-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql mbstring dom xml xmlwriter pcntl \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM tooling AS app
WORKDIR /var/www/html
COPY docker/uploads.ini /usr/local/etc/php/conf.d/veltra-uploads.ini
COPY --chown=www-data:www-data . .

RUN touch .env \
    && composer install --no-interaction --prefer-dist --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
