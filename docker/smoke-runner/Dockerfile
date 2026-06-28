ARG PHP_VERSION=8.4
ARG COMPOSER_VERSION=2.9.2

FROM php:${PHP_VERSION}-cli

ARG COMPOSER_VERSION

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer-home \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        npm \
        unzip \
        zip \
    && docker-php-ext-install intl pdo_mysql pdo_pgsql zip \
    && curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --version="${COMPOSER_VERSION}" --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /tmp/composer-home /tmp/composer-cache \
    && chmod 0777 /tmp/composer-home /tmp/composer-cache

WORKDIR /workspace
