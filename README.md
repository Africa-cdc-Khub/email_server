# Email Server

Laravel email gateway with **Microsoft Exchange (Graph API)** and **SMTP**, DB-managed providers, JWT integrations, and a Vue 3 + Vuetify admin panel.

## Production domain

| Role | URL |
|------|-----|
| Admin UI | https://notifications.africacdc.org |
| API | https://notifications.africacdc.org/api |
| Health | https://notifications.africacdc.org/api/v1/health |

Swagger/OpenAPI at `/api/documentation` is enabled when `API_DOCS_ENABLED=true` (default in `docker/.env.example`). Set it in **`docker/.env`** and recreate the app — Compose injects that value into the container (it overrides `backend/.env`).

---

## Production installation (Docker + host Nginx)

Preferred path: one script. Secrets are passed as parameters or an external env file — **never committed**.

### Architecture

```
Internet
   │
   ▼
Host Nginx :443  (notifications.africacdc.org) + Certbot TLS
   │
   ├── /          → 127.0.0.1:3006  (Admin UI container)
   └── /api/      → 127.0.0.1:8089  (API container)
                        │
              Docker: app, queue, redis, mysql
              Data:   /home/email_serverdata/{mysql,redis,storage}
```

### Prerequisites

- Linux server with Docker + Docker Compose plugin
- Host **Nginx** and **Certbot** already installed
- DNS **A/AAAA** for `notifications.africacdc.org` pointing at the server
- Ports **80/443** open on the host firewall

### Deploy with `setup.sh` (recommended)

```bash
sudo mkdir -p /var/lib/SYSTEMS
sudo git clone <YOUR_REPO_URL> /var/lib/SYSTEMS/email_server
cd /var/lib/SYSTEMS/email_server
```

**Edit `.env` files manually, then run setup** (setup does **not** overwrite them):

```bash
# First time only
cp -n docker/.env.example docker/.env
cp -n backend/.env.example backend/.env
chmod 600 docker/.env backend/.env

# Put real secrets here (ADMIN_PASSWORD, DB_PASSWORD, MYSQL_ROOT_PASSWORD, JWT_SECRET, …)
nano docker/.env
# Exchange / mail / APP_URL etc.
nano backend/.env

./setup.sh
```

What `setup.sh` does:

1. Reads existing `docker/.env` + `backend/.env` (never overwrites unless `--write-env`)
2. Creates `/home/email_serverdata/{mysql,redis,storage}`
3. Builds the Vue admin UI (**Docker `node` image if host `npm` is missing**)
4. Starts Docker (`app`, `queue`, `nginx`, `frontend`, `mysql`, `redis`)
5. Seeds admin user from `ADMIN_PASSWORD` in `docker/.env`, then sets `RUN_SEEDER=false`
6. Installs host Nginx site + security headers for the domain
7. Issues/installs Let’s Encrypt TLS with Certbot (`--nginx --redirect`)
8. Prints the admin URL (never prints passwords)

```bash
./setup.sh --help
```

Useful flags: `--skip-ssl`, `--skip-nginx`, `--skip-frontend-build`, `--frontend-build=docker`, `--run-seeder=false`, `--force-vendor`, `--reset-mysql` (destroys DB data).

After deploy:

1. Open https://notifications.africacdc.org and sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `docker/.env`
2. Enable **2FA**
3. Create/rotate integration secrets in the admin UI
4. Keep `docker/.env` and `backend/.env` **out of git**

### Manual steps (if you prefer not to use `setup.sh`)

#### 1. Clone the repository

```bash
sudo mkdir -p /var/www
sudo git clone <YOUR_REPO_URL> /var/www/email_server
cd /var/www/email_server
```

#### 2. Create persistent data directories

```bash
sudo mkdir -p /home/email_serverdata/{mysql,redis,storage}
sudo chown -R root:root /home/email_serverdata
```

MySQL and Redis bind-mount here so `docker compose down` does **not** wipe data.

#### 3. Configure Docker environment

```bash
cp docker/.env.example docker/.env
cp backend/.env.example backend/.env
```

Edit **`docker/.env`** (required for Compose):

```env
APP_ENV=production
APP_DEBUG=false
RUN_SEEDER=true

EMAIL_SERVER_DATA_PATH=/home/email_serverdata

APP_URL=https://notifications.africacdc.org
FRONTEND_URL=https://notifications.africacdc.org

ADMIN_EMAIL=andrewa@africacdc.org
ADMIN_PASSWORD=<strong-unique-password>

DB_PASSWORD=<strong-db-password>
MYSQL_ROOT_PASSWORD=<strong-root-password>

JWT_SECRET=<64+-char-random-string>
JWT_TTL=60
```

Generate secrets:

```bash
openssl rand -base64 48   # JWT_SECRET
openssl rand -base64 24   # DB / admin passwords
```

Edit **`backend/.env`** for Exchange/mail (used by the app container via bind mount):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://notifications.africacdc.org
FRONTEND_URL=https://notifications.africacdc.org

MAIL_MAILER=exchange
MAIL_FROM_ADDRESS=notifications@africacdc.org
MAIL_FROM_NAME="Africa CDC Notifications"

EXCHANGE_TENANT_ID=...
EXCHANGE_CLIENT_ID=...
EXCHANGE_CLIENT_SECRET=...
EXCHANGE_AUTH_METHOD=client_credentials
EXCHANGE_SCOPE=https://graph.microsoft.com/.default

JWT_SECRET=<same-as-docker/.env>
```

Generate the Laravel app key once containers are up (or after first start):

```bash
docker compose -f docker/docker-compose.yml exec app php artisan key:generate --force
```

> Set `RUN_SEEDER=false` in `docker/.env` **after** the first successful boot so reseeds do not overwrite production data.

#### 4. Pass public URLs into Compose

`docker/docker-compose.yml` reads `APP_URL` / `FRONTEND_URL` from the environment (or defaults to localhost). Export them before starting, or add them to `docker/.env` (Compose loads that file automatically when run from `docker/`):

```bash
# recommended: run Compose from the docker directory so .env is picked up
cd /var/www/email_server/docker
```

Ensure `docker/.env` also includes:

```env
APP_URL=https://notifications.africacdc.org
FRONTEND_URL=https://notifications.africacdc.org
```

If those keys are only in `backend/.env`, add matching keys to `docker/.env` as well — Compose substitutes `${APP_URL}` from `docker/.env`.

#### 5. Build the admin UI and start Docker

```bash
cd /var/www/email_server/frontend
npm ci
npm run build

cd /var/www/email_server/docker
docker compose up -d --build

# optional: more email workers under load
docker compose up -d --scale queue=4
```

Verify containers:

```bash
docker compose ps
curl -s http://127.0.0.1:8089/api/v1/health
```

#### 6. Host Nginx reverse proxy (HTTP first)

Install the HTTP site so Certbot can complete the ACME challenge:

```bash
sudo cp /var/www/email_server/deploy/configs/nginx-notifications.africacdc.org.conf \
  /etc/nginx/sites-available/notifications.africacdc.org.conf

sudo ln -sf /etc/nginx/sites-available/notifications.africacdc.org.conf \
  /etc/nginx/sites-enabled/notifications.africacdc.org.conf

sudo nginx -t && sudo systemctl reload nginx
```

Confirm HTTP reaches the app before requesting a certificate:

```bash
curl -I http://notifications.africacdc.org/api/v1/health
```

#### 7. SSL certificate with Certbot (already installed)

Issue a Let’s Encrypt certificate and let Certbot wire HTTPS into the Nginx site automatically:

```bash
# Confirm Certbot is available (already installed on Africa CDC servers)
certbot --version
# expect something like: certbot 2.x.x

# Issue certificate + configure Nginx HTTPS + HTTP→HTTPS redirect
sudo certbot --nginx \
  -d notifications.africacdc.org \
  --agree-tos \
  --redirect \
  -m andrewa@africacdc.org \
  --non-interactive
```

What Certbot does:

1. Obtains a certificate for `notifications.africacdc.org`
2. Stores files under:
   - `/etc/letsencrypt/live/notifications.africacdc.org/fullchain.pem`
   - `/etc/letsencrypt/live/notifications.africacdc.org/privkey.pem`
3. Updates the Nginx site to listen on **443** with TLS
4. Adds an **HTTP → HTTPS** redirect on port 80

Verify HTTPS:

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -I https://notifications.africacdc.org/api/v1/health
openssl s_client -connect notifications.africacdc.org:443 -servername notifications.africacdc.org </dev/null 2>/dev/null | openssl x509 -noout -dates -subject
```

#### Certificate auto-renewal

Certbot installs a systemd timer/cron job. Confirm renewal works:

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot || ls /etc/cron.d/certbot 2>/dev/null
```

Certificates renew automatically before expiry. After a successful renew, Nginx is reloaded by Certbot’s deploy hook / `nginx` plugin.

#### If the certificate already exists

```bash
sudo certbot certificates
sudo certbot install --nginx -d notifications.africacdc.org
# or force reissue:
sudo certbot --nginx -d notifications.africacdc.org --force-renewal --redirect
```

#### Manual HTTPS snippet (reference only)

If you need to inspect/edit TLS by hand after Certbot, paths look like:

```nginx
listen 443 ssl http2;
listen [::]:443 ssl http2;
server_name notifications.africacdc.org;

ssl_certificate     /etc/letsencrypt/live/notifications.africacdc.org/fullchain.pem;
ssl_certificate_key /etc/letsencrypt/live/notifications.africacdc.org/privkey.pem;
include /etc/letsencrypt/options-ssl-nginx.conf;
ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
```

Keep `APP_URL` and `FRONTEND_URL` as `https://notifications.africacdc.org` so Laravel generates correct absolute links and HSTS-related headers behave correctly.

#### 8. First login

1. Open https://notifications.africacdc.org
2. Sign in with `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `docker/.env`
3. Enable **2FA** under Security
4. Create/rotate **integration** secrets under Integrations
5. Set `RUN_SEEDER=false` and recreate the app container if needed:

```bash
cd /var/www/email_server/docker
# edit docker/.env → RUN_SEEDER=false
docker compose up -d app queue
```

#### 9. Day-2 operations

```bash
cd /var/www/email_server/docker

# logs
docker compose logs -f app queue nginx

# update code
cd /var/www/email_server && git pull
cd frontend && npm ci && npm run build
cd ../docker && docker compose up -d --build

# migrations
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache

# scale workers
docker compose up -d --scale queue=4
```

**Do not** run `docker compose down -v` — that can destroy named volumes. With bind mounts under `/home/email_serverdata`, a normal `down` keeps MySQL/Redis/storage data.

---

## Local development (Docker)

| Service | URL |
|---------|-----|
| Admin UI | http://localhost:3006 |
| API | http://localhost:8089 |
| Health | http://localhost:8089/api/v1/health |
| Swagger (non-prod only) | http://localhost:8089/api/documentation |

```bash
cp docker/.env.example docker/.env
cp backend/.env.example backend/.env
# fill ADMIN_PASSWORD, DB_PASSWORD, MYSQL_ROOT_PASSWORD, JWT_SECRET

cd frontend && npm ci && npm run build && cd ..
cd docker && docker compose up -d --build
```

Frontend hot-reload:

```bash
docker compose -f docker/docker-compose.yml up -d
cd frontend && npm run dev
```

---

## API overview

### Admin (Sanctum Bearer from login)

- `POST /api/v1/admin/auth/login`
- `GET /api/v1/admin/dashboard`
- `CRUD /api/v1/admin/email-providers`
- `CRUD /api/v1/admin/external-integrations`
- `POST /api/v1/admin/send-mail`

### Integrations (JWT)

**1. Token**

```http
POST /api/v1/integrations/auth/token
Content-Type: application/json

{
  "client_id": "staff-portal",
  "client_secret": "<secret-from-admin-ui>"
}
```

**2. Send** (async — returns `pending`)

```http
POST /api/v1/integrations/send
Authorization: Bearer <jwt>
Content-Type: application/json

{
  "to": "user@example.com",
  "subject": "Hello",
  "body": "<p>HTML body</p>",
  "is_html": true
}
```

**3. Status**

```http
GET /api/v1/integrations/logs/{log_id}
Authorization: Bearer <jwt>
```

`JWT_TTL` defaults to **60 minutes**. Set a dedicated `JWT_SECRET` (≥32 characters); do not reuse `APP_KEY`.

---

## Exchange configuration

| Field | Env |
|-------|-----|
| Tenant ID | `EXCHANGE_TENANT_ID` |
| Client ID | `EXCHANGE_CLIENT_ID` |
| Client secret | `EXCHANGE_CLIENT_SECRET` |
| Auth method | `client_credentials` |
| Scope | `https://graph.microsoft.com/.default` |

Also configure/provider-edit in the admin UI (stored encrypted in DB). Env values take precedence when set.

---

## Security checklist (production)

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] Strong unique `ADMIN_PASSWORD`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `JWT_SECRET`
- [ ] `RUN_SEEDER=false` after first seed
- [ ] Host Nginx only; Docker ports bound via Compose to host (`8089`/`3006`) — prefer firewall so they are not public
- [ ] TLS via Certbot on `notifications.africacdc.org` (`fullchain.pem` / `privkey.pem` under `/etc/letsencrypt/live/...`)
- [ ] Certbot auto-renew verified (`sudo certbot renew --dry-run`)
- [ ] Enable admin 2FA
- [ ] Integration IP allowlists where possible
- [ ] Rotate integration secrets periodically

Additional Apache/bare-metal notes: **[deploy/DEPLOY.md](deploy/DEPLOY.md)**.
