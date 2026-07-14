#!/bin/sh
set -e

cd /var/www/backend

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_AUDIT=false

if [ ! -f vendor/autoload.php ]; then
  composer install --no-dev --optimize-autoloader --no-interaction --no-audit
else
  echo "Vendor present, skipping composer install."
fi

wait_for_redis() {
  if [ -z "$REDIS_HOST" ]; then
    return 0
  fi

  echo "Waiting for Redis at ${REDIS_HOST}:${REDIS_PORT:-6379}..."
  for i in $(seq 1 30); do
    if php -r "
      \$host = getenv('REDIS_HOST') ?: '127.0.0.1';
      \$port = (int) (getenv('REDIS_PORT') ?: 6379);
      \$fp = @fsockopen(\$host, \$port, \$errno, \$errstr, 1);
      if (\$fp) { fclose(\$fp); exit(0); }
      exit(1);
    "; then
      echo "Redis is reachable."
      return 0
    fi
    sleep 1
  done

  echo "Redis not reachable after 30s" >&2
  exit 1
}

wait_for_redis

if [ "$CONTAINER_ROLE" != "queue" ]; then
  if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
  fi

  php artisan migrate --force
  php artisan storage:link --force 2>/dev/null || true

  if [ "${RUN_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
  fi

  if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
  fi
fi

exec "$@"
