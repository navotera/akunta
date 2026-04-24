FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        git \
        curl \
        bash \
        icu-dev \
        libzip-dev \
        libpng-dev \
        postgresql-dev \
        oniguruma-dev \
        autoconf \
        g++ \
        make \
    && docker-php-ext-install \
        intl \
        pdo_pgsql \
        pgsql \
        bcmath \
        zip \
        gd \
        mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php-fpm"]
