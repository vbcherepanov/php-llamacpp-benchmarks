# syntax=docker/dockerfile:1.7
FROM php:8.4-cli-bookworm

ARG DEBIAN_FRONTEND=noninteractive

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update \
 && apt-get install -y --no-install-recommends \
        libpq-dev \
        libffi-dev \
        unzip \
        git \
        ca-certificates \
        python3 \
        python3-matplotlib \
        python3-pandas \
        procps

RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache ffi \
 && docker-php-ext-enable opcache ffi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-bench.ini

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1
