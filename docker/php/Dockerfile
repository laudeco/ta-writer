FROM php:8.1-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-install intl zip mbstring dom xml simplexml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini "$PHP_INI_DIR/conf.d/app.ini"

WORKDIR /app
