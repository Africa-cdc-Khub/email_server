#!/usr/bin/env bash
# Email Server — single production deploy script
#
# Secrets are NEVER committed. Pass them as CLI flags, environment variables,
# or an external --env-file that stays outside the repo (or is gitignored).
#
# Example:
#   ./setup.sh \
#     --domain=notifications.africacdc.org \
#     --admin-email=andrewa@africacdc.org \
#     --admin-password='...' \
#     --db-password='...' \
#     --mysql-root-password='...' \
#     --jwt-secret="$(openssl rand -base64 48)" \
#     --certbot-email=andrewa@africacdc.org \
#     --exchange-tenant-id=... \
#     --exchange-client-id=... \
#     --exchange-client-secret=...
#
# Or:
#   cp deploy/production.secrets.env.example /etc/email-server/secrets.env
#   # edit secrets (chmod 600)
#   ./setup.sh --env-file=/etc/email-server/secrets.env

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# ---------------------------------------------------------------------------
# Defaults (non-secret)
# ---------------------------------------------------------------------------
DOMAIN="${DOMAIN:-notifications.africacdc.org}"
ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
JWT_SECRET="${JWT_SECRET:-}"
JWT_TTL="${JWT_TTL:-60}"
DATA_PATH="${EMAIL_SERVER_DATA_PATH:-/home/email_serverdata}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-}"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-Africa CDC Notifications}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
EXCHANGE_TENANT_ID="${EXCHANGE_TENANT_ID:-}"
EXCHANGE_CLIENT_ID="${EXCHANGE_CLIENT_ID:-}"
EXCHANGE_CLIENT_SECRET="${EXCHANGE_CLIENT_SECRET:-}"
EXCHANGE_AUTH_METHOD="${EXCHANGE_AUTH_METHOD:-client_credentials}"
EXCHANGE_SCOPE="${EXCHANGE_SCOPE:-https://graph.microsoft.com/.default}"
INTEGRATION_CLIENT_SECRET="${INTEGRATION_CLIENT_SECRET:-}"
QUEUE_SCALE="${QUEUE_SCALE:-1}"
MYSQL_HOST_PORT="${MYSQL_HOST_PORT:-3309}"
FORCE_VENDOR_REINSTALL="${FORCE_VENDOR_REINSTALL:-false}"
RESET_MYSQL="${RESET_MYSQL:-false}"
RUN_SEEDER="${RUN_SEEDER:-true}"
SKIP_SSL="${SKIP_SSL:-false}"
SKIP_FRONTEND_BUILD="${SKIP_FRONTEND_BUILD:-false}"
FRONTEND_BUILD="${FRONTEND_BUILD:-auto}"
SKIP_NGINX="${SKIP_NGINX:-false}"
APP_ENV="${APP_ENV:-production}"
APP_DEBUG="${APP_DEBUG:-false}"
ENV_FILE=""

usage() {
  cat <<'EOF'
Usage: ./setup.sh [options]

Required (or provide via --env-file / environment):
  --domain=HOST                 Public hostname (default: notifications.africacdc.org)
  --admin-email=EMAIL           First admin login email
  --admin-password=SECRET       First admin password
  --db-password=SECRET          MySQL app user password
  --mysql-root-password=SECRET  MySQL root password
  --jwt-secret=SECRET           JWT signing secret (>=32 chars). Auto-generated if omitted.
  --certbot-email=EMAIL         Let's Encrypt registration email (required unless --skip-ssl)

Optional:
  --env-file=PATH               Load KEY=VALUE secrets from an external file (not committed)
  --data-path=PATH              Persistent MySQL/Redis path (default: /home/email_serverdata)
  --mail-from-address=EMAIL     Default From address (default: notifications@<domain>)
  --mail-from-name=NAME         Default From display name
  --exchange-tenant-id=ID
  --exchange-client-id=ID
  --exchange-client-secret=SECRET
  --integration-client-secret=SECRET  Seeded staff-portal integration secret (auto if omitted)
  --queue-scale=N               docker compose --scale queue=N (default: 1)
  --mysql-host-port=PORT        Host port for MySQL (default: 3309; avoid host :3306 clashes)
  --force-vendor                Wipe backend/vendor and reinstall via composer
  --reset-mysql                 Wipe MySQL data dir and re-init with current passwords (DESTROYS DB DATA)
  --run-seeder=true|false       Seed admin/providers on start (default: true)
  --skip-ssl                    Skip Certbot TLS setup
  --skip-frontend-build         Skip frontend build (requires existing frontend/dist)
  --frontend-build=auto|docker|host
                                How to build UI (default: auto = host npm if present, else Docker node image)
  --skip-nginx                  Skip installing host Nginx site
  -h, --help                    Show this help

Environment variables with the same names (DOMAIN, ADMIN_PASSWORD, …) are also accepted.
EOF
}

log()  { printf '==> %s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

run_root() {
  if [[ "${EUID}" -eq 0 ]]; then
    "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo "$@"
  else
    die "Root privileges required for: $*"
  fi
}

gen_secret() {
  local bytes="${1:-48}"
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 "$bytes" | tr -d '\n'
  else
    head -c "$bytes" /dev/urandom | base64 | tr -d '\n'
  fi
}

# Load an external KEY=VALUE file without executing it.
# Always applies values from the file (caller loads this before CLI overrides).
load_env_file() {
  local file="$1"
  [[ -f "$file" ]] || die "Env file not found: $file"
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
    if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]]; then
      local key="${BASH_REMATCH[1]}"
      local val="${BASH_REMATCH[2]}"
      if [[ "$val" =~ ^\"(.*)\"$ ]] || [[ "$val" =~ ^\'(.*)\'$ ]]; then
        val="${BASH_REMATCH[1]}"
      fi
      printf -v "$key" '%s' "$val"
      export "$key"
    fi
  done < "$file"
}

write_env_file() {
  local target="$1"
  shift
  umask 077
  {
    printf '# Generated by setup.sh on %s — DO NOT COMMIT\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    for pair in "$@"; do
      printf '%s\n' "$pair"
    done
  } > "$target"
  chmod 600 "$target"
}

# ---------------------------------------------------------------------------
# Parse args (env-file first, then CLI overrides)
# ---------------------------------------------------------------------------
for arg in "$@"; do
  case "$arg" in
    --env-file=*) ENV_FILE="${arg#*=}" ;;
  esac
done

if [[ -n "$ENV_FILE" ]]; then
  log "Loading secrets from $ENV_FILE"
  load_env_file "$ENV_FILE"
  # Map common aliases from the secrets file
  DOMAIN="${DOMAIN:-notifications.africacdc.org}"
  ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
  ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
  DB_PASSWORD="${DB_PASSWORD:-}"
  MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
  JWT_SECRET="${JWT_SECRET:-}"
  JWT_TTL="${JWT_TTL:-60}"
  DATA_PATH="${EMAIL_SERVER_DATA_PATH:-${DATA_PATH:-/home/email_serverdata}}"
  MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-}"
  MAIL_FROM_NAME="${MAIL_FROM_NAME:-Africa CDC Notifications}"
  CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
  EXCHANGE_TENANT_ID="${EXCHANGE_TENANT_ID:-}"
  EXCHANGE_CLIENT_ID="${EXCHANGE_CLIENT_ID:-}"
  EXCHANGE_CLIENT_SECRET="${EXCHANGE_CLIENT_SECRET:-}"
  INTEGRATION_CLIENT_SECRET="${INTEGRATION_CLIENT_SECRET:-}"
  QUEUE_SCALE="${QUEUE_SCALE:-1}"
  MYSQL_HOST_PORT="${MYSQL_HOST_PORT:-3309}"
  FORCE_VENDOR_REINSTALL="${FORCE_VENDOR_REINSTALL:-false}"
  RESET_MYSQL="${RESET_MYSQL:-false}"
  RUN_SEEDER="${RUN_SEEDER:-true}"
  SKIP_SSL="${SKIP_SSL:-false}"
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --env-file=*) ;; # already processed
    --domain=*) DOMAIN="${1#*=}" ;;
    --admin-email=*) ADMIN_EMAIL="${1#*=}" ;;
    --admin-password=*) ADMIN_PASSWORD="${1#*=}" ;;
    --db-password=*) DB_PASSWORD="${1#*=}" ;;
    --mysql-root-password=*) MYSQL_ROOT_PASSWORD="${1#*=}" ;;
    --jwt-secret=*) JWT_SECRET="${1#*=}" ;;
    --jwt-ttl=*) JWT_TTL="${1#*=}" ;;
    --data-path=*) DATA_PATH="${1#*=}" ;;
    --mail-from-address=*) MAIL_FROM_ADDRESS="${1#*=}" ;;
    --mail-from-name=*) MAIL_FROM_NAME="${1#*=}" ;;
    --certbot-email=*) CERTBOT_EMAIL="${1#*=}" ;;
    --exchange-tenant-id=*) EXCHANGE_TENANT_ID="${1#*=}" ;;
    --exchange-client-id=*) EXCHANGE_CLIENT_ID="${1#*=}" ;;
    --exchange-client-secret=*) EXCHANGE_CLIENT_SECRET="${1#*=}" ;;
    --integration-client-secret=*) INTEGRATION_CLIENT_SECRET="${1#*=}" ;;
    --queue-scale=*) QUEUE_SCALE="${1#*=}" ;;
    --mysql-host-port=*) MYSQL_HOST_PORT="${1#*=}" ;;
    --force-vendor) FORCE_VENDOR_REINSTALL=true ;;
    --reset-mysql) RESET_MYSQL=true ;;
    --run-seeder=*) RUN_SEEDER="${1#*=}" ;;
    --skip-ssl) SKIP_SSL=true ;;
    --skip-frontend-build) SKIP_FRONTEND_BUILD=true ;;
    --frontend-build=*) FRONTEND_BUILD="${1#*=}" ;;
    --skip-nginx) SKIP_NGINX=true ;;
    *) die "Unknown option: $1 (try --help)" ;;
  esac
  shift
done

# load_env_file always assigns exported vars; refresh locals from exports when file was used
if [[ -n "$ENV_FILE" ]]; then
  : # locals already set in the CLI loop for overrides; fill remaining from environment
  DOMAIN="${DOMAIN}"
  ADMIN_EMAIL="${ADMIN_EMAIL}"
  ADMIN_PASSWORD="${ADMIN_PASSWORD}"
  DB_PASSWORD="${DB_PASSWORD}"
  MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD}"
  JWT_SECRET="${JWT_SECRET}"
fi

[[ -n "$MAIL_FROM_ADDRESS" ]] || MAIL_FROM_ADDRESS="notifications@${DOMAIN}"
[[ -n "$CERTBOT_EMAIL" ]] || CERTBOT_EMAIL="$ADMIN_EMAIL"
[[ -n "$JWT_SECRET" ]] || JWT_SECRET="$(gen_secret 48)"
[[ -n "$INTEGRATION_CLIENT_SECRET" ]] || INTEGRATION_CLIENT_SECRET="$(gen_secret 32)"

[[ -n "$ADMIN_PASSWORD" ]] || die "Missing --admin-password (or ADMIN_PASSWORD / --env-file)"
[[ -n "$DB_PASSWORD" ]] || die "Missing --db-password (or DB_PASSWORD / --env-file)"
[[ -n "$MYSQL_ROOT_PASSWORD" ]] || die "Missing --mysql-root-password (or MYSQL_ROOT_PASSWORD / --env-file)"
[[ "${#JWT_SECRET}" -ge 32 ]] || die "JWT_SECRET must be at least 32 characters"
[[ "$SKIP_SSL" == "true" ]] || [[ -n "$CERTBOT_EMAIL" ]] || die "Missing --certbot-email (or use --skip-ssl)"

APP_URL="https://${DOMAIN}"
FRONTEND_URL="https://${DOMAIN}"

need_cmd docker
need_cmd openssl
if [[ "$SKIP_NGINX" != "true" ]]; then
  need_cmd nginx
fi
if [[ "$SKIP_SSL" != "true" ]]; then
  need_cmd certbot
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose -f "$ROOT/docker/docker-compose.yml" --env-file "$ROOT/docker/.env")
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose -f "$ROOT/docker/docker-compose.yml" --env-file "$ROOT/docker/.env")
else
  die "Docker Compose is required"
fi

# ---------------------------------------------------------------------------
# 1. Persistent data dirs
# ---------------------------------------------------------------------------
log "Creating data directories under $DATA_PATH"
if mkdir -p "$DATA_PATH/mysql" "$DATA_PATH/redis" 2>/dev/null; then
  :
else
  run_root mkdir -p "$DATA_PATH/mysql" "$DATA_PATH/redis"
fi

# ---------------------------------------------------------------------------
# 2. Write docker/.env and backend/.env (gitignored — never commit)
# ---------------------------------------------------------------------------
log "Writing docker/.env (mode 600)"
write_env_file "$ROOT/docker/.env" \
  "APP_ENV=${APP_ENV}" \
  "APP_DEBUG=${APP_DEBUG}" \
  "RUN_SEEDER=${RUN_SEEDER}" \
  "EMAIL_SERVER_DATA_PATH=${DATA_PATH}" \
  "APP_URL=${APP_URL}" \
  "FRONTEND_URL=${FRONTEND_URL}" \
  "API_HOST_PORT=8089" \
  "MYSQL_HOST_PORT=${MYSQL_HOST_PORT}" \
  "REDIS_CLIENT=predis" \
  "ADMIN_EMAIL=${ADMIN_EMAIL}" \
  "ADMIN_PASSWORD=${ADMIN_PASSWORD}" \
  "ADMIN_RESET_PASSWORD=true" \
  "DB_PASSWORD=${DB_PASSWORD}" \
  "MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}" \
  "JWT_SECRET=${JWT_SECRET}" \
  "JWT_TTL=${JWT_TTL}" \
  "INTEGRATION_CLIENT_SECRET=${INTEGRATION_CLIENT_SECRET}"

log "Writing backend/.env (mode 600)"
if [[ ! -f "$ROOT/backend/.env.example" ]]; then
  die "backend/.env.example missing"
fi

# Capture APP_KEY before we overwrite .env from the example template.
PRESERVED_APP_KEY=""
if [[ -f "$ROOT/backend/.env" ]]; then
  PRESERVED_APP_KEY="$(grep -E '^APP_KEY=base64:' "$ROOT/backend/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true)"
fi

# Start from example, then overlay production values.
umask 077
cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
chmod 600 "$ROOT/backend/.env"

set_backend_env() {
  local key="$1"
  local value="$2"
  # Escape backslashes and double quotes for .env double-quoted values
  local escaped="${value//\\/\\\\}"
  escaped="${escaped//\"/\\\"}"
  local line="${key}=\"${escaped}\""

  if grep -q "^${key}=" "$ROOT/backend/.env"; then
    awk -v k="$key" -v line="$line" '
      BEGIN { done=0 }
      index($0, k "=") == 1 {
        print line
        done=1
        next
      }
      { print }
      END { if (!done) print line }
    ' "$ROOT/backend/.env" > "$ROOT/backend/.env.tmp"
    mv "$ROOT/backend/.env.tmp" "$ROOT/backend/.env"
    chmod 600 "$ROOT/backend/.env"
  else
    printf '%s\n' "$line" >> "$ROOT/backend/.env"
  fi
}

set_backend_env "APP_NAME" "Email Server"
set_backend_env "APP_ENV" "$APP_ENV"
set_backend_env "APP_DEBUG" "$APP_DEBUG"
set_backend_env "APP_URL" "$APP_URL"
set_backend_env "FRONTEND_URL" "$FRONTEND_URL"
set_backend_env "DB_HOST" "mysql"
set_backend_env "DB_DATABASE" "email_server"
set_backend_env "DB_USERNAME" "email_server"
set_backend_env "DB_PASSWORD" "$DB_PASSWORD"
set_backend_env "REDIS_CLIENT" "predis"
set_backend_env "MAIL_MAILER" "exchange"
set_backend_env "MAIL_FROM_ADDRESS" "$MAIL_FROM_ADDRESS"
set_backend_env "MAIL_FROM_NAME" "$MAIL_FROM_NAME"
set_backend_env "EXCHANGE_TENANT_ID" "$EXCHANGE_TENANT_ID"
set_backend_env "EXCHANGE_CLIENT_ID" "$EXCHANGE_CLIENT_ID"
set_backend_env "EXCHANGE_CLIENT_SECRET" "$EXCHANGE_CLIENT_SECRET"
set_backend_env "EXCHANGE_AUTH_METHOD" "$EXCHANGE_AUTH_METHOD"
set_backend_env "EXCHANGE_SCOPE" "$EXCHANGE_SCOPE"
set_backend_env "ADMIN_EMAIL" "$ADMIN_EMAIL"
set_backend_env "ADMIN_PASSWORD" "$ADMIN_PASSWORD"
set_backend_env "JWT_SECRET" "$JWT_SECRET"
set_backend_env "JWT_TTL" "$JWT_TTL"
set_backend_env "INTEGRATION_CLIENT_SECRET" "$INTEGRATION_CLIENT_SECRET"
set_backend_env "SANCTUM_STATEFUL_DOMAINS" "localhost,localhost:3006,127.0.0.1"
# Intentionally omit public DOMAIN — we use Bearer tokens, not Sanctum cookie SPA auth.

# Preserve or create APP_KEY before containers start (artisan cannot boot without it).
if [[ -n "$PRESERVED_APP_KEY" ]]; then
  log "Preserving existing APP_KEY"
  set_backend_env "APP_KEY" "$PRESERVED_APP_KEY"
else
  log "Generating new APP_KEY for backend/.env"
  set_backend_env "APP_KEY" "base64:$(openssl rand -base64 32 | tr -d '\n')"
fi

# ---------------------------------------------------------------------------
# 3. Frontend build (host npm OR Docker node — npm is NOT required on the server)
# ---------------------------------------------------------------------------
build_frontend_host() {
  need_cmd npm
  log "Building frontend with host npm"
  (
    cd "$ROOT/frontend"
    npm ci --legacy-peer-deps
    npm run build
  )
}

build_frontend_docker() {
  local node_image="${FRONTEND_NODE_IMAGE:-node:22-alpine}"
  log "Building frontend with Docker ($node_image) — no host npm required"
  docker run --rm \
    -v "$ROOT/frontend:/app" \
    -w /app \
    "$node_image" \
    sh -c "npm ci --legacy-peer-deps && npm run build"

  # Ensure dist is readable by the nginx container user
  if [[ -d "$ROOT/frontend/dist" ]]; then
    chmod -R a+rX "$ROOT/frontend/dist" || true
  fi
}

if [[ "$SKIP_FRONTEND_BUILD" != "true" ]]; then
  case "$FRONTEND_BUILD" in
    host)
      build_frontend_host
      ;;
    docker)
      build_frontend_docker
      ;;
    auto|"")
      if command -v npm >/dev/null 2>&1; then
        build_frontend_host
      else
        warn "npm not found on host — building frontend via Docker node image"
        build_frontend_docker
      fi
      ;;
    *)
      die "Invalid --frontend-build=$FRONTEND_BUILD (use auto|docker|host)"
      ;;
  esac
  [[ -d "$ROOT/frontend/dist" ]] || die "frontend/dist missing after build"
else
  warn "Skipping frontend build"
  [[ -d "$ROOT/frontend/dist" ]] || die "frontend/dist missing — run without --skip-frontend-build"
fi

# ---------------------------------------------------------------------------
# 4. Docker stack
# ---------------------------------------------------------------------------
API_HOST_PORT="${API_HOST_PORT:-8089}"
API_HEALTH_URL="http://127.0.0.1:${API_HOST_PORT}/api/v1/health"
API_UP_URL="http://127.0.0.1:${API_HOST_PORT}/up"

ensure_backend_vendor() {
  # Always run before compose up. Incomplete vendor/ (autoload present but packages
  # missing) has caused production 500s — never trust a partial tree.
  local vendor_dir="$ROOT/backend/vendor"
  local autoload="$vendor_dir/autoload.php"
  local sentinel="$vendor_dir/symfony/deprecation-contracts/function.php"
  local force="${FORCE_VENDOR_REINSTALL:-false}"

  vendor_is_healthy() {
    [[ -f "$autoload" ]] || return 1
    [[ -f "$sentinel" ]] || return 1
    [[ -f "$vendor_dir/predis/predis/composer.json" ]] || return 1
    [[ -d "$vendor_dir/laravel/framework" ]] || return 1
    docker run --rm -v "$ROOT/backend:/app" -w /app composer:2 \
      php -r 'require "vendor/autoload.php"; echo "ok";' >/dev/null 2>&1
  }

  run_composer_install() {
    log "Running: composer install --no-dev --optimize-autoloader"
    docker run --rm \
      -v "$ROOT/backend:/app" \
      -w /app \
      composer:2 \
      composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
  }

  if [[ "$force" == "true" ]] || ! vendor_is_healthy; then
    if [[ -d "$vendor_dir" ]]; then
      warn "Removing incomplete/stale backend/vendor before reinstall"
      rm -rf "$vendor_dir"
    else
      log "backend/vendor missing — installing PHP dependencies"
    fi
    run_composer_install
  else
    log "PHP vendor/ healthy — refreshing with composer install"
    run_composer_install
  fi

  [[ -f "$autoload" ]] || die "composer install did not create vendor/autoload.php"
  [[ -f "$sentinel" ]] || die "composer install incomplete (missing symfony/deprecation-contracts)"
  [[ -f "$vendor_dir/predis/predis/composer.json" ]] || die "composer install incomplete (missing predis/predis)"
  vendor_is_healthy || die "vendor/autoload.php still fails to load after composer install"
  log "PHP vendor/ OK"
}

sync_backend_db_password() {
  # Keep Laravel .env DB_PASSWORD in sync with docker/.env (avoids volume password drift)
  local db_pass
  db_pass="$(grep -E '^DB_PASSWORD=' "$ROOT/docker/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
  [[ -n "$db_pass" ]] || return 0
  if [[ -f "$ROOT/backend/.env" ]]; then
    if grep -q '^DB_PASSWORD=' "$ROOT/backend/.env"; then
      awk -v p="$db_pass" 'BEGIN{done=0} /^DB_PASSWORD=/{print "DB_PASSWORD=\"" p "\""; done=1; next} {print} END{if(!done) print "DB_PASSWORD=\"" p "\""}' \
        "$ROOT/backend/.env" > "$ROOT/backend/.env.tmp"
      mv "$ROOT/backend/.env.tmp" "$ROOT/backend/.env"
      chmod 600 "$ROOT/backend/.env"
    else
      printf 'DB_PASSWORD="%s"\n' "$db_pass" >> "$ROOT/backend/.env"
    fi
    log "Synced DB_PASSWORD into backend/.env"
  fi
}

# Read a KEY from docker/.env (strips surrounding quotes)
docker_env_get() {
  local key="$1"
  grep -E "^${key}=" "$ROOT/docker/.env" 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

sql_escape() {
  # Escape \ and ' for MySQL string literals
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\'/\\\'}"
  printf '%s' "$s"
}

wait_for_mysql_healthy() {
  local i
  log "Waiting for MySQL container to be healthy"
  for i in $(seq 1 36); do
    if "${COMPOSE[@]}" ps mysql 2>/dev/null | grep -qi 'healthy'; then
      return 0
    fi
    sleep 5
  done
  warn "MySQL did not report healthy — continuing anyway"
  return 0
}

# Must test with the SAME credentials Laravel uses (container env + backend/.env).
# Do NOT inject a different password here — that caused false "auth OK" while login 500'd.
mysql_app_auth_ok() {
  "${COMPOSE[@]}" exec -T app php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    try {
      Illuminate\Support\Facades\DB::connection()->getPdo();
      Illuminate\Support\Facades\DB::select("select 1");
      exit(0);
    } catch (Throwable $e) {
      fwrite(STDERR, $e->getMessage() . PHP_EOL);
      exit(1);
    }
  ' >/dev/null 2>&1
}

mysql_root_auth_ok() {
  local root_pass="$1"
  # Real query — mysqladmin ping can be misleading with auth failures
  "${COMPOSE[@]}" exec -T -e MYSQL_PWD="$root_pass" mysql \
    mysql -u root -h 127.0.0.1 -e 'SELECT 1;' >/dev/null 2>&1
}

reset_mysql_app_user() {
  local root_pass="$1"
  local esc_pass="$2"
  local hosts host

  "${COMPOSE[@]}" exec -T -e MYSQL_PWD="$root_pass" mysql \
    mysql -u root -h 127.0.0.1 -e "
CREATE DATABASE IF NOT EXISTS email_server CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'email_server'@'%' IDENTIFIED BY '${esc_pass}';
ALTER USER 'email_server'@'%' IDENTIFIED BY '${esc_pass}';
GRANT ALL PRIVILEGES ON email_server.* TO 'email_server'@'%';
FLUSH PRIVILEGES;
" || return 1

  # Sync password for every host the user already exists on (localhost, %, etc.)
  hosts="$("${COMPOSE[@]}" exec -T -e MYSQL_PWD="$root_pass" mysql \
    mysql -u root -h 127.0.0.1 -N -e "SELECT Host FROM mysql.user WHERE User='email_server';" 2>/dev/null | tr -d '\r')"
  while IFS= read -r host; do
    [[ -z "$host" ]] && continue
    "${COMPOSE[@]}" exec -T -e MYSQL_PWD="$root_pass" mysql \
      mysql -u root -h 127.0.0.1 -e "ALTER USER 'email_server'@'${host}' IDENTIFIED BY '${esc_pass}'; GRANT ALL PRIVILEGES ON email_server.* TO 'email_server'@'${host}';" \
      || warn "Could not ALTER email_server@${host}"
  done <<< "$hosts"

  "${COMPOSE[@]}" exec -T -e MYSQL_PWD="$root_pass" mysql \
    mysql -u root -h 127.0.0.1 -e "FLUSH PRIVILEGES;" || true
}

# Wipe bind-mounted MySQL data and recreate the container so MYSQL_* env
# passwords from docker/.env are applied on first initialization.
reset_mysql_data_volume() {
  local data_path="${EMAIL_SERVER_DATA_PATH:-$DATA_PATH}"
  # Prefer path written into docker/.env (what the container actually mounts)
  local env_path
  env_path="$(docker_env_get EMAIL_SERVER_DATA_PATH || true)"
  [[ -n "$env_path" ]] && data_path="$env_path"

  log "RESET MYSQL: stopping mysql and wiping ${data_path}/mysql (DESTROYS DB DATA)"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" stop mysql || true
    "${COMPOSE[@]}" rm -f mysql || true
  )
  if [[ -d "${data_path}/mysql" ]]; then
    run_root rm -rf "${data_path}/mysql"
  fi
  run_root mkdir -p "${data_path}/mysql"
  # MySQL image needs write access as mysql uid (typically 999)
  run_root chown -R 999:999 "${data_path}/mysql" 2>/dev/null || true

  log "Starting fresh MySQL with passwords from docker/.env"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate mysql
  )
  wait_for_mysql_healthy

  local db_pass root_pass tries
  db_pass="$(docker_env_get DB_PASSWORD)"
  root_pass="$(docker_env_get MYSQL_ROOT_PASSWORD)"

  # First-boot init can take a while on empty datadir
  tries=1
  while [[ "$tries" -le 30 ]]; do
    if mysql_root_auth_ok "$root_pass"; then
      break
    fi
    tries=$((tries + 1))
    sleep 2
  done

  if ! mysql_root_auth_ok "$root_pass"; then
    die "Fresh MySQL still rejects MYSQL_ROOT_PASSWORD — check docker/.env secrets match MYSQL_ROOT_PASSWORD used to create the container"
  fi
  if ! mysql_app_auth_ok; then
    warn "Fresh MySQL app user not ready yet — ensuring email_server@%"
    reset_mysql_app_user "$root_pass" "$(sql_escape "$db_pass")" || true
    sleep 3
    mysql_app_auth_ok || die "Fresh MySQL email_server auth still failing"
  fi

  # Recreate app/queue so container env matches docker/.env after a wipe
  log "Recreating app + queue after MySQL reset"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate --no-deps app
    "${COMPOSE[@]}" up -d --force-recreate --no-deps --scale "queue=${QUEUE_SCALE:-1}" queue
  )
  sleep 5
  mysql_app_auth_ok || die "Laravel still cannot connect to MySQL after app recreate"
  log "Fresh MySQL ready with current DB_PASSWORD / MYSQL_ROOT_PASSWORD"
}

# MySQL only applies MYSQL_PASSWORD on first volume init. If secrets change later,
# the volume keeps old passwords. Prefer ALTER via root; if root also mismatches,
# automatically wipe + re-init (failed first-deploy recovery).
sync_mysql_volume_password() {
  local db_pass root_pass esc_pass
  db_pass="$(docker_env_get DB_PASSWORD)"
  root_pass="$(docker_env_get MYSQL_ROOT_PASSWORD)"
  [[ -n "$db_pass" ]] || die "DB_PASSWORD missing from docker/.env"
  [[ -n "$root_pass" ]] || die "MYSQL_ROOT_PASSWORD missing from docker/.env"

  if [[ "$RESET_MYSQL" == "true" ]]; then
    reset_mysql_data_volume
    sync_backend_db_password
    log "Running migrations after MySQL reset"
    "${COMPOSE[@]}" exec -T app php artisan migrate --force \
      || warn "migrate still failing — check app logs"
    return 0
  fi

  wait_for_mysql_healthy

  # Ensure app container is up enough for the PDO network check
  if ! "${COMPOSE[@]}" ps app 2>/dev/null | grep -qi 'Up'; then
    warn "App container not Up yet — waiting briefly for network auth check"
    sleep 5
  fi

  if mysql_app_auth_ok; then
    log "MySQL app user auth OK (Laravel → mysql:3306)"
    return 0
  fi

  warn "Laravel cannot connect to MySQL with current DB_PASSWORD"

  if mysql_root_auth_ok "$root_pass"; then
    warn "Resetting email_server via root to match DB_PASSWORD"
    esc_pass="$(sql_escape "$db_pass")"
    if reset_mysql_app_user "$root_pass" "$esc_pass"; then
      sync_backend_db_password
      (
        cd "$ROOT/docker"
        "${COMPOSE[@]}" up -d --force-recreate --no-deps app
      )
      sleep 4
      if mysql_app_auth_ok; then
        log "MySQL email_server@% password reset to match DB_PASSWORD"
        log "Running migrations after MySQL password sync"
        "${COMPOSE[@]}" exec -T app php artisan migrate --force \
          || warn "migrate still failing — check app logs"
        return 0
      fi
    fi
    warn "Root ALTER did not fix app auth"
  else
    warn "MYSQL_ROOT_PASSWORD also does not match the volume"
  fi

  # Last resort for broken first deploys: wipe datadir and re-init with current .env
  warn "Auto-wiping MySQL data volume and re-initializing with docker/.env passwords"
  warn "(passwords in secrets no longer match the old volume — this DESTROYS DB DATA)"
  RESET_MYSQL=true
  reset_mysql_data_volume
  sync_backend_db_password
  log "Running migrations after MySQL wipe"
  "${COMPOSE[@]}" exec -T app php artisan migrate --force \
    || warn "migrate still failing — check app logs"
}

app_is_crash_looping() {
  "${COMPOSE[@]}" ps 2>/dev/null | grep -E 'email-server-app' | grep -qi 'Restarting'
}

app_is_up() {
  "${COMPOSE[@]}" ps 2>/dev/null | grep -E 'email-server-app' | grep -qi 'Up' \
    && ! "${COMPOSE[@]}" ps 2>/dev/null | grep -E 'email-server-app' | grep -qi 'Restarting'
}

wait_for_api() {
  local max_attempts="${1:-45}"
  local i code nginx_recreated=0 queue_warned=0
  log "Waiting for API on :${API_HOST_PORT} (up to ~$((max_attempts * 2))s)"
  log "Liveness check: GET ${API_UP_URL}"

  for i in $(seq 1 "$max_attempts"); do
    # Only fail-fast on the API container — a bad queue must not abort /up checks
    if app_is_crash_looping; then
      warn "App container is crash-looping — dumping logs"
      "${COMPOSE[@]}" ps || true
      "${COMPOSE[@]}" logs app --tail 100 || true
      return 1
    fi

    if [[ "$queue_warned" -eq 0 ]] \
      && "${COMPOSE[@]}" ps 2>/dev/null | grep -E 'docker-queue|queue-' | grep -qi 'Restarting'; then
      warn "Queue is Restarting (does not block API health) — see: docker compose logs queue --tail 50"
      queue_warned=1
    fi

    code="$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 2 --max-time 5 "$API_UP_URL" 2>/dev/null || true)"
    code="$(printf '%s' "${code:-000}" | tr -cd '0-9')"
    # normalize 000000 -> 000
    if [[ "$code" =~ 000+$ ]]; then code="000"; fi
    if [[ ${#code} -gt 3 ]]; then code="${code: -3}"; fi
    [[ -z "$code" ]] && code="000"

    if [[ "$code" == "200" ]]; then
      # /up does NOT check MySQL — also require API health DB=ok before continuing
      health="$(curl -fsS --connect-timeout 2 --max-time 5 "$API_HEALTH_URL" 2>/dev/null || true)"
      if printf '%s' "$health" | grep -q '"database":{"status":"ok"}'; then
        log "API is up (attempt ${i}/${max_attempts}) — /up=200 and database ok"
        return 0
      fi
      if [[ "$i" -eq 1 ]] || (( i % 3 == 0 )); then
        warn "/up=200 but database not ok yet — Laravel may still be rejecting DB_PASSWORD"
      fi
    fi

    # App up but nginx still 000/502 — bounce nginx once
    if [[ "$nginx_recreated" -eq 0 ]] && app_is_up && [[ "$i" -ge 6 ]]; then
      warn "App is Up but /up=${code} — recreating nginx"
      "${COMPOSE[@]}" up -d --force-recreate --no-deps nginx || true
      nginx_recreated=1
      sleep 3
      continue
    fi

    if (( i % 3 == 0 )); then
      printf '    … still starting (%s/%s) /up=%s\n' "$i" "$max_attempts" "$code"
      "${COMPOSE[@]}" ps --format 'table {{.Name}}\t{{.Status}}' 2>/dev/null | sed 's/^/       /' || true
    fi
    sleep 2
  done

  warn "API liveness timed out on ${API_UP_URL} (or database still unhealthy)"
  "${COMPOSE[@]}" ps || true
  "${COMPOSE[@]}" logs app --tail 100 || true
  "${COMPOSE[@]}" logs nginx --tail 40 || true
  "${COMPOSE[@]}" logs queue --tail 40 || true
  curl -sS "$API_HEALTH_URL" || true
  echo
  return 1
}

log "Preparing backend vendor + DB password sync"
ensure_backend_vendor
sync_backend_db_password
rm -f "$ROOT/backend/bootstrap/cache/config.php" \
  "$ROOT/backend/bootstrap/cache/routes-v7.php" \
  "$ROOT/backend/bootstrap/cache/routes.php" 2>/dev/null || true

log "Starting Docker stack"
(
  cd "$ROOT/docker"
  RUN_SEEDER=false "${COMPOSE[@]}" up -d --build --scale "queue=${QUEUE_SCALE}"
)

# Align MySQL volume passwords with docker/.env BEFORE health/seed
sync_mysql_volume_password

if ! wait_for_api 45; then
  die "API did not become healthy.

Try these on the server:
  cd $ROOT/docker
  docker compose logs app --tail 100
  docker compose ps

If DB auth fails, either set DB_PASSWORD / MYSQL_ROOT_PASSWORD in docker/.env +
backend/.env to the ORIGINAL MySQL volume passwords, or reset the volume
(DESTROYS DATA):
  docker compose down
  sudo rm -rf ${DATA_PATH}/mysql/*
  docker compose up -d
"
fi

# Seed / ensure admin after API is alive
if [[ "$RUN_SEEDER" == "true" ]]; then
  log "Seeding database / ensuring admin user"
  if ! "${COMPOSE[@]}" exec -T \
    -e ADMIN_EMAIL="$ADMIN_EMAIL" \
    -e ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    -e ADMIN_RESET_PASSWORD=true \
    -e RUN_SEEDER=true \
    app php artisan db:seed --force; then
    warn "db:seed failed — trying direct admin upsert"
    if ! "${COMPOSE[@]}" exec -T \
      -e ADMIN_EMAIL="$ADMIN_EMAIL" \
      -e ADMIN_PASSWORD="$ADMIN_PASSWORD" \
      app php artisan tinker --execute="
\$email = getenv('ADMIN_EMAIL');
\$pass = getenv('ADMIN_PASSWORD');
\$u = App\Models\User::query()->updateOrCreate(
  ['email' => \$email],
  [
    'name' => 'Super Admin',
    'password' => Illuminate\Support\Facades\Hash::make(\$pass),
    'is_admin' => true,
    'is_active' => true,
  ]
);
echo 'admin='.\$u->email.PHP_EOL;
"; then
      die "Could not seed/upsert admin — MySQL auth or migrate likely still failing.
Re-run with matching DB_PASSWORD/MYSQL_ROOT_PASSWORD, or:
  cd $ROOT && ./setup.sh --env-file=... --force-vendor
"
    fi
  fi
fi

# Disable reseed for subsequent boots
if [[ "$RUN_SEEDER" == "true" ]]; then
  log "Setting RUN_SEEDER=false for subsequent starts"
  awk 'BEGIN{done=0} /^RUN_SEEDER=/{print "RUN_SEEDER=false"; done=1; next} {print} END{if(!done) print "RUN_SEEDER=false"}' \
    "$ROOT/docker/.env" > "$ROOT/docker/.env.tmp"
  mv "$ROOT/docker/.env.tmp" "$ROOT/docker/.env"
  chmod 600 "$ROOT/docker/.env"
  awk 'BEGIN{done=0} /^ADMIN_RESET_PASSWORD=/{print "ADMIN_RESET_PASSWORD=false"; done=1; next} {print} END{if(!done) print "ADMIN_RESET_PASSWORD=false"}' \
    "$ROOT/docker/.env" > "$ROOT/docker/.env.tmp"
  mv "$ROOT/docker/.env.tmp" "$ROOT/docker/.env"
  chmod 600 "$ROOT/docker/.env"
fi

# ---------------------------------------------------------------------------
# 5. Host Nginx
# ---------------------------------------------------------------------------
if [[ "$SKIP_NGINX" != "true" ]]; then
  log "Installing Nginx site for ${DOMAIN}"
  SRC="$ROOT/deploy/configs/nginx-notifications.africacdc.org.conf"
  TMP="$(mktemp)"
  sed "s/notifications\.africacdc\.org/${DOMAIN}/g" "$SRC" > "$TMP"
  run_root cp "$TMP" "/etc/nginx/sites-available/${DOMAIN}.conf"
  rm -f "$TMP"
  run_root ln -sfn "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"
  run_root nginx -t
  run_root systemctl reload nginx
else
  warn "Skipping Nginx site install"
fi

# ---------------------------------------------------------------------------
# 6. Certbot SSL
# ---------------------------------------------------------------------------
if [[ "$SKIP_SSL" != "true" ]]; then
  log "Issuing/installing SSL certificate with Certbot for ${DOMAIN}"
  run_root certbot --nginx \
    -d "$DOMAIN" \
    --agree-tos \
    --redirect \
    -m "$CERTBOT_EMAIL" \
    --non-interactive \
    --keep-until-expiring

  log "Verifying HTTPS"
  curl -fsSI "https://${DOMAIN}/api/v1/health" | head -n 1 || warn "HTTPS health check failed — DNS/firewall may need attention"

  # Browser login uses Origin: https://DOMAIN — must NOT be CSRF 419 / Server Error
  login_probe="$(curl -sS -o /tmp/email_server_login_probe.json -w '%{http_code}' \
    -X POST "https://${DOMAIN}/api/v1/admin/auth/login" \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -H "Origin: https://${DOMAIN}" \
    -H "Referer: https://${DOMAIN}/login" \
    -d '{"email":"probe@example.com","password":"invalid-password-probe"}' 2>/dev/null || true)"
  if [[ "$login_probe" == "422" ]] || [[ "$login_probe" == "401" ]]; then
    log "HTTPS login endpoint OK (HTTP ${login_probe} with browser Origin)"
  else
    warn "HTTPS login probe returned HTTP ${login_probe} (expected 422). Body:"
    cat /tmp/email_server_login_probe.json 2>/dev/null | head -c 400 || true
    echo
    warn "If you see CSRF / Server Error, ensure backend/bootstrap/app.php has no statefulApi() and restart app."
  fi

  run_root certbot renew --dry-run || warn "Certbot renew dry-run reported issues"
else
  warn "Skipping SSL (--skip-ssl)"
fi

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
cat <<EOF

========================================================================
 Email Server deploy complete
========================================================================
  Admin UI : https://${DOMAIN}
  API      : https://${DOMAIN}/api
  Health   : https://${DOMAIN}/api/v1/health

  Admin email : ${ADMIN_EMAIL}
  Admin pass  : (the value you passed — not printed)

  Secrets written locally (gitignored, mode 600):
    ${ROOT}/docker/.env
    ${ROOT}/backend/.env

  Data path:
    ${DATA_PATH}/{mysql,redis}

  Certbot certificate (if SSL enabled):
    /etc/letsencrypt/live/${DOMAIN}/fullchain.pem
    /etc/letsencrypt/live/${DOMAIN}/privkey.pem

Next steps:
  1. Sign in and enable 2FA
  2. Create/rotate integration secrets in the admin UI
  3. Keep secrets.env / .env files out of git
========================================================================
EOF
