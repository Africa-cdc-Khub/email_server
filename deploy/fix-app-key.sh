#!/usr/bin/env bash
# Fix MissingAppKeyException (500 on /api/documentation and other routes).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Current APP_KEY in backend/.env"
if grep -E '^APP_KEY=' backend/.env 2>/dev/null | sed 's/^\(APP_KEY=\).*/\1[redacted]/' ; then
  :
else
  echo "(APP_KEY line missing)"
fi

_key="$(grep -E '^APP_KEY=' backend/.env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
if [[ -z "$_key" || "$_key" != base64:* ]]; then
  echo "==> Generating APP_KEY in backend/.env"
  _new="base64:$(openssl rand -base64 32 | tr -d '\n')"
  if grep -q '^APP_KEY=' backend/.env 2>/dev/null; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${_new}|" backend/.env
  else
    printf 'APP_KEY=%s\n' "$_new" >> backend/.env
  fi
  chmod 600 backend/.env 2>/dev/null || sudo chmod 600 backend/.env
  echo "    APP_KEY set"
else
  echo "==> APP_KEY already present"
fi

echo "==> Clear Laravel bootstrap cache (stale config caches empty key)"
rm -f backend/bootstrap/cache/config.php \
  backend/bootstrap/cache/services.php \
  backend/bootstrap/cache/routes.php \
  backend/bootstrap/cache/routes-v7.php 2>/dev/null || true

cd docker
echo "==> Recreate app container (entrypoint re-validates APP_KEY)"
docker compose up -d --force-recreate --no-deps app
sleep 4

docker compose exec -T app php artisan config:clear || true
docker compose exec -T app php artisan route:clear || true

echo "==> Verify inside container"
docker compose exec -T app grep -E '^APP_KEY=base64:' .env | sed 's/^\(APP_KEY=\).*/\1[ok]/' || {
  echo "ERROR: APP_KEY still not set in container .env" >&2
  exit 1
}

docker compose exec -T app php artisan --version
echo "==> Done — retry https://notifications.africacdc.org/api/documentation"
