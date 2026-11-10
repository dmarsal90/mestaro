# syntax=docker/dockerfile:1.7
# Shared by every Mestaro service. Targets: dev (code bind-mounted). prod target: Phase 8.
ARG PHP_VERSION=8.4.27
ARG NODE_VERSION=22.22.2
ARG COMPOSER_VERSION=2.10.3
ARG EXTENSION_INSTALLER_VERSION=2.12.0

FROM composer:${COMPOSER_VERSION} AS composer-bin
FROM node:${NODE_VERSION}-bookworm-slim AS node-bin
FROM mlocati/php-extension-installer:${EXTENSION_INSTALLER_VERSION} AS extension-installer

FROM php:${PHP_VERSION}-fpm-bookworm AS base
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql redis intl zip opcache pcntl bcmath sodium \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl procps unzip ca-certificates tzdata git \
    && rm -rf /var/lib/apt/lists/*
ENV PHP_FPM_MAX_CHILDREN=20
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=755 docker/healthcheck.sh /usr/local/bin/healthcheck
WORKDIR /var/www/html
ENTRYPOINT ["entrypoint"]

FROM base AS dev
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN install-php-extensions xdebug
COPY docker/php/php-dev.ini "$PHP_INI_DIR/conf.d/zz-dev.ini"
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
COPY --from=node-bin /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && groupmod -o -g "${HOST_GID}" www-data \
    && usermod -o -u "${HOST_UID}" -g www-data www-data \
    && chown -R www-data:www-data /var/www
ENV XDEBUG_MODE=off COMPOSER_HOME=/var/www/.composer npm_config_cache=/var/www/.npm
USER www-data
CMD ["fpm"]
