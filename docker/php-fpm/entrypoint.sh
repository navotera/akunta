#!/bin/sh
set -eu

APP_DIR="/var/www/html/${APP_PATH}"

# Named storage volumes are empty on first deployment, so recreate Laravel's
# writable directories after the volume is mounted.
mkdir -p \
    "${APP_DIR}/storage/app/private" \
    "${APP_DIR}/storage/app/public" \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/testing" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/bootstrap/cache"

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

exec docker-php-serversideup-entrypoint "$@"
