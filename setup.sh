#!/usr/bin/env bash
# Email Server — production deploy script
#
# Primary workflow (recommended on the server):
#   1) Edit secrets in docker/.env and backend/.env yourself (never commit them)
#   2) Run:  ./setup.sh
#
# First time only (creates empty templates if missing):
#   cp docker/.env.example docker/.env
#   cp backend/.env.example backend/.env
#   # edit both files, then:
#   ./setup.sh
#
# Optional: still accepts --env-file / CLI flags to *seed* missing docker/.env
# values, but existing .env files are never overwritten.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# Preserve argv for re-exec after git pull (so the newly pulled setup.sh continues).
SETUP_ARGV=("$@")
GIT_PULLED=false

# ---------------------------------------------------------------------------
# Defaults (non-secret) — overridden by docker/.env once loaded
# ---------------------------------------------------------------------------
DOMAIN="${DOMAIN:-notifications.africacdc.org}"
ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
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
POSTGRES_HOST_PORT="${POSTGRES_HOST_PORT:-5433}"
FORCE_VENDOR_REINSTALL="${FORCE_VENDOR_REINSTALL:-false}"
RESET_POSTGRES="${RESET_POSTGRES:-false}"
RESET_REDIS="${RESET_REDIS:-false}"
RUN_SEEDER="${RUN_SEEDER:-true}"
SKIP_SSL="${SKIP_SSL:-false}"
SKIP_FRONTEND_BUILD="${SKIP_FRONTEND_BUILD:-false}"
FRONTEND_BUILD="${FRONTEND_BUILD:-auto}"
SKIP_NGINX="${SKIP_NGINX:-false}"
SKIP_REVERSE_PROXY="${SKIP_REVERSE_PROXY:-}"
REVERSE_PROXY="${REVERSE_PROXY:-nginx}"
SKIP_GIT_PULL="${SKIP_GIT_PULL:-false}"
APP_ENV="${APP_ENV:-production}"
APP_DEBUG="${APP_DEBUG:-false}"
ENV_FILE=""
WRITE_ENV="${WRITE_ENV:-false}"
NONINTERACTIVE="${NONINTERACTIVE:-false}"
REVERSE_PROXY_FROM_CLI="${REVERSE_PROXY_FROM_CLI:-false}"

usage() {
  cat <<'EOF'
Usage: ./setup.sh [options]

Recommended (production / later deploys):
  ./setup.sh
     → git fetch/pull (stash local tracked changes if needed)
     → re-runs itself so the latest setup.sh/scripts are used
     → interactive menu: host reverse proxy (1=nginx, 2=apache), domain, SSL
     → rebuild/restart stack

  Skip code update:  ./setup.sh --skip-git-pull

First-time templates:
  cp docker/.env.example docker/.env
  # edit ADMIN_PASSWORD, DB_PASSWORD, JWT_SECRET — then:
  ./setup.sh

setup.sh NEVER overwrites existing docker/.env / backend/.env unless you pass
  --write-env   (rebuilds them from CLI / --env-file — only for fresh boxes)

Optional flags:
  --env-file=PATH               Load KEY=VALUE into this shell (does not overwrite .env unless --write-env)
  --domain=HOST                 Used with --write-env / nginx site name
  --data-path=PATH              Persistent Postgres/Redis/storage path
  --queue-scale=N               docker compose --scale queue=N (default: 1)
  --postgres-host-port=PORT     Host Postgres port (default: 5433)
  --force-vendor                Wipe backend/vendor and reinstall via composer
  --reset-postgres              OPTIONAL wipe of Postgres data (DESTROYS DATA)
  --reset-redis                 Wipe Redis data dir (queues/cache only — safe vs Postgres)
  --run-seeder=true|false       Seed admin/providers (default: true)
  --write-env                   Rewrite docker/.env + backend/.env from flags/--env-file
  --skip-ssl                    Skip Certbot TLS setup
  --skip-frontend-build         Skip frontend build
  --frontend-build=auto|docker|host
  --skip-nginx                  Skip host reverse-proxy site install (alias: --skip-reverse-proxy)
  --skip-reverse-proxy          Same as --skip-nginx
  --reverse-proxy=nginx|apache  Host reverse proxy (default: nginx)
  --non-interactive             Do not prompt; use flags/env defaults
  --skip-git-pull               Do not stash/pull from git before deploy
  -h, --help

During install, setup.sh repeatedly fixes ownership/permissions for:
  DATA_PATH storage (www-data), redis (999), backend bootstrap/cache + .env,
  frontend/dist readability, and again inside the app container after up.

Required keys in docker/.env (edit manually):
  ADMIN_PASSWORD, DB_PASSWORD, JWT_SECRET (>=32 chars)
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

# Make path writable by www-data (uid 33) used by php-fpm in the app image.
try_chown() {
  # try_chown <uid:gid> <path...>
  local owner="$1"
  shift
  [[ $# -eq 0 ]] && return 0
  if chown -R "$owner" "$@" 2>/dev/null; then
    return 0
  fi
  run_root chown -R "$owner" "$@" 2>/dev/null || true
}

try_chmod() {
  # try_chmod <mode-args...> — last args are paths (chmod-compatible)
  if chmod "$@" 2>/dev/null; then
    return 0
  fi
  run_root chmod "$@" 2>/dev/null || true
}

# Fix host + data-path permissions so Docker (php-fpm/nginx/redis/postgres) can read/write.
# Safe to call multiple times during setup (before and after builds / compose up).
fix_server_permissions() {
  local phase="${1:-}"
  local www_uid=33 www_gid=33
  local redis_uid=999 redis_gid=999
  local data_path="${EMAIL_SERVER_DATA_PATH:-$DATA_PATH}"

  log "Fixing server permissions${phase:+ ($phase)}"

  # Persistent Laravel storage (bind-mounted over backend/storage in containers)
  if [[ -d "$data_path/storage" ]]; then
    try_chown "${www_uid}:${www_gid}" "$data_path/storage"
    try_chmod -R ug+rwX "$data_path/storage"
    # New files inherit group-write where supported
    run_root find "$data_path/storage" -type d -exec chmod g+s {} \; 2>/dev/null || true
  fi

  # Redis data dir (official image runs as uid 999)
  if [[ -d "$data_path/redis" ]]; then
    try_chown "${redis_uid}:${redis_gid}" "$data_path/redis"
    try_chmod -R u+rwX "$data_path/redis"
  fi

  # Postgres data dir (alpine ≈ 70, debian ≈ 999)
  if [[ -d "$data_path/postgres" ]]; then
    if [[ -n "$(ls -A "$data_path/postgres" 2>/dev/null || true)" ]]; then
      # Already initialized — do not force-chown over a running cluster; only ensure dir exists
      :
    else
      run_root chown -R 70:70 "$data_path/postgres" 2>/dev/null \
        || run_root chown -R 999:999 "$data_path/postgres" 2>/dev/null \
        || true
    fi
  fi

  # Host-mounted backend paths php-fpm / entrypoint need
  run_root mkdir -p \
    "$ROOT/backend/bootstrap/cache" \
    "$ROOT/backend/storage/framework/cache/data" \
    "$ROOT/backend/storage/framework/sessions" \
    "$ROOT/backend/storage/framework/views" \
    "$ROOT/backend/storage/logs" \
    2>/dev/null || mkdir -p \
      "$ROOT/backend/bootstrap/cache" \
      "$ROOT/backend/storage/logs" 2>/dev/null || true

  try_chown "${www_uid}:${www_gid}" "$ROOT/backend/bootstrap/cache"
  try_chmod -R ug+rwX "$ROOT/backend/bootstrap/cache"

  # Repo storage tree (unused when bind-mounted, but keep writable for local tooling)
  if [[ -d "$ROOT/backend/storage" ]]; then
    try_chown "${www_uid}:${www_gid}" "$ROOT/backend/storage"
    try_chmod -R ug+rwX "$ROOT/backend/storage"
  fi

  # backend/.env: entrypoint (root) rewrites APP_KEY; php-fpm (www-data) must read it
  if [[ -f "$ROOT/backend/.env" ]]; then
    try_chown "${www_uid}:${www_gid}" "$ROOT/backend/.env"
    try_chmod 640 "$ROOT/backend/.env"
  fi

  # docker/.env is only for compose on the host — keep private to the deploy user
  if [[ -f "$ROOT/docker/.env" ]]; then
    try_chmod 600 "$ROOT/docker/.env"
  fi

  # Built admin UI must be world-readable for the nginx container
  if [[ -d "$ROOT/frontend/dist" ]]; then
    try_chmod -R a+rX "$ROOT/frontend/dist"
  fi

  # Deploy scripts
  try_chmod +x "$ROOT/setup.sh"
  if [[ -f "$ROOT/docker/entrypoint.sh" ]]; then
    try_chmod +x "$ROOT/docker/entrypoint.sh"
  fi

  # Optional: inside running app container, normalize storage ownership again
  if [[ "$phase" == "post-up" ]] && [[ -n "${COMPOSE[*]:-}" ]]; then
    (
      cd "$ROOT/docker"
      "${COMPOSE[@]}" exec -T -u root app sh -c '
        mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/{public,private} storage/api-docs bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
        chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
        if [ -f .env ]; then chown www-data:www-data .env; chmod 640 .env; fi
      ' 2>/dev/null
    ) && log "Container storage/bootstrap permissions OK" \
      || warn "Could not fix permissions inside app container (may not be up yet)"
  fi

  log "Server permissions fixed${phase:+ ($phase)}"
}

# Stash local worktree changes (not gitignored secrets) and fast-forward pull when remote has commits.
# Sets GIT_PULLED=true when the worktree moved forward (caller should re-exec setup.sh).
sync_git_updates() {
  GIT_PULLED=false

  if [[ "${SKIP_GIT_PULL}" == "true" ]]; then
    log "Skipping git pull (--skip-git-pull)"
    return 0
  fi

  if ! command -v git >/dev/null 2>&1; then
    warn "git not installed — skipping pull"
    return 0
  fi

  if ! git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    warn "Not a git repository — skipping pull"
    return 0
  fi

  log "Checking for git updates (git fetch + pull)"
  if ! git -C "$ROOT" fetch --prune origin; then
    warn "git fetch failed — continuing with local tree"
    return 0
  fi

  local branch upstream behind ahead stash_msg before after
  branch="$(git -C "$ROOT" rev-parse --abbrev-ref HEAD)"
  if [[ "$branch" == "HEAD" ]]; then
    warn "Detached HEAD — skipping git pull"
    return 0
  fi

  if upstream="$(git -C "$ROOT" rev-parse --abbrev-ref '@{upstream}' 2>/dev/null)"; then
    :
  elif git -C "$ROOT" show-ref --verify --quiet "refs/remotes/origin/${branch}"; then
    upstream="origin/${branch}"
  elif git -C "$ROOT" show-ref --verify --quiet "refs/remotes/origin/main"; then
    upstream="origin/main"
  else
    warn "No upstream remote branch — skipping git pull"
    return 0
  fi

  behind="$(git -C "$ROOT" rev-list --count "HEAD..${upstream}" 2>/dev/null || echo 0)"
  ahead="$(git -C "$ROOT" rev-list --count "${upstream}..HEAD" 2>/dev/null || echo 0)"

  if [[ "${behind}" -eq 0 ]]; then
    log "Git already up to date with ${upstream} ($(git -C "$ROOT" rev-parse --short HEAD))"
    return 0
  fi

  log "Remote has ${behind} new commit(s) on ${upstream} — pulling for deploy"
  before="$(git -C "$ROOT" rev-parse HEAD)"

  if ! git -C "$ROOT" diff --quiet \
    || ! git -C "$ROOT" diff --cached --quiet \
    || [[ -n "$(git -C "$ROOT" ls-files --others --exclude-standard)" ]]; then
    stash_msg="setup.sh auto-stash before pull $(date -u +%Y%m%dT%H%M%SZ)"
    log "Stashing local changes (${stash_msg})"
    # -u includes untracked files; gitignored secrets (.env) are not stashed
    git -C "$ROOT" stash push -u -m "${stash_msg}" \
      || warn "git stash failed — attempting pull anyway"
  fi

  if [[ "${ahead}" -gt 0 ]]; then
    warn "Local branch is ahead of ${upstream} by ${ahead} commit(s) — pulling with rebase"
    git -C "$ROOT" pull --rebase --autostash origin "${branch}" \
      || die "git pull --rebase failed; resolve conflicts, then re-run ./setup.sh"
  else
    git -C "$ROOT" pull --ff-only origin "${branch}" \
      || die "git pull --ff-only failed; resolve manually, then re-run ./setup.sh"
  fi

  after="$(git -C "$ROOT" rev-parse HEAD)"
  log "Git pull complete ($(git -C "$ROOT" rev-parse --short "$before") → $(git -C "$ROOT" rev-parse --short "$after"))"
  if [[ "$before" != "$after" ]]; then
    GIT_PULLED=true
  fi
  if git -C "$ROOT" stash list 2>/dev/null | head -1 | grep -q 'setup.sh auto-stash'; then
    warn "Local changes were stashed. Review with: git stash list && git stash pop"
  fi
}

prompt_yes_no() {
  # $1 prompt  $2 default y|n
  local prompt="$1" default="${2:-y}" reply
  if [[ "$NONINTERACTIVE" == "true" ]]; then
    [[ "$default" == "y" ]]
    return $?
  fi
  if [[ -r /dev/tty ]]; then
    if [[ "$default" == "y" ]]; then
      read -r -p "${prompt} [Y/n] " reply </dev/tty || reply=""
      reply="${reply:-y}"
    else
      read -r -p "${prompt} [y/N] " reply </dev/tty || reply=""
      reply="${reply:-n}"
    fi
  elif [[ -t 0 ]]; then
    if [[ "$default" == "y" ]]; then
      read -r -p "${prompt} [Y/n] " reply || reply=""
      reply="${reply:-y}"
    else
      read -r -p "${prompt} [y/N] " reply || reply=""
      reply="${reply:-n}"
    fi
  else
    [[ "$default" == "y" ]]
    return $?
  fi
  case "${reply}" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

prompt_value() {
  # $1 prompt  $2 default → echoes value
  local prompt="$1" default="$2" reply
  if [[ "$NONINTERACTIVE" == "true" ]]; then
    printf '%s\n' "$default"
    return 0
  fi
  if [[ -r /dev/tty ]]; then
    read -r -p "${prompt} [${default}]: " reply </dev/tty || reply=""
  elif [[ -t 0 ]]; then
    read -r -p "${prompt} [${default}]: " reply || reply=""
  else
    printf '%s\n' "$default"
    return 0
  fi
  printf '%s\n' "${reply:-$default}"
}

can_prompt_interactive() {
  [[ "$NONINTERACTIVE" != "true" ]] && { [[ -r /dev/tty ]] || [[ -t 0 ]]; }
}

configure_reverse_proxy_interactive() {
  # Honor legacy SKIP_NGINX / new SKIP_REVERSE_PROXY
  if [[ "${SKIP_REVERSE_PROXY}" == "true" || "${SKIP_NGINX}" == "true" ]]; then
    SKIP_NGINX=true
    SKIP_REVERSE_PROXY=true
    log "Skipping host reverse-proxy install"
    return 0
  fi

  local choice domain_in default_num
  REVERSE_PROXY="$(printf '%s' "${REVERSE_PROXY:-nginx}" | tr '[:upper:]' '[:lower:]')"
  case "$REVERSE_PROXY" in
    apache) default_num=2 ;;
    nginx|*) REVERSE_PROXY=nginx; default_num=1 ;;
  esac

  if can_prompt_interactive; then
    echo
    echo "========================================================================"
    echo " Host reverse proxy"
    echo "========================================================================"
    echo "  This app runs in Docker. The host reverse proxy terminates TLS and"
    echo "  forwards traffic to the API (:${API_HOST_PORT:-8089}) and Admin UI (:3006)."
    echo
    echo "  1) nginx   — default (sites-available / sites-enabled + certbot --nginx)"
    echo "  2) apache  — email_server_vhost.conf + a2ensite + certbot --apache"
    echo
    choice="$(prompt_value "Select host server [1=nginx, 2=apache]" "$default_num")"
    case "$(printf '%s' "$choice" | tr '[:upper:]' '[:lower:]')" in
      1|nginx|n) REVERSE_PROXY=nginx ;;
      2|apache|a) REVERSE_PROXY=apache ;;
      *)
        warn "Invalid choice '${choice}' — using nginx"
        REVERSE_PROXY=nginx
        ;;
    esac

    domain_in="$(prompt_value "Public domain for this host" "$DOMAIN")"
    DOMAIN="${domain_in:-$DOMAIN}"

    if [[ "$SKIP_SSL" != "true" ]]; then
      CERTBOT_EMAIL="$(prompt_value "Let's Encrypt / Certbot email" "${CERTBOT_EMAIL:-$ADMIN_EMAIL}")"
    fi

    echo
    echo "  Host server   : ${REVERSE_PROXY}"
    echo "  Domain        : ${DOMAIN}"
    echo "  SSL (Certbot) : $([[ "$SKIP_SSL" == "true" ]] && echo disabled || echo enabled)"
    echo "  Certbot email : ${CERTBOT_EMAIL:-$ADMIN_EMAIL}"
    echo
    if ! prompt_yes_no "Proceed with these settings?" y; then
      die "Aborted by operator. Re-run ./setup.sh and choose nginx or apache again."
    fi
  else
    log "Reverse proxy=${REVERSE_PROXY} domain=${DOMAIN} (non-interactive — pass --reverse-proxy= or run in a terminal)"
  fi
}

ensure_pkg() {
  # ensure_pkg <apt-packages...>
  local missing=()
  local p
  for p in "$@"; do
    if ! dpkg -s "$p" >/dev/null 2>&1; then
      missing+=("$p")
    fi
  done
  if [[ "${#missing[@]}" -eq 0 ]]; then
    return 0
  fi
  log "Installing packages: ${missing[*]}"
  run_root apt-get update -qq
  DEBIAN_FRONTEND=noninteractive run_root apt-get install -y "${missing[@]}"
}

ensure_certbot_renewal() {
  # Only touch this app's lineage (DOMAIN from docker/.env / --domain). Never renew every
  # cert on a shared host (other africacdc.org sites share the same Certbot install).
  local cert_name="${DOMAIN}"
  log "Ensuring Certbot automatic renewal for ${cert_name}"
  if systemctl list-unit-files 2>/dev/null | grep -q '^certbot.timer'; then
    run_root systemctl enable --now certbot.timer || warn "Could not enable certbot.timer"
    run_root systemctl status certbot.timer --no-pager -l || true
  elif [[ -f /etc/cron.d/certbot ]]; then
    log "Certbot cron found at /etc/cron.d/certbot"
  else
    # Fallback: renew only this app's certificate
    run_root tee /etc/cron.d/email-server-certbot-renew >/dev/null <<CRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
0 */12 * * * root test -x /usr/bin/certbot && perl -e 'sleep int(rand(43200))' && certbot renew -q --cert-name ${cert_name} --deploy-hook "systemctl reload nginx 2>/dev/null; systemctl reload apache2 2>/dev/null; true"
CRON
    log "Installed /etc/cron.d/email-server-certbot-renew (cert-name=${cert_name})"
  fi
  log "Certbot renew dry-run for ${cert_name} only"
  run_root certbot renew --cert-name "$cert_name" --dry-run \
    || warn "Certbot renew dry-run for ${cert_name} reported issues (DNS/HTTP challenge may be pending)"
}

install_nginx_reverse_proxy() {
  local api_port ui_port src tmp
  api_port="${API_HOST_PORT:-8089}"
  ui_port="3006"

  ensure_pkg nginx
  need_cmd nginx

  log "Installing Nginx site for ${DOMAIN} (API :${api_port}, UI :${ui_port})"
  run_root mkdir -p /etc/nginx/snippets /etc/nginx/conf.d /var/www/html

  run_root cp "$ROOT/deploy/configs/nginx-http-rate-limit.conf" \
    /etc/nginx/conf.d/email-server-rate-limit.conf
  run_root cp "$ROOT/deploy/configs/nginx-security-headers.conf" \
    /etc/nginx/snippets/email-server-security-headers.conf

  src="$ROOT/deploy/configs/nginx-notifications.africacdc.org.conf"
  tmp="$(mktemp)"
  sed \
    -e "s/notifications\.africacdc\.org/${DOMAIN}/g" \
    -e "s/127\.0\.0\.1:8089/127.0.0.1:${api_port}/g" \
    -e "s/127\.0\.0\.1:3006/127.0.0.1:${ui_port}/g" \
    "$src" > "$tmp"
  run_root cp "$tmp" "/etc/nginx/sites-available/${DOMAIN}.conf"
  rm -f "$tmp"
  run_root ln -sfn "/etc/nginx/sites-available/${DOMAIN}.conf" "/etc/nginx/sites-enabled/${DOMAIN}.conf"

  # Avoid default site stealing the domain
  if [[ -L /etc/nginx/sites-enabled/default ]]; then
    run_root rm -f /etc/nginx/sites-enabled/default || true
  fi

  if run_root nginx -t; then
    run_root systemctl enable nginx || true
    run_root systemctl reload nginx || run_root systemctl restart nginx
  else
    warn "nginx -t failed — check /etc/nginx/sites-available/${DOMAIN}.conf"
  fi
}

install_apache_reverse_proxy() {
  local api_port ui_port src tmp site="email_server_vhost.conf"
  api_port="${API_HOST_PORT:-8089}"
  ui_port="3006"

  ensure_pkg apache2
  need_cmd apache2
  need_cmd a2ensite
  need_cmd a2enmod

  log "Installing Apache reverse-proxy vhost (${site}) for ${DOMAIN}"
  run_root a2enmod proxy proxy_http headers rewrite ssl socache_shmcb remoteip >/dev/null

  run_root mkdir -p /var/www/html/.well-known/acme-challenge
  run_root chown -R www-data:www-data /var/www/html || true

  src="$ROOT/deploy/configs/email_server_vhost.conf"
  [[ -f "$src" ]] || die "Missing ${src}"
  tmp="$(mktemp)"
  sed \
    -e "s/__DOMAIN__/${DOMAIN}/g" \
    -e "s/__API_UPSTREAM__/127.0.0.1:${api_port}/g" \
    -e "s/__UI_UPSTREAM__/127.0.0.1:${ui_port}/g" \
    "$src" > "$tmp"

  if [[ ! -f "/etc/apache2/sites-available/${site}" ]]; then
    log "Creating /etc/apache2/sites-available/${site}"
  else
    log "Updating /etc/apache2/sites-available/${site}"
  fi
  run_root cp "$tmp" "/etc/apache2/sites-available/${site}"
  rm -f "$tmp"

  run_root a2ensite "$site" >/dev/null
  # Prefer this vhost over the default site on :80
  if [[ -f /etc/apache2/sites-enabled/000-default.conf ]]; then
    run_root a2dissite 000-default >/dev/null || true
  fi

  if command -v nginx >/dev/null 2>&1 && systemctl is-active --quiet nginx 2>/dev/null; then
    warn "Nginx is active while Apache reverse-proxy was selected — :80 may conflict."
    if prompt_yes_no "Stop and disable Nginx so Apache can bind :80/:443?" y; then
      run_root systemctl stop nginx || true
      run_root systemctl disable nginx || true
    fi
  fi

  if run_root apache2ctl configtest; then
    run_root systemctl enable apache2 || true
    run_root systemctl reload apache2 || run_root systemctl restart apache2
  else
    die "apache2ctl configtest failed — fix /etc/apache2/sites-available/${site}"
  fi
}

install_host_ssl() {
  if [[ "$SKIP_SSL" == "true" ]]; then
    warn "Skipping SSL (--skip-ssl)"
    return 0
  fi

  local email="${CERTBOT_EMAIL:-$ADMIN_EMAIL}"
  [[ -n "$email" ]] || die "CERTBOT_EMAIL / ADMIN_EMAIL required for SSL"

  ensure_pkg certbot
  need_cmd certbot

  log "Issuing/installing SSL certificate with Certbot for ${DOMAIN} (${REVERSE_PROXY})"

  if [[ "$REVERSE_PROXY" == "apache" ]]; then
    ensure_pkg python3-certbot-apache
    if ! run_root certbot --apache \
      -d "$DOMAIN" \
      --agree-tos \
      --redirect \
      -m "$email" \
      --non-interactive \
      --keep-until-expiring; then
      warn "Certbot --apache failed — trying webroot HTTP-01"
      run_root mkdir -p /var/www/html
      run_root certbot certonly --webroot \
        -w /var/www/html \
        -d "$DOMAIN" \
        --agree-tos \
        -m "$email" \
        --non-interactive \
        --keep-until-expiring \
        || warn "Certbot webroot also failed — check DNS A/AAAA for ${DOMAIN} and that :80 reaches this host"
    fi
  else
    ensure_pkg python3-certbot-nginx
    # Ensure rate-limit zones exist
    if [[ -f "$ROOT/deploy/configs/nginx-http-rate-limit.conf" ]]; then
      run_root mkdir -p /etc/nginx/conf.d
      run_root cp "$ROOT/deploy/configs/nginx-http-rate-limit.conf" \
        /etc/nginx/conf.d/email-server-rate-limit.conf
      run_root nginx -t && run_root systemctl reload nginx || warn "nginx -t still failing before certbot"
    fi

    if ! run_root certbot --nginx \
      -d "$DOMAIN" \
      --agree-tos \
      --redirect \
      -m "$email" \
      --non-interactive \
      --keep-until-expiring; then
      warn "Certbot --nginx failed — trying webroot HTTP-01 instead"
      run_root mkdir -p /var/www/html
      run_root certbot certonly --webroot \
        -w /var/www/html \
        -d "$DOMAIN" \
        --agree-tos \
        -m "$email" \
        --non-interactive \
        --keep-until-expiring \
        || warn "Certbot webroot also failed — fix reverse proxy then re-run certbot"
    fi
  fi

  log "Verifying HTTPS"
  curl -fsSI "https://${DOMAIN}/api/v1/health" | head -n 1 || warn "HTTPS health check failed — DNS/firewall may need attention"

  local login_probe
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
  fi

  ensure_certbot_renewal
}

# Load an external KEY=VALUE file without executing it.
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

# Read KEY from a .env file (strips surrounding quotes).
# Missing key → empty string (must not fail under set -euo pipefail).
env_file_get() {
  local file="$1"
  local key="$2"
  local line=""
  [[ -f "$file" ]] || { printf ''; return 0; }
  line="$(grep -E "^${key}=" "$file" 2>/dev/null | head -1 || true)"
  [[ -n "$line" ]] || { printf ''; return 0; }
  printf '%s' "${line#*=}" | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
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

# Atomic replace without interactive prompts (aliases like mv -i, or 0640 www-data .env).
replace_file() {
  local src="$1"
  local dest="$2"
  # command bypasses shell aliases (mv -i); -f never prompts
  if command mv -f "$src" "$dest" 2>/dev/null; then
    return 0
  fi
  run_root command mv -f "$src" "$dest"
}

set_env_key() {
  # set_env_key <file> <KEY> <value>  — upsert KEY="value" (quoted)
  local file="$1"
  local key="$2"
  local value="$3"
  local escaped="${value//\\/\\\\}"
  escaped="${escaped//\"/\\\"}"
  local line="${key}=\"${escaped}\""
  local tmp

  [[ -f "$file" ]] || die "Cannot set ${key}: missing ${file}"

  tmp="$(mktemp "${file}.XXXXXX")"
  if grep -q "^${key}=" "$file"; then
    if ! awk -v k="$key" -v line="$line" '
      BEGIN { done=0 }
      index($0, k "=") == 1 {
        print line
        done=1
        next
      }
      { print }
      END { if (!done) print line }
    ' "$file" > "$tmp" 2>/dev/null; then
      run_root bash -c "awk -v k=\"$key\" -v line=\"$line\" '
        BEGIN { done=0 }
        index(\$0, k \"=\") == 1 { print line; done=1; next }
        { print }
        END { if (!done) print line }
      ' \"$file\" > \"$tmp\""
    fi
    replace_file "$tmp" "$file"
  else
    rm -f "$tmp"
    if ! printf '%s\n' "$line" >> "$file" 2>/dev/null; then
      run_root bash -c "printf '%s\\n' $(printf '%q' "$line") >> $(printf '%q' "$file")"
    fi
  fi
  try_chmod 640 "$file"
}

set_backend_env() {
  set_env_key "$ROOT/backend/.env" "$1" "$2"
}

set_docker_env() {
  set_env_key "$ROOT/docker/.env" "$1" "$2"
}

# ---------------------------------------------------------------------------
# Parse args
# ---------------------------------------------------------------------------
for arg in "$@"; do
  case "$arg" in
    --env-file=*) ENV_FILE="${arg#*=}" ;;
  esac
done

if [[ -n "$ENV_FILE" ]]; then
  log "Loading values from $ENV_FILE (into this process only)"
  load_env_file "$ENV_FILE"
  DOMAIN="${DOMAIN:-notifications.africacdc.org}"
  ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
  DATA_PATH="${EMAIL_SERVER_DATA_PATH:-${DATA_PATH:-/home/email_serverdata}}"
  JWT_TTL="${JWT_TTL:-60}"
  QUEUE_SCALE="${QUEUE_SCALE:-1}"
  POSTGRES_HOST_PORT="${POSTGRES_HOST_PORT:-5433}"
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
    --postgres-host-port=*) POSTGRES_HOST_PORT="${1#*=}" ;;
    --force-vendor) FORCE_VENDOR_REINSTALL=true ;;
    --reset-postgres) RESET_POSTGRES=true ;;
    --reset-redis) RESET_REDIS=true ;;
    --run-seeder=*) RUN_SEEDER="${1#*=}" ;;
    --write-env) WRITE_ENV=true ;;
    --skip-ssl) SKIP_SSL=true ;;
    --skip-frontend-build) SKIP_FRONTEND_BUILD=true ;;
    --frontend-build=*) FRONTEND_BUILD="${1#*=}" ;;
    --skip-nginx|--skip-reverse-proxy) SKIP_NGINX=true; SKIP_REVERSE_PROXY=true ;;
    --reverse-proxy=*)
      REVERSE_PROXY="${1#*=}"
      REVERSE_PROXY_FROM_CLI=true
      ;;
    --non-interactive) NONINTERACTIVE=true ;;
    --skip-git-pull) SKIP_GIT_PULL=true ;;
    *) die "Unknown option: $1 (try --help)" ;;
  esac
  shift
done

# Pull remote commits early, then re-exec so the rest of this run uses the new setup.sh.
# After a re-exec, skip a second pull/re-exec loop (SETUP_REEXEC_AFTER_PULL=1).
if [[ "${SETUP_REEXEC_AFTER_PULL:-}" == "1" ]]; then
  log "Continuing setup after git pull (at $(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null || echo local))"
else
  sync_git_updates
  if [[ "$GIT_PULLED" == "true" ]]; then
    log "Re-running ./setup.sh with updated code from git"
    export SETUP_REEXEC_AFTER_PULL=1
    exec bash "$ROOT/setup.sh" "${SETUP_ARGV[@]}"
  fi
fi

# ---------------------------------------------------------------------------
# Ensure .env templates exist (never overwrite existing files)
# ---------------------------------------------------------------------------
BACKEND_ENV_JUST_CREATED=false

if [[ ! -f "$ROOT/docker/.env" ]]; then
  [[ -f "$ROOT/docker/.env.example" ]] || die "docker/.env.example missing"
  cp "$ROOT/docker/.env.example" "$ROOT/docker/.env"
  chmod 600 "$ROOT/docker/.env"
  warn "Created docker/.env from example — edit secrets, then re-run ./setup.sh"
  die "Stopped: fill ADMIN_PASSWORD, DB_PASSWORD, JWT_SECRET in docker/.env"
fi

if [[ ! -f "$ROOT/backend/.env" ]]; then
  [[ -f "$ROOT/backend/.env.example" ]] || die "backend/.env.example missing"
  umask 077
  cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
  chmod 600 "$ROOT/backend/.env"
  BACKEND_ENV_JUST_CREATED=true
  log "Created backend/.env from example — syncing secrets from docker/.env (no manual edit required)"
fi

# Load operator-edited docker/.env as source of truth
log "Using existing docker/.env (not overwritten)"
load_env_file "$ROOT/docker/.env"

# Refresh locals from docker/.env / environment
DOMAIN="${DOMAIN:-notifications.africacdc.org}"
# Prefer DOMAIN; else strip host from APP_URL
if [[ -z "${DOMAIN}" || "$DOMAIN" == "notifications.africacdc.org" ]]; then
  _app_url="$(env_file_get "$ROOT/docker/.env" APP_URL)"
  if [[ -n "$_app_url" ]]; then
    DOMAIN="$(printf '%s' "$_app_url" | sed -e 's|^https\?://||' -e 's|/.*||')"
  fi
fi
ADMIN_EMAIL="$(env_file_get "$ROOT/docker/.env" ADMIN_EMAIL)"; ADMIN_EMAIL="${ADMIN_EMAIL:-andrewa@africacdc.org}"
ADMIN_PASSWORD="$(env_file_get "$ROOT/docker/.env" ADMIN_PASSWORD)"
DB_PASSWORD="$(env_file_get "$ROOT/docker/.env" DB_PASSWORD)"
JWT_SECRET="$(env_file_get "$ROOT/docker/.env" JWT_SECRET)"
JWT_TTL="$(env_file_get "$ROOT/docker/.env" JWT_TTL)"; JWT_TTL="${JWT_TTL:-60}"
DATA_PATH="$(env_file_get "$ROOT/docker/.env" EMAIL_SERVER_DATA_PATH)"; DATA_PATH="${DATA_PATH:-/home/email_serverdata}"
POSTGRES_HOST_PORT="$(env_file_get "$ROOT/docker/.env" POSTGRES_HOST_PORT)"; POSTGRES_HOST_PORT="${POSTGRES_HOST_PORT:-5433}"
API_HOST_PORT="$(env_file_get "$ROOT/docker/.env" API_HOST_PORT)"; API_HOST_PORT="${API_HOST_PORT:-8089}"
RUN_SEEDER="$(env_file_get "$ROOT/docker/.env" RUN_SEEDER)"; RUN_SEEDER="${RUN_SEEDER:-true}"
APP_ENV="$(env_file_get "$ROOT/docker/.env" APP_ENV)"; APP_ENV="${APP_ENV:-production}"
APP_DEBUG="$(env_file_get "$ROOT/docker/.env" APP_DEBUG)"; APP_DEBUG="${APP_DEBUG:-false}"
INTEGRATION_CLIENT_SECRET="$(env_file_get "$ROOT/docker/.env" INTEGRATION_CLIENT_SECRET)"
QUEUE_SCALE="${QUEUE_SCALE:-1}"

# Backend-held values (operator may edit backend/.env for Exchange, etc.)
MAIL_FROM_ADDRESS="$(env_file_get "$ROOT/backend/.env" MAIL_FROM_ADDRESS)"
MAIL_FROM_NAME="$(env_file_get "$ROOT/backend/.env" MAIL_FROM_NAME)"
MAIL_FROM_NAME="${MAIL_FROM_NAME:-Africa CDC Notifications}"
EXCHANGE_TENANT_ID="$(env_file_get "$ROOT/backend/.env" EXCHANGE_TENANT_ID)"
EXCHANGE_CLIENT_ID="$(env_file_get "$ROOT/backend/.env" EXCHANGE_CLIENT_ID)"
EXCHANGE_CLIENT_SECRET="$(env_file_get "$ROOT/backend/.env" EXCHANGE_CLIENT_SECRET)"
EXCHANGE_AUTH_METHOD="$(env_file_get "$ROOT/backend/.env" EXCHANGE_AUTH_METHOD)"
EXCHANGE_AUTH_METHOD="${EXCHANGE_AUTH_METHOD:-client_credentials}"
EXCHANGE_SCOPE="$(env_file_get "$ROOT/backend/.env" EXCHANGE_SCOPE)"
EXCHANGE_SCOPE="${EXCHANGE_SCOPE:-https://graph.microsoft.com/.default}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-$ADMIN_EMAIL}"

# Ask reverse-proxy engine + domain (defaults: nginx + DOMAIN from env)
# Skip with --skip-nginx / --skip-reverse-proxy / --non-interactive
if [[ "${REVERSE_PROXY_FROM_CLI}" != "true" ]]; then
  _rp="$(env_file_get "$ROOT/docker/.env" REVERSE_PROXY)"
  [[ -n "$_rp" ]] && REVERSE_PROXY="$_rp"
fi
REVERSE_PROXY="$(printf '%s' "${REVERSE_PROXY:-nginx}" | tr '[:upper:]' '[:lower:]')"
configure_reverse_proxy_interactive

[[ -n "$MAIL_FROM_ADDRESS" ]] || MAIL_FROM_ADDRESS="notifications@${DOMAIN}"

is_placeholder() {
  case "$1" in
    ""|change-me*|CHANGE_ME*|changeme*) return 0 ;;
    *) return 1 ;;
  esac
}

# If docker/.env still has example placeholders, prompt (interactive) or auto-generate
# secrets so first-time deploy can continue without a second manual edit cycle.
ensure_required_secrets() {
  local reply gen

  if is_placeholder "$ADMIN_PASSWORD"; then
    if can_prompt_interactive; then
      echo
      echo "========================================================================"
      echo " Admin password"
      echo "========================================================================"
      echo "  docker/.env still has a placeholder ADMIN_PASSWORD."
      echo "  Enter the password you will use to log into the admin UI."
      echo
      while true; do
        reply="$(prompt_value "ADMIN_PASSWORD (min 8 characters)" "")"
        if [[ ${#reply} -ge 8 ]] && ! is_placeholder "$reply"; then
          ADMIN_PASSWORD="$reply"
          break
        fi
        warn "Need a real password at least 8 characters (not change-me…)."
      done
      set_docker_env "ADMIN_PASSWORD" "$ADMIN_PASSWORD"
      log "Saved ADMIN_PASSWORD to docker/.env"
    else
      die "Set a real ADMIN_PASSWORD in docker/.env (not a placeholder), then re-run ./setup.sh"
    fi
  fi

  if is_placeholder "$DB_PASSWORD"; then
    if can_prompt_interactive; then
      echo
      echo "DB_PASSWORD in docker/.env is missing or still a placeholder."
      if prompt_yes_no "Auto-generate a strong DB_PASSWORD?" y; then
        DB_PASSWORD="$(gen_secret 24)"
        log "Generated DB_PASSWORD"
      else
        while true; do
          reply="$(prompt_value "DB_PASSWORD (min 12 characters)" "")"
          if [[ ${#reply} -ge 12 ]] && ! is_placeholder "$reply"; then
            DB_PASSWORD="$reply"
            break
          fi
          warn "Need a real DB password at least 12 characters."
        done
      fi
      set_docker_env "DB_PASSWORD" "$DB_PASSWORD"
      log "Saved DB_PASSWORD to docker/.env"
    else
      die "Set a real DB_PASSWORD in docker/.env, then re-run ./setup.sh"
    fi
  fi

  if is_placeholder "$JWT_SECRET" || [[ "${#JWT_SECRET}" -lt 32 ]]; then
    if can_prompt_interactive; then
      echo
      echo "JWT_SECRET in docker/.env is missing, too short, or still a placeholder."
      if prompt_yes_no "Auto-generate JWT_SECRET (>=64 chars)?" y; then
        JWT_SECRET="$(gen_secret 48)"
        log "Generated JWT_SECRET"
      else
        while true; do
          reply="$(prompt_value "JWT_SECRET (>=32 characters)" "")"
          if [[ ${#reply} -ge 32 ]] && ! is_placeholder "$reply"; then
            JWT_SECRET="$reply"
            break
          fi
          warn "JWT_SECRET must be at least 32 characters and not a placeholder."
        done
      fi
      set_docker_env "JWT_SECRET" "$JWT_SECRET"
      log "Saved JWT_SECRET to docker/.env"
    else
      [[ -n "$JWT_SECRET" ]] || die "Set JWT_SECRET in docker/.env (>=32 chars)"
      is_placeholder "$JWT_SECRET" && die "Set a real JWT_SECRET in docker/.env (not a placeholder)"
      [[ "${#JWT_SECRET}" -ge 32 ]] || die "JWT_SECRET in docker/.env must be at least 32 characters"
    fi
  fi

  [[ "$SKIP_SSL" == "true" ]] || [[ -n "$CERTBOT_EMAIL" ]] || die "Set CERTBOT_EMAIL in the environment or use --skip-ssl (default: ADMIN_EMAIL)"
}

ensure_required_secrets

APP_URL="$(env_file_get "$ROOT/docker/.env" APP_URL)"
FRONTEND_URL="$(env_file_get "$ROOT/docker/.env" FRONTEND_URL)"
[[ -n "$APP_URL" ]] || APP_URL="https://${DOMAIN}"
[[ -n "$FRONTEND_URL" ]] || FRONTEND_URL="https://${DOMAIN}"

need_cmd docker
need_cmd openssl
if [[ "$SKIP_NGINX" != "true" && "$SKIP_REVERSE_PROXY" != "true" ]]; then
  case "$(printf '%s' "$REVERSE_PROXY" | tr '[:upper:]' '[:lower:]')" in
    apache) : ;; # packages installed in install_apache_reverse_proxy
    nginx|*) : ;;
  esac
fi
if [[ "$SKIP_SSL" != "true" ]]; then
  : # certbot installed in install_host_ssl
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE=(docker compose -f "$ROOT/docker/docker-compose.yml" --env-file "$ROOT/docker/.env")
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE=(docker-compose -f "$ROOT/docker/docker-compose.yml" --env-file "$ROOT/docker/.env")
else
  die "Docker Compose is required"
fi

# Optional: only rewrite .env files when explicitly requested
if [[ "$WRITE_ENV" == "true" ]]; then
  warn "--write-env: rewriting docker/.env and backend/.env from current variables"
  [[ -n "$INTEGRATION_CLIENT_SECRET" ]] || INTEGRATION_CLIENT_SECRET="$(gen_secret 32)"
  write_env_file "$ROOT/docker/.env" \
    "APP_ENV=${APP_ENV}" \
    "APP_DEBUG=${APP_DEBUG}" \
    "RUN_SEEDER=${RUN_SEEDER}" \
    "EMAIL_SERVER_DATA_PATH=${DATA_PATH}" \
    "APP_URL=${APP_URL}" \
    "FRONTEND_URL=${FRONTEND_URL}" \
    "API_HOST_PORT=${API_HOST_PORT}" \
    "POSTGRES_HOST_PORT=${POSTGRES_HOST_PORT}" \
    "REDIS_CLIENT=predis" \
    "ADMIN_EMAIL=${ADMIN_EMAIL}" \
    "ADMIN_PASSWORD=${ADMIN_PASSWORD}" \
    "ADMIN_RESET_PASSWORD=true" \
    "DB_PASSWORD=${DB_PASSWORD}" \
    "JWT_SECRET=${JWT_SECRET}" \
    "JWT_TTL=${JWT_TTL}" \
    "API_DOCS_ENABLED=${API_DOCS_ENABLED:-true}" \
    "INTEGRATION_CLIENT_SECRET=${INTEGRATION_CLIENT_SECRET}"

  PRESERVED_APP_KEY="$(env_file_get "$ROOT/backend/.env" APP_KEY)"
  umask 077
  cp "$ROOT/backend/.env.example" "$ROOT/backend/.env"
  chmod 600 "$ROOT/backend/.env"
  set_backend_env "APP_NAME" "Email Server"
  set_backend_env "APP_ENV" "$APP_ENV"
  set_backend_env "APP_DEBUG" "$APP_DEBUG"
  set_backend_env "APP_URL" "$APP_URL"
  set_backend_env "FRONTEND_URL" "$FRONTEND_URL"
  set_backend_env "DB_CONNECTION" "pgsql"
  set_backend_env "DB_HOST" "postgres"
  set_backend_env "DB_PORT" "5432"
  set_backend_env "DB_DATABASE" "email_server"
  set_backend_env "DB_USERNAME" "email_server"
  set_backend_env "DB_PASSWORD" "$DB_PASSWORD"
  set_backend_env "REDIS_CLIENT" "predis"
  set_backend_env "MAIL_MAILER" "log"
  set_backend_env "ADMIN_EMAIL" "$ADMIN_EMAIL"
  set_backend_env "ADMIN_PASSWORD" "$ADMIN_PASSWORD"
  set_backend_env "JWT_SECRET" "$JWT_SECRET"
  set_backend_env "JWT_TTL" "$JWT_TTL"
  set_backend_env "API_DOCS_ENABLED" "${API_DOCS_ENABLED:-true}"
  set_backend_env "INTEGRATION_CLIENT_SECRET" "$INTEGRATION_CLIENT_SECRET"
  set_backend_env "SANCTUM_STATEFUL_DOMAINS" "localhost,localhost:3006,127.0.0.1"
  if [[ "$PRESERVED_APP_KEY" == base64:* ]]; then
    set_backend_env "APP_KEY" "$PRESERVED_APP_KEY"
  else
    set_backend_env "APP_KEY" "base64:$(openssl rand -base64 32 | tr -d '\n')"
  fi
else
  if [[ "$BACKEND_ENV_JUST_CREATED" == "true" ]]; then
    log "First-time backend/.env — syncing core keys from docker/.env"
  else
    log "Leaving docker/.env and backend/.env as operator-edited (syncing shared secrets only)"
  fi

  # Keep Laravel secrets in sync with docker/.env (source of truth for deploy)
  _be_db="$(env_file_get "$ROOT/backend/.env" DB_PASSWORD)"
  if [[ "$_be_db" != "$DB_PASSWORD" ]]; then
    log "Syncing DB_PASSWORD from docker/.env → backend/.env"
    set_backend_env "DB_PASSWORD" "$DB_PASSWORD"
  fi
  _be_jwt="$(env_file_get "$ROOT/backend/.env" JWT_SECRET)"
  if [[ -z "$_be_jwt" || "$_be_jwt" != "$JWT_SECRET" ]]; then
    log "Syncing JWT_SECRET from docker/.env → backend/.env"
    set_backend_env "JWT_SECRET" "$JWT_SECRET"
  fi
  _be_admin_pw="$(env_file_get "$ROOT/backend/.env" ADMIN_PASSWORD)"
  if [[ -n "$ADMIN_PASSWORD" && "$_be_admin_pw" != "$ADMIN_PASSWORD" ]]; then
    log "Syncing ADMIN_PASSWORD from docker/.env → backend/.env"
    set_backend_env "ADMIN_PASSWORD" "$ADMIN_PASSWORD"
  fi
  _be_admin_email="$(env_file_get "$ROOT/backend/.env" ADMIN_EMAIL)"
  if [[ -n "$ADMIN_EMAIL" && "$_be_admin_email" != "$ADMIN_EMAIL" ]]; then
    set_backend_env "ADMIN_EMAIL" "$ADMIN_EMAIL"
  fi

  # First boot from example: align APP_* URLs/env with docker/.env
  if [[ "$BACKEND_ENV_JUST_CREATED" == "true" ]]; then
    set_backend_env "APP_ENV" "$APP_ENV"
    set_backend_env "APP_DEBUG" "$APP_DEBUG"
    set_backend_env "APP_URL" "${APP_URL:-https://${DOMAIN}}"
    set_backend_env "FRONTEND_URL" "${FRONTEND_URL:-https://${DOMAIN}}"
    set_backend_env "JWT_TTL" "$JWT_TTL"
    set_backend_env "DB_CONNECTION" "pgsql"
    set_backend_env "DB_HOST" "postgres"
    set_backend_env "DB_PORT" "5432"
    set_backend_env "DB_DATABASE" "email_server"
    set_backend_env "DB_USERNAME" "email_server"
    set_backend_env "REDIS_CLIENT" "predis"
    if [[ -n "$INTEGRATION_CLIENT_SECRET" ]]; then
      set_backend_env "INTEGRATION_CLIENT_SECRET" "$INTEGRATION_CLIENT_SECRET"
    fi
  fi

  # Swagger: Compose injects API_DOCS_ENABLED into the app container (overrides backend/.env)
  _api_docs="$(env_file_get "$ROOT/docker/.env" API_DOCS_ENABLED)"
  if [[ -z "$_api_docs" ]]; then
    log "API_DOCS_ENABLED missing in docker/.env — adding API_DOCS_ENABLED=true"
    if printf '\nAPI_DOCS_ENABLED=true\n' >> "$ROOT/docker/.env" 2>/dev/null; then
      :
    else
      run_root bash -c "printf '\\nAPI_DOCS_ENABLED=true\\n' >> '$ROOT/docker/.env'"
    fi
    _api_docs=true
  fi
  log "API_DOCS_ENABLED=${_api_docs} (from docker/.env)"
  _be_docs="$(env_file_get "$ROOT/backend/.env" API_DOCS_ENABLED)"
  if [[ "$_be_docs" != "$_api_docs" ]]; then
    log "Syncing API_DOCS_ENABLED=${_api_docs} → backend/.env"
    set_backend_env "API_DOCS_ENABLED" "$_api_docs"
  fi
  if [[ "$_api_docs" != "true" && "$_api_docs" != "1" ]]; then
    warn "Swagger is OFF. To enable /api/documentation set API_DOCS_ENABLED=true in docker/.env, then:
  cd $ROOT/docker && docker compose up -d --force-recreate --no-deps app"
  fi

  # Ensure APP_KEY exists (entrypoint can also generate; do it here for first-time)
  _be_key="$(env_file_get "$ROOT/backend/.env" APP_KEY)"
  if [[ "$_be_key" != base64:* ]]; then
    log "Generating APP_KEY in backend/.env"
    set_backend_env "APP_KEY" "base64:$(openssl rand -base64 32 | tr -d '\n')"
  fi
fi

# ---------------------------------------------------------------------------
# 1. Persistent data dirs (Postgres, Redis, Laravel storage)
# ---------------------------------------------------------------------------
log "Creating data directories under $DATA_PATH"
ensure_data_dirs() {
  mkdir -p \
    "$DATA_PATH/postgres" \
    "$DATA_PATH/redis" \
    "$DATA_PATH/storage/app/public" \
    "$DATA_PATH/storage/app/private" \
    "$DATA_PATH/storage/framework/cache/data" \
    "$DATA_PATH/storage/framework/sessions" \
    "$DATA_PATH/storage/framework/testing" \
    "$DATA_PATH/storage/framework/views" \
    "$DATA_PATH/storage/logs" \
    "$DATA_PATH/storage/api-docs"
}

if ensure_data_dirs 2>/dev/null; then
  :
else
  run_root bash -c "mkdir -p \
    '$DATA_PATH/postgres' \
    '$DATA_PATH/redis' \
    '$DATA_PATH/storage/app/public' \
    '$DATA_PATH/storage/app/private' \
    '$DATA_PATH/storage/framework/cache/data' \
    '$DATA_PATH/storage/framework/sessions' \
    '$DATA_PATH/storage/framework/testing' \
    '$DATA_PATH/storage/framework/views' \
    '$DATA_PATH/storage/logs' \
    '$DATA_PATH/storage/api-docs'"
fi

# One-time copy of existing repo storage into the persistent path (branding uploads, etc.)
if [[ ! -f "$DATA_PATH/storage/.initialized" ]]; then
  log "Initializing persistent storage from backend/storage (one-time)"
  if [[ -d "$ROOT/backend/storage/app" ]]; then
    if cp -a "$ROOT/backend/storage/app/." "$DATA_PATH/storage/app/" 2>/dev/null; then
      :
    else
      run_root cp -a "$ROOT/backend/storage/app/." "$DATA_PATH/storage/app/"
    fi
  fi
  if touch "$DATA_PATH/storage/.initialized" 2>/dev/null; then
    :
  else
    run_root touch "$DATA_PATH/storage/.initialized"
  fi
fi

fix_server_permissions "data-dirs"
log "Laravel storage → ${DATA_PATH}/storage (bind-mounted in app/queue/nginx)"

ensure_storage_link() {
  mkdir -p "$DATA_PATH/storage/app/public/branding" 2>/dev/null \
    || run_root mkdir -p "$DATA_PATH/storage/app/public/branding"
  local link="$ROOT/backend/public/storage"
  rm -f "$link" 2>/dev/null || run_root rm -f "$link" || true
  if ln -sfn "$DATA_PATH/storage/app/public" "$link" 2>/dev/null; then
    :
  else
    run_root ln -sfn "$DATA_PATH/storage/app/public" "$link" \
      || die "Could not create storage link at backend/public/storage"
  fi
  log "Storage link: backend/public/storage → ${DATA_PATH}/storage/app/public"
}

# Seed default logos into persistent public disk if missing (uploads create branding/ later)
ensure_default_branding_assets() {
  local dest="$DATA_PATH/storage/app/public/branding"
  local src="$ROOT/backend/storage/app/public/branding"
  mkdir -p "$dest" 2>/dev/null || run_root mkdir -p "$dest"
  if [[ -d "$src" ]]; then
    for f in logo.png logo-dark.png; do
      if [[ -f "$src/$f" && ! -f "$dest/$f" ]]; then
        cp -a "$src/$f" "$dest/$f" 2>/dev/null || run_root cp -a "$src/$f" "$dest/$f" || true
        log "Seeded default branding asset: $f"
      fi
    done
  fi
  if [[ -f "$ROOT/backend/storage/app/public/branding-logo.png" && ! -f "$DATA_PATH/storage/app/public/branding-logo.png" ]]; then
    cp -a "$ROOT/backend/storage/app/public/branding-logo.png" \
      "$DATA_PATH/storage/app/public/branding-logo.png" 2>/dev/null \
      || run_root cp -a "$ROOT/backend/storage/app/public/branding-logo.png" \
        "$DATA_PATH/storage/app/public/branding-logo.png" || true
  fi
  chown -R 33:33 "$DATA_PATH/storage/app/public" 2>/dev/null \
    || run_root chown -R 33:33 "$DATA_PATH/storage/app/public" || true
}

ensure_storage_link
ensure_default_branding_assets
fix_server_permissions "after-storage-link"

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
  local db_pass
  db_pass="$(env_file_get "$ROOT/docker/.env" DB_PASSWORD)"
  [[ -n "$db_pass" ]] || return 0
  if [[ -f "$ROOT/backend/.env" ]]; then
    set_backend_env "DB_PASSWORD" "$db_pass"
    log "Synced DB_PASSWORD into backend/.env"
  fi
}

docker_env_get() {
  env_file_get "$ROOT/docker/.env" "$1"
}

sql_escape() {
  # Escape \ and ' for SQL string literals
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\'/\\\'}"
  printf '%s' "$s"
}

wait_for_postgres_healthy() {
  local i
  log "Waiting for Postgres container to be healthy"
  for i in $(seq 1 36); do
    if "${COMPOSE[@]}" ps postgres 2>/dev/null | grep -qi 'healthy'; then
      return 0
    fi
    sleep 5
  done
  warn "Postgres did not report healthy — continuing anyway"
  return 0
}

redis_is_healthy() {
  "${COMPOSE[@]}" ps redis 2>/dev/null | grep -qi 'healthy'
}

reset_redis_data_volume() {
  local data_path="${EMAIL_SERVER_DATA_PATH:-$DATA_PATH}"
  local env_path
  env_path="$(docker_env_get EMAIL_SERVER_DATA_PATH || true)"
  [[ -n "$env_path" ]] && data_path="$env_path"

  log "RESET REDIS: stopping redis and wiping ${data_path}/redis (queues/cache only)"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" stop redis || true
    "${COMPOSE[@]}" rm -f redis || true
  )
  if [[ -d "${data_path}/redis" ]]; then
    if rm -rf "${data_path}/redis"/* 2>/dev/null; then
      :
    else
      run_root rm -rf "${data_path}/redis"/*
    fi
  else
    mkdir -p "${data_path}/redis" 2>/dev/null || run_root mkdir -p "${data_path}/redis"
  fi
  chown -R 999:999 "${data_path}/redis" 2>/dev/null || run_root chown -R 999:999 "${data_path}/redis"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate redis
  )
}

wait_for_redis_healthy() {
  local i
  log "Waiting for Redis container to be healthy"
  for i in $(seq 1 24); do
    if redis_is_healthy; then
      return 0
    fi
    sleep 2
  done
  return 1
}

ensure_redis_ready() {
  if [[ "$RESET_REDIS" == "true" ]]; then
    reset_redis_data_volume
    wait_for_redis_healthy && return 0
    die "Redis still unhealthy after --reset-redis. Check: cd $ROOT/docker && docker compose logs redis --tail 80"
  fi

  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d redis
  )

  if wait_for_redis_healthy; then
    log "Redis OK"
    return 0
  fi

  warn "Redis unhealthy — trying ownership fix (uid 999) and recreate"
  chown -R 999:999 "$DATA_PATH/redis" 2>/dev/null || run_root chown -R 999:999 "$DATA_PATH/redis" || true
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate redis
  )
  if wait_for_redis_healthy; then
    log "Redis OK after permission fix"
    return 0
  fi

  warn "Redis still unhealthy — logs:"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" logs redis --tail 40
  ) >&2 || true

  die "Redis container is unhealthy (blocks app + queue).

Fix on the server:
  cd $ROOT/docker
  docker compose logs redis --tail 80

Usually caused by bad permissions or corrupt AOF in ${DATA_PATH}/redis.
Safe recovery (queues/cache only — does NOT touch Postgres):
  cd $ROOT && ./setup.sh --reset-redis

Or manually:
  cd $ROOT/docker && docker compose stop redis
  sudo rm -rf ${DATA_PATH}/redis/*
  sudo chown -R 999:999 ${DATA_PATH}/redis
  docker compose up -d --remove-orphans redis
"
}

# Must test with the SAME credentials Laravel uses (container env + backend/.env).
postgres_app_auth_ok() {
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

# Wipe bind-mounted Postgres data and recreate so POSTGRES_PASSWORD from docker/.env applies.
reset_postgres_data_volume() {
  local data_path="${EMAIL_SERVER_DATA_PATH:-$DATA_PATH}"
  local env_path
  env_path="$(docker_env_get EMAIL_SERVER_DATA_PATH || true)"
  [[ -n "$env_path" ]] && data_path="$env_path"

  log "RESET POSTGRES: stopping postgres and wiping ${data_path}/postgres (DESTROYS DB DATA)"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" stop postgres || true
    "${COMPOSE[@]}" rm -f postgres || true
  )
  if [[ -d "${data_path}/postgres" ]]; then
    run_root rm -rf "${data_path}/postgres"
  fi
  run_root mkdir -p "${data_path}/postgres"
  # Official postgres image runs as uid 70 (alpine) or 999 — allow either
  run_root chown -R 70:70 "${data_path}/postgres" 2>/dev/null || true
  run_root chown -R 999:999 "${data_path}/postgres" 2>/dev/null || true

  log "Starting fresh Postgres with passwords from docker/.env"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate postgres
  )
  wait_for_postgres_healthy

  local tries=1
  while [[ "$tries" -le 30 ]]; do
    if postgres_app_auth_ok; then
      break
    fi
    tries=$((tries + 1))
    sleep 2
  done

  if ! postgres_app_auth_ok; then
    # App may not be up yet during first boot — check via psql inside postgres
    if ! "${COMPOSE[@]}" exec -T postgres pg_isready -U email_server -d email_server >/dev/null 2>&1; then
      die "Fresh Postgres is not ready — check docker compose logs postgres"
    fi
  fi

  log "Recreating app + queue after Postgres reset"
  (
    cd "$ROOT/docker"
    "${COMPOSE[@]}" up -d --force-recreate --no-deps app
    "${COMPOSE[@]}" up -d --force-recreate --no-deps --scale "queue=${QUEUE_SCALE:-1}" queue
  )
  sleep 5
  postgres_app_auth_ok || die "Laravel still cannot connect to Postgres after app recreate"
  log "Fresh Postgres ready with current DB_PASSWORD"
}

# Postgres only applies POSTGRES_PASSWORD on first volume init.
# Never auto-wipe — use --reset-postgres explicitly.
sync_postgres_volume_password() {
  local db_pass
  db_pass="$(docker_env_get DB_PASSWORD)"
  [[ -n "$db_pass" ]] || die "DB_PASSWORD missing from docker/.env"

  if [[ "$RESET_POSTGRES" == "true" ]]; then
    warn "--reset-postgres set: wiping Postgres data and re-initializing (DESTROYS DB DATA)"
    reset_postgres_data_volume
    sync_backend_db_password
    log "Running migrations after Postgres reset"
    "${COMPOSE[@]}" exec -T app php artisan migrate --force \
      || warn "migrate still failing — check app logs"
    return 0
  fi

  wait_for_postgres_healthy

  if ! "${COMPOSE[@]}" ps app 2>/dev/null | grep -qi 'Up'; then
    warn "App container not Up yet — waiting briefly for network auth check"
    sleep 5
  fi

  if postgres_app_auth_ok; then
    log "Postgres app auth OK (Laravel → postgres:5432)"
    return 0
  fi

  warn "Laravel cannot connect to Postgres with current DB_PASSWORD"

  die "Postgres credentials in docker/.env do not match the existing data volume.

Data was NOT wiped. Fix (pick one):

  A) Put the ORIGINAL DB_PASSWORD back into docker/.env and backend/.env,
     then re-run ./setup.sh (preserves data).

  B) Explicitly wipe and re-seed (DESTROYS DATA — only if you accept losing DB):
       ./setup.sh --reset-postgres

  C) Manual wipe:
       cd $ROOT/docker && docker compose stop postgres
       sudo rm -rf ${DATA_PATH}/postgres && sudo mkdir -p ${DATA_PATH}/postgres
       docker compose up -d
"
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
      # /up does NOT check Postgres — also require API health DB=ok before continuing
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

fix_server_permissions "pre-compose"
log "Starting Docker stack"
ensure_redis_ready
(
  cd "$ROOT/docker"
  # --remove-orphans drops leftover containers from old compose project names / scale changes
  RUN_SEEDER=false "${COMPOSE[@]}" up -d --build --remove-orphans --scale "queue=${QUEUE_SCALE}"
)

# Align Postgres volume with docker/.env BEFORE health/seed
sync_postgres_volume_password

if ! wait_for_api 45; then
  die "API did not become healthy.

Try these on the server:
  cd $ROOT/docker
  docker compose logs app --tail 100
  docker compose ps

If DB auth fails, set DB_PASSWORD in docker/.env + backend/.env to the ORIGINAL
Postgres volume password, or reset the volume (DESTROYS DATA):
  ./setup.sh --reset-postgres
"
fi

fix_server_permissions "post-up"

log "Ensuring Laravel storage:link in app container"
"${COMPOSE[@]}" exec -T app php artisan storage:link --force \
  && log "storage:link OK" \
  || warn "storage:link failed in container — check app logs"

# Pre-generate OpenAPI JSON for Swagger UI (non-fatal)
log "Generating OpenAPI spec for /api/docs.json"
"${COMPOSE[@]}" exec -T app php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  try {
    $openapi = OpenApi\Generator::scan([app_path("OpenApi")]);
    $dir = storage_path("api-docs");
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    file_put_contents($dir."/openapi.json", $openapi->toJson());
    echo "openapi_ok\n";
  } catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
  }
' && log "OpenAPI cached at storage/api-docs/openapi.json" \
  || warn "OpenAPI generation failed — /api/docs.json will try live scan"

# Seed / ensure admin after API is alive
if [[ "$RUN_SEEDER" == "true" ]]; then
  log "Seeding database / ensuring admin user (password = ADMIN_PASSWORD from secrets)"
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
      die "Could not seed/upsert admin — Postgres auth or migrate likely still failing.
Re-run with matching DB_PASSWORD, or:
  cd $ROOT && ./setup.sh --reset-postgres
"
    fi
  fi

  # Prove the seeded password works (same path curl uses)
  log "Verifying admin login with seeded password"
  verify_code="$("${COMPOSE[@]}" exec -T \
    -e ADMIN_EMAIL="$ADMIN_EMAIL" \
    -e ADMIN_PASSWORD="$ADMIN_PASSWORD" \
    app php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      $u = App\Models\User::query()->where("email", getenv("ADMIN_EMAIL"))->first();
      if (!$u) { fwrite(STDERR, "admin user missing\n"); exit(2); }
      if (!Illuminate\Support\Facades\Hash::check(getenv("ADMIN_PASSWORD"), $u->password)) {
        fwrite(STDERR, "ADMIN_PASSWORD hash mismatch\n"); exit(3);
      }
      echo "admin_password_ok\n";
    ' 2>&1)" || true
  if ! printf '%s' "$verify_code" | grep -q 'admin_password_ok'; then
    die "Admin password verification failed after seed:
$verify_code

The password that works is ADMIN_PASSWORD from docker/.env —
not a placeholder like change-me-in-production."
  fi
  log "Admin password verified for ${ADMIN_EMAIL}"
fi

# Disable reseed for subsequent boots
if [[ "$RUN_SEEDER" == "true" ]]; then
  log "Setting RUN_SEEDER=false for subsequent starts"
  set_docker_env "RUN_SEEDER" "false"
  set_docker_env "ADMIN_RESET_PASSWORD" "false"
fi

# ---------------------------------------------------------------------------
# 5. Host reverse proxy (nginx default, or apache)
# ---------------------------------------------------------------------------
if [[ "$SKIP_NGINX" == "true" || "$SKIP_REVERSE_PROXY" == "true" ]]; then
  warn "Skipping host reverse-proxy site install"
else
  case "$(printf '%s' "$REVERSE_PROXY" | tr '[:upper:]' '[:lower:]')" in
    apache)
      install_apache_reverse_proxy
      ;;
    nginx|*)
      REVERSE_PROXY=nginx
      install_nginx_reverse_proxy
      ;;
  esac
fi

# ---------------------------------------------------------------------------
# 6. Certbot SSL (+ auto-renewal)
# ---------------------------------------------------------------------------
if [[ "$SKIP_NGINX" == "true" || "$SKIP_REVERSE_PROXY" == "true" ]]; then
  if [[ "$SKIP_SSL" != "true" ]]; then
    warn "Reverse proxy skipped — SSL install may still require a working :80 vhost"
    install_host_ssl
  else
    warn "Skipping SSL (--skip-ssl)"
  fi
else
  install_host_ssl
fi

# Persist reverse-proxy choice for next runs (docker/.env)
if [[ -f "$ROOT/docker/.env" ]]; then
  set_docker_env "REVERSE_PROXY" "$REVERSE_PROXY"
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
  Proxy    : ${REVERSE_PROXY}

  Admin email : ${ADMIN_EMAIL}
  Admin pass  : (value from docker/.env ADMIN_PASSWORD — not printed)

  Env files used (gitignored — edit these manually on the server):
    ${ROOT}/docker/.env
    ${ROOT}/backend/.env

  Data path:
    ${DATA_PATH}/{postgres,redis,storage}
    (Laravel storage is bind-mounted from ${DATA_PATH}/storage)

  Certbot certificate (if SSL enabled):
    /etc/letsencrypt/live/${DOMAIN}/fullchain.pem
    /etc/letsencrypt/live/${DOMAIN}/privkey.pem

Next steps:
  1. Sign in with ADMIN_EMAIL / ADMIN_PASSWORD from docker/.env
  2. Enable 2FA
  3. Keep .env files out of git
========================================================================
EOF
