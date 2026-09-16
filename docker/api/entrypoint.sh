#!/bin/sh
set -e
cd /var/www

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

php artisan migrate --force

if [ "$#" -gt 0 ]; then
  exec "$@"
fi

exec php artisan serve --host=0.0.0.0 --port=8000
