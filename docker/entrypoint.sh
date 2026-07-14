#!/bin/sh
set -e

cd /var/www/backend

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_AUDIT=false

echo "==> Entry role=${CONTAINER_ROLE:-app} env=${APP_ENV:-unknown}"

if [ ! -f vendor/autoload.php ]; then
  echo "==> Installing Composer dependencies..."
  composer install --no-dev --optimize-autoloader --no-interaction --no-audit
else
  echo "Vendor present, skipping composer install."
fi

# Prefer phpredis when available; otherwise force predis (already in composer.json)
if php -m 2>/dev/null | grep -qi '^redis$'; then
  echo "==> PHP redis extension detected"
else
  echo "==> PHP redis extension missing — using predis client"
  export REDIS_CLIENT=predis
fi

wait_for_tcp() {
  name="$1"
  host="$2"
  port="$3"
  tries="${4:-60}"

  if [ -z "$host" ]; then
    return 0
  fi

  echo "Waiting for ${name} at ${host}:${port}..."
  i=1
  while [ "$i" -le "$tries" ]; do
    if WAIT_HOST="$host" WAIT_PORT="$port" php -r '
      $host = getenv("WAIT_HOST") ?: "";
      $port = (int) (getenv("WAIT_PORT") ?: 0);
      $fp = @fsockopen($host, $port, $errno, $errstr, 1);
      if ($fp) { fclose($fp); exit(0); }
      exit(1);
    ' 2>/dev/null; then
      echo "${name} is reachable."
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done

  echo "ERROR: ${name} not reachable at ${host}:${port} after ${tries}s" >&2
  exit 1
}

wait_for_tcp Redis "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" 60
wait_for_tcp MySQL "${DB_HOST:-mysql}" "${DB_PORT:-3306}" 90

if [ "$CONTAINER_ROLE" != "queue" ]; then
  if [ ! -f .env ]; then
    echo "ERROR: backend/.env is missing (bind-mounted). Run setup.sh first." >&2
    exit 1
  fi

  if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "==> Generating APP_KEY..."
    php artisan key:generate --force
  fi

  # Drop stale config cache that may bake wrong REDIS_CLIENT / empty secrets
  rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php 2>/dev/null || true

  echo "==> Running migrations..."
  php artisan migrate --force

  php artisan storage:link --force 2>/dev/null || true

  if [ "${RUN_SEEDER:-false}" = "true" ]; then
    echo "==> Seeding database..."
    if [ -z "${ADMIN_PASSWORD:-}" ]; then
      echo "ERROR: RUN_SEEDER=true but ADMIN_PASSWORD is empty." >&2
      exit 1
    fi
    php artisan db:seed --force
  fi

  if [ "${APP_ENV:-}" = "production" ]; then
    echo "==> Caching config..."
    php artisan config:cache || echo "WARNING: config:cache failed (continuing)"
    php artisan route:cache 2>/dev/null || echo "WARNING: route:cache skipped/failed (continuing)"
  fi

  echo "==> Starting php-fpm..."
else
  echo "==> Queue worker boot check..."
  if ! php artisan about; then
    echo "ERROR: php artisan about failed — queue worker cannot boot." >&2
    ls -la .env vendor/autoload.php 2>&1 || true
    exit 1
  fi
  echo "==> Starting queue worker..."
fi

exec "$@"
