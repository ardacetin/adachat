#!/bin/bash
# Claude Code on the web: make tests, linters and the build work in a fresh
# container. Local sessions are left alone.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(dirname "$0")/../..}"

# MySQL: the test suite needs it (phpunit.xml uses the ada_test database).
if ! command -v mysqld >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq && apt-get install -y -qq mysql-server >/dev/null
fi
if ! mysqladmin ping --silent >/dev/null 2>&1; then
  service mysql start >/dev/null
fi
for _ in $(seq 1 30); do
  mysqladmin ping --silent >/dev/null 2>&1 && break
  sleep 1
done
mysql -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS ada CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ada_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ada'@'127.0.0.1' IDENTIFIED BY 'secret';
CREATE USER IF NOT EXISTS 'ada'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON ada.* TO 'ada'@'127.0.0.1', 'ada'@'localhost';
GRANT ALL PRIVILEGES ON ada_test.* TO 'ada'@'127.0.0.1', 'ada'@'localhost';
SQL

# PHP dependencies (install, not ci: the container is cached after the hook).
# Packages are downloaded from github.com: where the network policy does not
# allow it, keep what is installed and say so instead of failing the session.
export COMPOSER_ALLOW_SUPERUSER=1
if ! composer install --no-interaction --no-progress --prefer-dist >/dev/null 2>&1; then
  echo "session-start: composer install failed (is github.com allowed?); using the installed vendor/." >&2
fi

# The development .env (database ada/secret on 127.0.0.1, as above).
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --no-interaction >/dev/null
fi

# JavaScript dependencies; Playwright uses the preinstalled Chromium.
export PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
npm install --no-audit --no-fund >/dev/null

# The development database, for the e2e tests and `php artisan serve`.
php artisan migrate --force --no-interaction >/dev/null
