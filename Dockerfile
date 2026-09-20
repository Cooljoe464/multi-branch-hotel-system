# ──────────────────────────────────────────────
# Stage 1: Install PHP dependencies
# ──────────────────────────────────────────────
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .

RUN composer dump-autoload \
    --optimize \
    --no-dev \
    --classmap-authoritative

# ──────────────────────────────────────────────
# Stage 2: Build frontend assets
# ──────────────────────────────────────────────
FROM node:22-alpine AS node

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tailwind.config.ts tsconfig.json ./
COPY resources/css resources/css
COPY resources/js resources/js

RUN npm run build

# ──────────────────────────────────────────────
# Stage 3: PHP-FPM runtime
# ──────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS php

# System dependencies for extensions + supervisor
RUN apk add --no-cache \
    supervisor \
    libpq-dev \
    icu-libs \
    fcgi

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    intl \
    opcache \
    pcntl

# Install Redis extension via PECL
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis

# PHP-FPM config: listen on tcp 9000
RUN echo "listen = 0.0.0.0:9000" > /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/zz-docker.conf \
    && echo "pm.status_path = /status" >> /usr/local/etc/php-fpm.d/zz-docker.conf

# OPcache settings for production
RUN echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=256" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=20000" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit=1255" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size=128M" >> /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /app

# Copy composer deps from composer stage
COPY --from=composer /app/vendor vendor
COPY --from=composer /app/composer.json .

# Copy built frontend assets from node stage
COPY --from=node /app/public/build public/build

# Copy application source
COPY . .

# Copy supervisord config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Set permissions
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
