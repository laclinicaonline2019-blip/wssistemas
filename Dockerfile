# syntax=docker/dockerfile:1.7
# AivexaClínica — imagem da aplicação (PHP-FPM 8.4). Multi-stage: dependências → runtime.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.4-fpm-alpine AS runtime
RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype postgresql-libs \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del .build

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-aivexa.ini
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN rm -f .env && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
