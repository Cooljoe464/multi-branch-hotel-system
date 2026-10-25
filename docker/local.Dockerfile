FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    icu-dev \
    libpq-dev \
    libzip-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /app

EXPOSE 8000 8080
