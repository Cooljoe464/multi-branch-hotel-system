# ──────────────────────────────────────────────
# Stage 1: Install PHP dependencies
# ──────────────────────────────────────────────
FROM composer:2.10 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-pcntl \
    --prefer-dist

COPY . .

RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload \
    --optimize \
    --no-dev \
    --classmap-authoritative

# ──────────────────────────────────────────────
# Stage 2: Build frontend assets
# ──────────────────────────────────────────────
FROM node:22.22-alpine3.24 AS node

WORKDIR /app

# Wayfinder runs artisan during the Vite build, so make the same application
# source and Composer dependencies available in this stage along with PHP CLI.
RUN apk add --no-cache \
    php84 \
    php84-ctype \
    php84-curl \
    php84-dom \
    php84-fileinfo \
    php84-mbstring \
    php84-openssl \
    php84-pdo \
    php84-phar \
    php84-session \
    php84-tokenizer \
    php84-xml \
    php84-zip \
    && ln -s /usr/bin/php84 /usr/local/bin/php

COPY --from=composer /app /app

RUN npm ci --no-audit --no-fund

RUN php artisan wayfinder:generate --with-form

RUN npm run build

# ──────────────────────────────────────────────
# Stage 3: PHP-FPM runtime
# ──────────────────────────────────────────────
FROM php:8.4-fpm-alpine3.24 AS php

# System dependencies for extensions + supervisor
RUN apk add --no-cache \
    supervisor \
    libpq-dev \
    libzip-dev \
    icu-dev \
    icu-libs \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    fcgi

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    gd \
    intl \
    opcache \
    pcntl \
    zip

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
RUN mkdir -p /app/bootstrap/cache /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache \
    && chmod -R 775 /app/storage /app/bootstrap/cache

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
