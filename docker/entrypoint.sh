#!/bin/sh
set -e

# Ensure storage and cache directories exist and are writable
mkdir -p /app/storage/framework/{sessions,views,cache}
mkdir -p /app/storage/logs
mkdir -p /app/bootstrap/cache

chown -R www-data:www-data /app/storage /app/bootstrap/cache 2>/dev/null || true
chmod -R 775 /app/storage /app/bootstrap/cache 2>/dev/null || true

# Run migrations if DB is reachable (first boot)
if php artisan tinker --execute='DB::connection()->getPdo();' 2>/dev/null; then
    echo "Running migrations..."
    php artisan migrate --force
    echo "Caching config..."
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

exec "$@"
