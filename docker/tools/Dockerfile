ARG COMPOSER_VERSION=2.9.2
ARG PHP_VERSION=8.5.1

FROM composer:${COMPOSER_VERSION} AS composer

FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install \
        bcmath \
        pcntl \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENTRYPOINT ["composer"]
