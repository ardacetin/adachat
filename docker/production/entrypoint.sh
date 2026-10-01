#!/bin/sh
# Prepares the container, then runs the command (supervisord for the web
# container, `php artisan schedule:work` for the scheduler).
set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with: docker run --rm ada-chat php artisan key:generate --show" >&2
    echo "and keep it safe: it encrypts the provider API keys (docs/deployment.md)." >&2
    exit 1
fi

# A fresh storage volume starts empty.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

# The configuration is read from the environment once, at start.
php artisan optimize --no-interaction

if [ "${ADA_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
