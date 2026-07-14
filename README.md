# Email Server

Laravel 13 email gateway with **Microsoft Exchange (Graph API)** and **SMTP** support, DB-managed provider configs, and a Vue 3 + Vuetify admin panel (styled like Staff Helpdesk).

## Ports

| Service | URL |
|---------|-----|
| **Admin UI (Vue)** | http://localhost:3006 |
| **API + Swagger** | http://localhost:8082 |
| **Health (incl. Redis)** | http://localhost:8082/api/v1/health |
| **Swagger UI** | http://localhost:8082/api/documentation |

## Features

- **Email providers** stored in MySQL (Exchange default, SMTP, SES, log)
- **Exchange transport** — same Microsoft Graph pattern as `staff/apm` and `staff/helpdesk`
- **External integrations** — JWT auth (24h) for APM, Helpdesk, and other systems
- **Admin panel** — Vue 3 + Vuetify on port **3006**
- **Swagger/OpenAPI** — interactive API docs at `/api/documentation`
- **Async email queue** — Redis + workers; HTTP returns immediately with `pending` status
- **Production tuning** — PHP-FPM, MySQL, Apache configs from [enterprise optimisation guide](https://github.com/agabaandre/PHP_laravel_Codeigniter_wordpress_server_optimisation_enterprise/blob/main/docs/MANUAL-OPTIMIZATION-PHP82-MYSQL8.md)
- **Docker** — PHP 8.4, Nginx, **Redis** (queue/cache/sessions), MySQL, scalable queue workers

## Quick start (Docker)

```bash
# 1. Copy env and add Exchange credentials (from staff/apm/.env or helpdesk)
cp backend/.env.example backend/.env

# 2. Build frontend
cd frontend && npm ci && npm run build && cd ..

# 3. Start stack
docker compose -f docker/docker-compose.yml up -d --build

# Optional: scale email workers for higher throughput
docker compose -f docker/docker-compose.yml up -d --scale queue=4

# 4. Open apps
open http://localhost:3006          # Admin UI
open http://localhost:8082/api/documentation  # Swagger
```

**Default admin login:** `admin@emailserver.local` / `password`

After seeding, check container logs for the integration API key:

```bash
docker logs email-server-app 2>&1 | grep ems_
```

## API

Interactive documentation: **http://localhost:8082/api/documentation** (OpenAPI spec at `/api/docs.json`, generated from annotations in `app/OpenApi/`).

### Admin (Bearer token from `/api/v1/admin/auth/login`)

- `GET /api/v1/admin/dashboard`
- `CRUD /api/v1/admin/email-providers`
- `POST /api/v1/admin/email-providers/{id}/test`
- `CRUD /api/v1/admin/external-integrations`

### External systems (JWT — 24 hour tokens)

**1. Exchange integration credentials for a JWT**

```http
POST /api/v1/integrations/auth/token
Content-Type: application/json

{
  "client_id": "staff-portal",
  "client_secret": "ems_..."
}
```

Response includes `token`, `expires_in` (seconds), and `expires_at`. Tokens expire after **24 hours** (`JWT_TTL=1440` minutes).

**2. Send email with the JWT** (returns immediately with `status: pending`)

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

**3. Poll delivery status**

```http
GET /api/v1/integrations/logs/{log_id}
Authorization: Bearer <jwt>
```

Emails are processed asynchronously by Redis queue workers — Exchange/SMTP calls do not block the API thread.

Set `JWT_SECRET` in `.env` (use a long random string; falls back to `APP_KEY` if unset).

## Development

```bash
# API via Docker (port 8082)
docker compose -f docker/docker-compose.yml up -d

# Frontend dev server on :3006 (proxies /api to :8082)
cd frontend && npm run dev
```

## Exchange configuration

Provider settings mirror Staff portal apps:

| Field | Env (seed) |
|-------|------------|
| Tenant ID | `EXCHANGE_TENANT_ID` |
| Client ID | `EXCHANGE_CLIENT_ID` |
| Client secret | `EXCHANGE_CLIENT_SECRET` |
| Auth method | `client_credentials` (default) |
| Scope | `https://graph.microsoft.com/.default` |

Copy values from `/opt/homebrew/var/www/staff/apm/.env` or `staff/helpdesk/backend/.env`, then update via the admin UI (stored encrypted in DB).

## Production & scaling

See **[deploy/DEPLOY.md](deploy/DEPLOY.md)** for:

- Apache 2.4 + PHP-FPM + MySQL 8 bare-metal setup (1000+ request capacity)
- Supervisor queue workers
- Security hardening checklist
- Docker worker scaling (`--scale queue=4`)
