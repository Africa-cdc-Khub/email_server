#!/usr/bin/env bash
# Diagnose + fix /api/documentation Server Error on production.
# API_DOCS_ENABLED=true only registers the route — this finds the real 500.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOMAIN="${DOMAIN:-notifications.africacdc.org}"

cd "$ROOT"

echo "==> Confirm API_DOCS_ENABLED (already true is fine)"
grep -E '^API_DOCS_ENABLED=' docker/.env || echo "(not in docker/.env)"
cd docker
echo -n "container: "
docker compose exec -T app printenv API_DOCS_ENABLED || echo "(unset)"

echo "==> Clear cached config/routes (stale cache is a common 500 cause)"
docker compose exec -T app php artisan config:clear || true
docker compose exec -T app php artisan route:clear || true
docker compose exec -T app php artisan view:clear || true
docker compose exec -T app rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/routes.php 2>/dev/null || true

echo "==> Does resources/swagger/ui.html exist in the container?"
docker compose exec -T app ls -la resources/swagger/ui.html 2>&1 || true

echo "==> Route list"
docker compose exec -T app php artisan route:list --path=documentation 2>&1 || true

echo "==> Hit /api/documentation INSIDE the container (shows real status/body)"
docker compose exec -T app php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
try {
  $request = Illuminate\Http\Request::create("/api/documentation", "GET", [], [], [], ["HTTP_ACCEPT" => "text/html"]);
  $response = $kernel->handle($request);
  echo "status=".$response->getStatusCode().PHP_EOL;
  echo "content-type=".$response->headers->get("Content-Type").PHP_EOL;
  echo substr((string) $response->getContent(), 0, 400).PHP_EOL;
} catch (Throwable $e) {
  echo "EXCEPTION: ".$e->getMessage().PHP_EOL;
  echo $e->getFile().":".$e->getLine().PHP_EOL;
}
' 2>&1 || true

echo "==> Last exception from laravel.log"
docker compose exec -T app sh -c '
  if [ -f storage/logs/laravel.log ]; then
    grep -A 30 "local.ERROR\|production.ERROR\|documentation\|ApiDocumentation" storage/logs/laravel.log | tail -n 60
    echo "---- tail ----"
    tail -n 40 storage/logs/laravel.log
  else
    echo "(no storage/logs/laravel.log — check permissions on bind mount)"
    ls -la storage/logs 2>&1 || true
  fi
' 2>&1 || true

echo "==> Public probe"
curl -sI "https://${DOMAIN}/api/documentation" | head -n 12 || true
echo
curl -s "https://${DOMAIN}/api/documentation" | head -c 200 || true
echo
