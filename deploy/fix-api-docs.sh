#!/usr/bin/env bash
# Fix /api/documentation 500 on production.
# Root cause was usually: MissingAppKeyException from web middleware (EncryptCookies)
# on the docs route, plus empty APP_KEY in backend/.env.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN="${DOMAIN:-notifications.africacdc.org}"
cd "$ROOT"

echo "==> 1) Ensure APP_KEY in backend/.env"
_key="$(grep -E '^APP_KEY=' backend/.env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true)"
if [[ -z "$_key" || "$_key" != base64:* ]]; then
  _new="base64:$(openssl rand -base64 32 | tr -d '\n')"
  if grep -q '^APP_KEY=' backend/.env 2>/dev/null; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${_new}|" backend/.env
  else
    printf '\nAPP_KEY=%s\n' "$_new" >> backend/.env
  fi
  chmod 600 backend/.env 2>/dev/null || sudo chmod 600 backend/.env || true
  echo "    generated APP_KEY"
else
  echo "    APP_KEY already set"
fi

echo "==> 2) Ensure API_DOCS_ENABLED=true in docker/.env + backend/.env"
for f in docker/.env backend/.env; do
  if grep -q '^API_DOCS_ENABLED=' "$f" 2>/dev/null; then
    sed -i 's/^API_DOCS_ENABLED=.*/API_DOCS_ENABLED=true/' "$f"
  else
    printf '\nAPI_DOCS_ENABLED=true\n' >> "$f"
  fi
done

echo "==> 3) Clear bootstrap caches"
rm -f backend/bootstrap/cache/config.php \
  backend/bootstrap/cache/services.php \
  backend/bootstrap/cache/routes.php \
  backend/bootstrap/cache/routes-v7.php 2>/dev/null || true

echo "==> 4) Recreate app (loads new bootstrap + entrypoint APP_KEY check)"
cd docker
docker compose up -d --force-recreate --no-deps app
sleep 5

docker compose exec -T app php artisan config:clear || true
docker compose exec -T app php artisan route:clear || true

echo "==> 5) Verify"
docker compose exec -T app grep -E '^APP_KEY=base64:' .env | sed 's/=.*/=[ok]/' || {
  echo "ERROR: APP_KEY still missing inside container" >&2
  exit 1
}
docker compose exec -T app printenv API_DOCS_ENABLED || true
docker compose exec -T app php artisan route:list --path=documentation || true

echo "==> 6) Internal probe"
docker compose exec -T app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$req = Illuminate\Http\Request::create("/api/documentation", "GET", [], [], [], ["HTTP_ACCEPT"=>"text/html"]);
$res = $kernel->handle($req);
echo "status=".$res->getStatusCode()." type=".$res->headers->get("Content-Type").PHP_EOL;
echo substr((string)$res->getContent(), 0, 120).PHP_EOL;
' 2>&1 || true

echo "==> 7) Public probe"
curl -sI "https://${DOMAIN}/api/documentation" | head -n 8 || true
echo
curl -s "https://${DOMAIN}/api/documentation" | head -c 150 || true
echo
echo "Done."
