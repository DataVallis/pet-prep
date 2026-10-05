# PetPrep production image (M4-05b) — PHP-FPM 8.3 + the application code + vendor.
#
# Targets (backend/compose.production.yaml builds both; context = backend/):
#   app  (default) php-fpm for `app`; the same image runs reverb, queue,
#        queue-broadcasts and scheduler with a CLI command.
#   web  caddy:2.8-alpine + public/ (Filament css/js, index.php for php_fastcgi
#        try_files). Caddy serves static files itself and pet media from its
#        read-only mount of the storage volume (X-Accel-Redirect).
#
# The code is baked into the image (no bind mount of repo/backend): an image is
# one release. vendor/ comes from `composer install --no-dev` against
# composer.lock, so a lock change reaches production with the next deploy.
# OPcache never re-checks files (validate_timestamps=0) — a deploy recreates the
# containers. Caches that need the production env (config) are built when a
# container starts (docker/production/entrypoint.sh), never baked into the image.
#
# Local smoke build:  docker build -f docker/production/Dockerfile --target app .

ARG PHP_VERSION=8.3

FROM composer:2 AS composer

# ---- base: PHP runtime + extensions + config ---------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# Extensions beyond the official image's defaults: pgsql (DB), redis (phpredis:
# cache, queue, Reverb scaling), intl (Filament), zip (openspout exports), bcmath,
# gd + exif (image validation), pcntl (queue:work / reverb signals), opcache.
# libfcgi-bin: cgi-fcgi for the FPM ping health check (Debian stable package —
# pinning an exact version would break the build at every Debian point release).
# hadolint ignore=DL3008
RUN set -eux; \
    install-php-extensions bcmath exif gd intl opcache pcntl pdo_pgsql pgsql redis zip; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfcgi-bin; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    rm -f /usr/local/etc/php-fpm.d/*.conf; \
    groupadd --gid 1000 petprep; \
    useradd --uid 1000 --gid 1000 --create-home --shell /usr/sbin/nologin petprep

COPY docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-petprep.ini"
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-petprep.conf

# ---- build: composer install (no dev) + package discovery + Filament assets ----------
FROM base AS build

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1
WORKDIR /app

# Dependencies first: this layer is reused until composer.json / composer.lock change.
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-progress --no-scripts --no-autoloader

COPY . .
RUN set -eux; \
    mkdir -p bootstrap/cache storage/app/public storage/app/pet-media storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views; \
    composer dump-autoload --no-dev --optimize --no-scripts; \
    php artisan package:discover --ansi; \
    php artisan filament:assets --ansi; \
    php -l public/index.php

# ---- web: Caddy + public/ ------------------------------------------------------------
FROM caddy:2.8-alpine AS web
COPY --from=build /app/public /var/www/html/public

# ---- app: php-fpm (default target) -----------------------------------------------------
FROM base AS app

ENV APP_ENV=production \
    VIEW_COMPILED_PATH=/var/www/html/bootstrap/cache/views
WORKDIR /var/www/html

# Code owned by root (read-only for the runtime user); only the cache dirs are
# writable. storage/ is the app_storage volume at runtime (files owned by uid
# 1000 since the Sail era — same uid as `petprep`).
COPY --from=build /app /var/www/html
COPY docker/production/entrypoint.sh /usr/local/bin/petprep-entrypoint
COPY docker/production/php-fpm-ping.sh /usr/local/bin/php-fpm-ping
RUN set -eux; \
    chmod 0755 /usr/local/bin/petprep-entrypoint /usr/local/bin/php-fpm-ping; \
    mkdir -p bootstrap/cache/views; \
    chown -R petprep:petprep bootstrap/cache storage

USER petprep
EXPOSE 9000
ENTRYPOINT ["petprep-entrypoint"]
CMD ["php-fpm"]
