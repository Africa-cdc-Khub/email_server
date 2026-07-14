#!/bin/sh
# Keep this minimal: always reach php-fpm / queue:work.
# Heavy setup (seed) is handled by setup.sh after /up is healthy.

cd /var/www/backend || exit 1

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_AUDIT=false
export REDIS_CLIENT="${REDIS_CLIENT:-predis}"

echo "==> Entry role=${CONTAINER_ROLE:-app} env=${APP_ENV:-unknown} redis_client=${REDIS_CLIENT}"

if [ ! -f vendor/autoload.php ] \
  || [ ! -f vendor/symfony/deprecation-contracts/function.php ]; then
  if [ "$CONTAINER_ROLE" = "queue" ]; then
    echo "==> Waiting for complete vendor/ (installed by setup or app)..."
    i=1
    while [ "$i" -le 90 ]; do
      if [ -f vendor/autoload.php ] \
        && [ -f vendor/symfony/deprecation-contracts/function.php ]; then
        break
      fi
      i=$((i + 1))
      sleep 2
    done
    if [ ! -f vendor/autoload.php ] \
      || [ ! -f vendor/symfony/deprecation-contracts/function.php ]; then
      echo "ERROR: vendor/ still incomplete after wait" >&2
      exit 1
    fi
  else
    echo "==> Installing Composer dependencies (vendor missing or incomplete)..."
    rm -rf vendor
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

if [ ! -f .env ]; then
  echo "ERROR: backend/.env missing" >&2
  exit 1
fi

# Ensure APP_KEY without artisan (artisan --version itself needs the key).
# flock avoids races when app + multiple queue workers start together.
ensure_app_key() {
  if grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    return 0
  fi

  echo "==> Generating APP_KEY (no artisan)..."
  KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));' 2>/dev/null || true)"
  if [ -z "$KEY" ]; then
    KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
  fi

  grep -v '^APP_KEY=' .env > .env.appkey.tmp 2>/dev/null || cp .env .env.appkey.tmp
  printf 'APP_KEY=%s\n' "$KEY" >> .env.appkey.tmp
  mv .env.appkey.tmp .env
  chmod 600 .env 2>/dev/null || true
}

if command -v flock >/dev/null 2>&1; then
  (
    flock -w 30 9 || true
    ensure_app_key
  ) 9>.env.lock
else
  ensure_app_key
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "ERROR: APP_KEY still missing after generation" >&2
  exit 1
fi

dump_artisan_error() {
  echo "ERROR: php artisan failed to boot — last output:" >&2
  php artisan --version -vvv 2>&1 | tail -80 >&2 || true
  echo "--- .env key presence ---" >&2
  grep -E '^(APP_KEY|APP_ENV|DB_HOST|REDIS_CLIENT)=' .env 2>/dev/null | sed 's/^\(APP_KEY=\).*/\1[set]/' >&2 || true
}

if [ "$CONTAINER_ROLE" = "queue" ]; then
  echo "==> Queue boot check..."
  if ! php artisan --version; then
    dump_artisan_error
    exit 1
  fi
  echo "==> Starting queue worker (emails,default)..."
  exec php artisan queue:work \
    --queue=emails,default \
    --sleep=1 \
    --tries=5 \
    --timeout=120 \
    --verbose
fi

echo "==> Running migrations (non-fatal)..."
if ! php artisan migrate --force; then
  echo "WARNING: migrate failed — check DB_PASSWORD matches the MySQL volume" >&2
  php artisan migrate --force -v 2>&1 | tail -40 || true
fi

php artisan storage:link --force 2>/dev/null || true

echo "==> Starting php-fpm..."
exec php-fpm
