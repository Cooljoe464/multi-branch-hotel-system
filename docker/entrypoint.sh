#!/bin/sh
set -e

# Ensure storage and cache directories exist and are writable
mkdir -p /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs /app/bootstrap/cache

chown -R www-data:www-data /app/storage /app/bootstrap/cache 2>/dev/null || true
chmod -R 775 /app/storage /app/bootstrap/cache 2>/dev/null || true

# Provision the runtime role and run migrations only in the application container.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    rm -f /tmp/hms-migrations-ready
    php artisan db:provision-runtime-role --no-interaction
    echo "Running migrations..."
    php artisan migrate --database=migration --force
    php artisan route:cache
    php artisan event:cache
    touch /tmp/hms-migrations-ready
fi

exec "$@"
