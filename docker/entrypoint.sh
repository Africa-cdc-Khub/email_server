#!/bin/sh
# Keep this minimal: always reach php-fpm / queue:work.
# Heavy setup (seed) is handled by setup.sh after /up is healthy.

cd /var/www/backend || exit 1

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_AUDIT=false
export REDIS_CLIENT="${REDIS_CLIENT:-predis}"

echo "==> Entry role=${CONTAINER_ROLE:-app} env=${APP_ENV:-unknown} redis_client=${REDIS_CLIENT}"

if [ ! -f vendor/autoload.php ]; then
  if [ "$CONTAINER_ROLE" = "queue" ]; then
    echo "==> Waiting for vendor/ (installed by setup or app)..."
    i=1
    while [ "$i" -le 90 ]; do
      [ -f vendor/autoload.php ] && break
      i=$((i + 1))
      sleep 2
    done
    if [ ! -f vendor/autoload.php ]; then
      echo "ERROR: vendor/autoload.php still missing after wait" >&2
      exit 1
    fi
  else
    echo "==> Installing Composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction || {
      echo "ERROR: composer install failed" >&2
      exit 1
    }
  fi
else
  echo "Vendor present, skipping composer install."
fi

wait_for_tcp() {
  name="$1"
  host="$2"
  port="$3"
  tries="${4:-45}"

  echo "Waiting for ${name} at ${host}:${port}..."
  i=1
  while [ "$i" -le "$tries" ]; do
    if WAIT_HOST="$host" WAIT_PORT="$port" php -r '
      $fp = @fsockopen(getenv("WAIT_HOST"), (int) getenv("WAIT_PORT"), $e, $s, 1);
      if ($fp) { fclose($fp); exit(0); }
      exit(1);
    ' 2>/dev/null; then
      echo "${name} is reachable."
      return 0
    fi
    i=$((i + 1))
    sleep 1
  done
  echo "WARNING: ${name} not reachable yet — continuing anyway" >&2
  return 0
}

wait_for_tcp Redis "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}" 45
wait_for_tcp MySQL "${DB_HOST:-mysql}" "${DB_PORT:-3306}" 60

# Clear cached config that may force phpredis / wrong DB password
rm -f bootstrap/cache/config.php \
  bootstrap/cache/routes-v7.php \
  bootstrap/cache/routes.php \
  bootstrap/cache/services.php 2>/dev/null || true

if [ "$CONTAINER_ROLE" = "queue" ]; then
  echo "==> Starting queue worker..."
  exec "$@"
fi

if [ ! -f .env ]; then
  echo "ERROR: backend/.env missing" >&2
  exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "==> Generating APP_KEY..."
  php artisan key:generate --force || true
fi

echo "==> Running migrations (non-fatal)..."
if ! php artisan migrate --force; then
  echo "WARNING: migrate failed — check DB_PASSWORD matches the MySQL volume" >&2
  php artisan migrate --force -v 2>&1 | tail -40 || true
fi

php artisan storage:link --force 2>/dev/null || true

# Do NOT seed or config:cache here — those caused crash loops on production.
# setup.sh seeds after /up succeeds.

echo "==> Starting php-fpm..."
exec "$@"
