#!/bin/sh
set -e

echo "--> Starting Entrypoint Script..."

# 1. Run migrations if DB is connected
if [ -n "$DB_HOST" ]; then
    echo "--> Running database migrations..."
    php artisan migrate --force --no-interaction || echo "--> Migration failed, database might not be ready yet."
fi

# 2. Re-create storage link
echo "--> Creating storage link..."
php artisan storage:link --force || true

# 3. Cache configuration and routes for speed
echo "--> Caching Laravel config, routes, and views..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# 4. Start services
echo "--> Starting PHP-FPM..."
php-fpm -D

echo "--> Starting Nginx..."
nginx -g "daemon off;"
