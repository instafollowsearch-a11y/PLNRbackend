FROM richarvey/nginx-php-fpm:3.1.6

COPY . .

# Image config (Render + nginx-php-fpm)
ENV SKIP_COMPOSER=1
ENV WEBROOT=/var/www/html/public
ENV PHP_ERRORS_STDERR=1
ENV RUN_SCRIPTS=1
ENV REAL_IP_HEADER=1

# Laravel
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV LOG_CHANNEL=stderr

# Allow composer to run as root
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN chmod +x /var/www/html/scripts/00-laravel-deploy.sh

CMD ["/start.sh"]
