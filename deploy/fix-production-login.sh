#!/usr/bin/env bash
# Fix production login failures caused by Postgres password drift / missing seed.
# Run on the server from the repo root:
#   ./deploy/fix-production-login.sh
#
# Optional:
#   ADMIN_PASSWORD='...' ./deploy/fix-production-login.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/docker"

if [[ ! -f .env ]]; then
  echo "ERROR: docker/.env missing" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a
source .env
set +a

echo "==> Ensuring POSTGRES_HOST_PORT is set (default 5433)"
if ! grep -q '^POSTGRES_HOST_PORT=' .env; then
  echo 'POSTGRES_HOST_PORT=5433' >> .env
fi

DATA_PATH="${EMAIL_SERVER_DATA_PATH:-/home/email_serverdata}"
DB_PASSWORD="${DB_PASSWORD:?DB_PASSWORD missing in docker/.env}"
ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"

echo "==> Recreating postgres/app/queue with current docker/.env"
docker compose up -d postgres
sleep 3

laravel_db_ok() {
  docker compose exec -T app php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::connection()->getPdo();
    Illuminate\Support\Facades\DB::select("select 1");
    echo "ok\n";
  ' 2>/dev/null
}

if ! laravel_db_ok | grep -q ok; then
  echo "==> Laravel cannot reach Postgres — wiping ${DATA_PATH}/postgres and re-initializing"
  docker compose stop postgres || true
  docker compose rm -f postgres || true
  sudo rm -rf "${DATA_PATH}/postgres"
  sudo mkdir -p "${DATA_PATH}/postgres"
  sudo chown -R 70:70 "${DATA_PATH}/postgres" || true
  docker compose up -d --force-recreate postgres
  echo "==> Waiting for Postgres healthy..."
  for _ in $(seq 1 36); do
    if docker compose ps postgres | grep -qi healthy; then
      break
    fi
    sleep 5
  done
fi

docker compose up -d --force-recreate --no-deps app queue
sleep 5

if ! laravel_db_ok | grep -q ok; then
  echo "ERROR: Laravel still cannot connect after Postgres recreate." >&2
  docker compose logs postgres --tail 40 || true
  docker compose logs app --tail 40 || true
  exit 1
fi

echo "==> Running migrations"
docker compose exec -T app php artisan migrate --force

echo "==> Seeding / ensuring admin"
if [[ -z "$ADMIN_PASSWORD" ]]; then
  echo "WARNING: ADMIN_PASSWORD empty — using value already in docker/.env via compose env" >&2
fi
docker compose exec -T \
  -e ADMIN_EMAIL="$ADMIN_EMAIL" \
  -e ADMIN_PASSWORD="${ADMIN_PASSWORD:-$ADMIN_PASSWORD}" \
  -e ADMIN_RESET_PASSWORD=true \
  app php artisan db:seed --force

echo "==> Done. Try logging in as ${ADMIN_EMAIL}"
