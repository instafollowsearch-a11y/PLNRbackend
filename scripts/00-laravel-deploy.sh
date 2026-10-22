#!/bin/sh
set -e

cd /var/www/html

if [ ! -f artisan ]; then
  echo "artisan not found; skipping Laravel deploy steps"
  exit 0
fi

# Vendor should already exist from the image build; refresh if missing
if [ ! -f vendor/autoload.php ]; then
  echo "Running composer"
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --working-dir=/var/www/html
fi

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
