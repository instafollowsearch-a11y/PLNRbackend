#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
exec php artisan queue:work --tries=3 --sleep=3
