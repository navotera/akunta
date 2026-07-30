FROM serversideup/php:8.4-fpm-nginx-alpine

USER root

# Server Side Up already includes Composer, PostgreSQL PDO, Redis, mbstring,
# zip, and the Laravel runtime defaults. Install only the extensions required
# by the applications that are not included in the base image.
RUN install-php-extensions bcmath exif gd intl

ARG APP_PATH
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_PATH=${APP_PATH} \
    NGINX_WEBROOT=/var/www/html/${APP_PATH}/public

COPY modules /var/www/html/modules
COPY ${APP_PATH} /var/www/html/${APP_PATH}

WORKDIR /var/www/html/${APP_PATH}
RUN rm -f bootstrap/cache/*.php \
    && composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts \
    && touch /tmp/akunta-build.sqlite \
    && DB_CONNECTION=sqlite DB_DATABASE=/tmp/akunta-build.sqlite php artisan package:discover --ansi \
    && rm -f /tmp/akunta-build.sqlite \
    && mkdir -p storage/app/public bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache vendor

COPY docker/php-fpm/entrypoint.sh /usr/local/bin/akunta-entrypoint
RUN chmod +x /usr/local/bin/akunta-entrypoint

USER root
ENTRYPOINT ["/usr/local/bin/akunta-entrypoint"]
CMD ["/init"]
