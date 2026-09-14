#!/usr/bin/env bash
set -e

echo "Running composer"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Caching views..."
php artisan view:cache

echo "Running migrations..."
php artisan migrate --force

echo "Seeding plan types and app settings..."
php artisan db:seed --class=ProductionSeeder --force
