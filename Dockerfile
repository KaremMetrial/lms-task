# syntax=docker/dockerfile:1.7

# =============================================================================
# base — the shared PHP runtime every other stage builds on.
#
# Build dependencies are installed and removed inside the same layer, so the
# compilers never reach a published image.
# =============================================================================
FROM php:8.3-fpm-alpine AS base

# Runtime libraries only:
#   icu-libs / libzip : shared objects the intl + zip extensions link against
#   fcgi              : provides cgi-fcgi, used by the HEALTHCHECK below
#   tini              : real PID 1, so SIGTERM reaches queue workers intact
#   mysql-client       : lets us exec into a container and inspect the ledger
RUN apk add --no-cache \
        bash \
        fcgi \
        icu-libs \
        libzip \
        mysql-client \
        tini

# PHP extensions this project actually needs:
#   pdo_mysql : MySQL
#   bcmath    : arbitrary-precision guard rail for money maths
#   pcntl     : signal handling — without it `queue:work` cannot shut down
#               gracefully, which is exactly the failure mode we must survive
#   opcache   : throughput
#   intl, zip : framework + Filament requirements
#   redis     : queue and cache driver
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        linux-headers; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    pecl clear-cache; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear /usr/local/lib/php/doc

COPY --from=composer:2.9 /usr/bin/composer /usr/local/bin/composer

# Run as a non-root user whose UID matches the host developer, so files written
# through the bind mount (logs, cache, migrations) stay editable on the host.
ARG UID=1000
ARG GID=1000
RUN set -eux; \
    addgroup -g "${GID}" app; \
    adduser -D -u "${UID}" -G app -h /var/www -s /bin/bash app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# php-fpm exposes /ping via www.conf; cgi-fcgi speaks FastCGI to it directly.
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD REDIRECT_STATUS=true \
        SCRIPT_NAME=/ping \
        SCRIPT_FILENAME=/ping \
        REQUEST_METHOD=GET \
        cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

ENTRYPOINT ["/sbin/tini", "--", "docker-php-entrypoint"]
CMD ["php-fpm"]

# =============================================================================
# dev — what docker compose runs. The source tree arrives as a bind mount, so
# this stage deliberately copies no application code.
# =============================================================================
FROM base AS dev

ENV COMPOSER_MEMORY_LIMIT=-1

USER app

# =============================================================================
# vendor — production Composer dependencies, cached on composer.lock alone.
# =============================================================================
FROM base AS vendor

WORKDIR /app
COPY composer.json composer.lock ./

# --no-scripts: artisan is not present yet, so package:discover cannot run here.
# --no-autoloader: the classmap needs the application code, added in `prod`.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

# =============================================================================
# assets — compiled CSS/JS. Separate stage so Node never ships to production.
# =============================================================================
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
RUN npm run build

# =============================================================================
# prod — the deployable image: no Composer dev packages, no Node, no toolchain.
# =============================================================================
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /app/vendor ./vendor
COPY --from=assets --chown=app:app /app/public/build ./public/build

RUN set -eux; \
    composer dump-autoload --optimize --classmap-authoritative --no-dev; \
    mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache; \
    chown -R app:app storage bootstrap/cache; \
    rm -rf /usr/local/bin/composer

USER app
