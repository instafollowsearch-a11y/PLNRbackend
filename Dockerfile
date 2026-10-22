# Patched PHP (CVE-2026-109618 / CVE-2026-109619).
# Port 80 (same as previous richarvey image) so Render free plans need no port change.
FROM serversideup/php:8.3.36-fpm-nginx

USER root

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Runs on each container start (migrate, cache, ProductionSeeder)
COPY --chmod=755 scripts/00-laravel-deploy.sh /etc/entrypoint.d/99-laravel-deploy.sh

# Listen on 80 like the old image (requires root; Render free plans often cannot remaps ports)
ENV SSL_MODE=off \
    NGINX_HTTP_PORT=80 \
    NGINX_WEBROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/api/v1/health \
    COMPOSER_ALLOW_SUPERUSER=1

EXPOSE 80

# Stay root so nginx can bind to privileged port 80
USER root
