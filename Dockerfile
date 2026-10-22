# Patched PHP (CVE-2026-109618 / CVE-2026-109619). Do not use richarvey/nginx-php-fpm:3.1.6 (PHP 8.2.7).
FROM serversideup/php:8.3.36-fpm-nginx

USER root

WORKDIR /var/www/html

# App files (vendor is built below; see .dockerignore)
COPY --chown=www-data:www-data . /var/www/html

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Runs on each container start (migrate, cache, ProductionSeeder)
COPY --chmod=755 scripts/00-laravel-deploy.sh /etc/entrypoint.d/99-laravel-deploy.sh

# Render terminates TLS; image listens on 8080 as non-root
ENV SSL_MODE=off \
    NGINX_WEBROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/api/v1/health \
    COMPOSER_ALLOW_SUPERUSER=1

USER www-data
