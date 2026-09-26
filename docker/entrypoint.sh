#!/bin/sh
set -e
cd /var/www/app

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: docker compose run --rm --entrypoint php app artisan key:generate --show" >&2
  exit 1
fi

# Wait for the database, then migrate (safe on every start) and seed base data
# (subscription plans + Super Admin from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD).
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  i=0
  until php artisan migrate --force --no-interaction; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then echo "Database not reachable" >&2; exit 1; fi
    echo "Waiting for database ($i)"
    sleep 3
  done
  php artisan db:seed --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
