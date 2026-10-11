#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan schedule:run
