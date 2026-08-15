# syntax=docker/dockerfile:1

###############################################################################
# Build stage — frontend assets
#
# Compiled in their own stage so Node and node_modules never reach the runtime
# image; only the built output is copied forward.
###############################################################################
FROM node:22-alpine AS assets

WORKDIR /build

COPY package.json package-lock.json* ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

###############################################################################
# Build stage — PHP dependencies
###############################################################################
FROM composer:2 AS vendor

WORKDIR /build

COPY composer.json composer.lock ./
# Scripts are skipped here because artisan needs the full application, which
# is not present yet; they run in the runtime stage.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

###############################################################################
# Runtime
###############################################################################
FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        gd \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Persian locale data for intl date formatting.
ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/99-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/html

COPY --from=vendor /build/vendor ./vendor
COPY . .
COPY --from=assets /build/public/build ./public/build

# Storage and cache must be writable by the FPM user; everything else is not.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && composer dump-autoload --optimize --no-dev

USER www-data

EXPOSE 9000

# Fails fast if the container is up but PHP cannot answer.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(0);' || exit 1

CMD ["php-fpm"]
