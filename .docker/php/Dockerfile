FROM php:8.2-cli-alpine

RUN apk add --no-cache postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev postgresql-dev \
    && pecl install mongodb-1.21.5 \
    && docker-php-ext-enable mongodb \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
