#!/usr/bin/env sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

if [ -z "${APP_KEY:-}" ] && [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

if [ "${RUN_QUEUE_WORKER:-false}" = "true" ] && command -v supervisord >/dev/null 2>&1 && [ -f /etc/supervisor/conf.d/laravel-queue.conf ]; then
    supervisord -c /etc/supervisor/conf.d/laravel-queue.conf &
fi

exec "$@"
