FROM php:8.4-fpm-alpine AS application

ARG APP_ENV=production

RUN set -eux; \
    apk add --no-cache \
        curl \
        libcurl \
        nginx \
        supervisor \
        tzdata; \
    apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        curl-dev; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" curl pdo_mysql; \
    apk del .build-dependencies; \
    rm -rf /var/cache/apk/* /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN set -eux; \
    composer install \
        --no-dev \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist; \
    composer clear-cache

COPY . .
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-gamble.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint

RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    chmod +x /usr/local/bin/docker-entrypoint; \
    mkdir -p /app/var/log /var/lib/php/sessions; \
    chown -R www-data:www-data /app/var /var/lib/php/sessions

ENV APP_ENV=${APP_ENV}

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
