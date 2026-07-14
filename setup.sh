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
set_backend_env "SANCTUM_STATEFUL_DOMAINS" "${DOMAIN},localhost,localhost:3006,127.0.0.1"

# Ensure APP_KEY line exists (entrypoint generates if empty)
grep -q '^APP_KEY=' "$ROOT/backend/.env" || printf 'APP_KEY=\n' >> "$ROOT/backend/.env"

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
log "Starting Docker stack"
(
  cd "$ROOT/docker"
  "${COMPOSE[@]}" up -d --build --scale "queue=${QUEUE_SCALE}"
)

API_HOST_PORT="${API_HOST_PORT:-8089}"
API_HEALTH_URL="http://127.0.0.1:${API_HOST_PORT}/api/v1/health"
API_UP_URL="http://127.0.0.1:${API_HOST_PORT}/up"

wait_for_api() {
  local max_attempts="${1:-90}"
  local i code body
  log "Waiting for API on :${API_HOST_PORT} (up to ~$((max_attempts * 2))s)"

  for i in $(seq 1 "$max_attempts"); do
    # Prefer /up (Laravel liveness) — does not require DB/Redis yet
    code="$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 2 --max-time 5 "$API_UP_URL" 2>/dev/null || echo 000)"
    if [[ "$code" == "200" ]]; then
      # Then confirm JSON health when dependencies are up (200 or accept any HTTP response with body)
      body="$(curl -s --connect-timeout 2 --max-time 8 "$API_HEALTH_URL" 2>/dev/null || true)"
      if echo "$body" | grep -q '"status"'; then
        log "API is up (attempt ${i}/${max_attempts})"
        return 0
      fi
      # PHP responds; keep waiting briefly for DB/Redis to become healthy
      if (( i % 5 == 0 )); then
        printf '    … PHP up, waiting for DB/Redis health (%s/%s) http=%s\n' "$i" "$max_attempts" "$code"
      fi
    else
      if (( i % 5 == 0 )); then
        printf '    … still starting (%s/%s) /up=%s\n' "$i" "$max_attempts" "$code"
        "${COMPOSE[@]}" ps 2>/dev/null | sed 's/^/       /' || true
      fi
    fi
    sleep 2
  done

  warn "API health check timed out on ${API_HEALTH_URL}"
  warn "Container status:"
  "${COMPOSE[@]}" ps || true
  warn "Recent app logs:"
  "${COMPOSE[@]}" logs app --tail 40 || true
  warn "Recent nginx logs:"
  "${COMPOSE[@]}" logs nginx --tail 20 || true
  curl -sv "$API_UP_URL" 2>&1 | tail -20 || true
  curl -sv "$API_HEALTH_URL" 2>&1 | tail -30 || true
  return 1
}

if ! wait_for_api 90; then
  die "API did not become healthy. Fix the errors above, then re-run setup or: cd docker && docker compose up -d"
fi

# Force admin password reset on first deploy if seeder skipped recreating password
if [[ "$RUN_SEEDER" == "true" ]]; then
  log "Ensuring admin user password is set"
  "${COMPOSE[@]}" exec -T \
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
" || warn "Could not reset admin via tinker (containers may still be starting)"
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
