# syntax=docker/dockerfile:1
#
# Production image for Ada Chat: nginx and PHP-FPM under supervisord, as the
# unprivileged www-data user, listening on port 8080. The scheduler runs from
# the same image with `php artisan schedule:work` (compose.production.yml).
#
#   docker build -t ada-chat .
#
# Configuration comes from the environment at run time; nothing secret is
# baked into the image. See docs/deployment.md.

ARG PHP_VERSION=8.4

# --- Runtime base: PHP-FPM with the extensions Ada needs, nginx, supervisord
FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN apk add --no-cache icu-libs libzip nginx supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    && chown -R www-data:www-data /run/nginx /var/lib/nginx /var/log/nginx

WORKDIR /app

# --- PHP dependencies, without development packages
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan wayfinder:generate --with-form

# --- Front-end build, on glibc (the bundler's native binaries); the route
# helpers were generated above, so PHP is not needed here
FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY --from=vendor /app /app
RUN WAYFINDER_COMMAND=true npm run build

# --- Final image
FROM base

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter \
    PHP_FPM_MAX_CHILDREN=24 \
    PHP_FPM_REQUEST_TERMINATE_TIMEOUT=420 \
    ADA_MIGRATE=true

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-ada.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-ada.conf
COPY docker/production/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/ada-entrypoint

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build

RUN rm -f /usr/local/etc/php-fpm.d/zz-docker.conf \
    && ln -sfn ../storage/app/public public/storage \
    && rm -rf node_modules tests .env \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

USER www-data

EXPOSE 8080
VOLUME ["/app/storage"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["ada-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
