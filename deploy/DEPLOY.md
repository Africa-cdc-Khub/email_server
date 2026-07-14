# Production deployment (Apache + PHP-FPM + MySQL 8)

Configs in `deploy/configs/` are copied from the [enterprise optimisation guide](https://github.com/agabaandre/PHP_laravel_Codeigniter_wordpress_server_optimisation_enterprise/blob/main/docs/MANUAL-OPTIMIZATION-PHP82-MYSQL8.md) and adapted for **PHP 8.4** and this Laravel app.

Target capacity: **400+ Apache workers**, **56 PHP-FPM children**, **300 MySQL connections** — suitable for **1000+ concurrent HTTP requests** when combined with **Redis queues** for email (Graph/SMTP never blocks the request thread).

---

## Docker (recommended)

```bash
cp backend/.env.example backend/.env
# Set APP_KEY, JWT_SECRET, Exchange creds, strong DB_PASSWORD

cd frontend && npm ci && npm run build && cd ..

docker compose -f docker/docker-compose.yml up -d --build

# Scale queue workers for higher email throughput
docker compose -f docker/docker-compose.yml up -d --scale queue=4
```

| Service | Port | Role |
|---------|------|------|
| nginx | 8082 | API + Swagger |
| frontend | 3006 | Admin UI |
| queue | — | Async email (`SendEmailJob`) |
| redis | — | Queue, cache, sessions, rate limits |
| mysql | — | Tuned via `mysqld-docker.cnf` |

---

## Bare metal (Apache 2.4 + PHP 8.4 FPM)

Follow the [manual optimisation guide](https://github.com/agabaandre/PHP_laravel_Codeigniter_wordpress_server_optimisation_enterprise/blob/main/docs/MANUAL-OPTIMIZATION-PHP82-MYSQL8.md), substituting **8.4** for 8.2 where paths differ.

### 1. Copy configs

```bash
sudo cp deploy/configs/mysqld-production.cnf /etc/mysql/mysql.conf.d/99-email-server.cnf
sudo cp deploy/configs/php-production.ini /etc/php/8.4/fpm/conf.d/99-email-server.ini
sudo cp deploy/configs/php-fpm-www.conf /etc/php/8.4/fpm/pool.d/www.conf
sudo sed -i 's|127.0.0.1:9000|/run/php/php8.4-fpm.sock|g' /etc/php/8.4/fpm/pool.d/www.conf
sudo cp deploy/configs/apache-mpm.conf /etc/apache2/mods-available/mpm_event.conf
sudo cp deploy/configs/apache-vhost-laravel.conf /etc/apache2/sites-available/email-server.conf
```

Edit vhost `ServerName` and `DocumentRoot` to your deploy path.

### 2. Enable Apache modules

```bash
sudo a2dismod mpm_prefork 2>/dev/null || true
sudo a2enmod mpm_event ssl rewrite headers proxy proxy_fcgi setenvif http2 expires deflate
sudo a2enconf php8.4-fpm
sudo a2ensite email-server
sudo a2dissite 000-default
```

### 3. Redis (required)

Docker includes Redis automatically. For bare metal:

```bash
sudo apt install -y redis-server
sudo systemctl enable redis-server
```

`.env` must use separate Redis databases:

```
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_QUEUE_CONNECTION=queue
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

Health check: `GET /api/v1/health` or `GET /api/health`

### 4. Laravel app

```bash
cd /var/www/email_server/backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

Set in `.env`:

```
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
```

### 5. Queue workers (Supervisor)

```ini
[program:email-server-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/email_server/backend/artisan queue:work redis --queue=emails,default --sleep=1 --tries=3 --timeout=120
autostart=true
autorestart=true
numprocs=4
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/email-server/queue.log
```

```bash
sudo mkdir -p /var/log/email-server
sudo supervisorctl reread && sudo supervisorctl update
```

### 6. After code deploy

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
sudo systemctl reload php8.4-fpm   # OPcache validate_timestamps=0
```

### 7. SSL

Use Certbot per guide Step 13, then reload Apache.

---

## Security checklist

- [ ] `APP_DEBUG=false`, strong `APP_KEY` and `JWT_SECRET`
- [ ] `.env` not web-accessible (Apache vhost blocks it)
- [ ] `RUN_SEEDER=false` in production Docker
- [ ] UFW: allow 80/443 only
- [ ] Fail2ban on Apache (guide Step 4)
- [ ] Integration IP allowlists where possible
- [ ] Rotate integration API keys periodically

---

## Email flow (async)

```
POST /api/v1/integrations/send  → 201/pending log  →  HTTP returns immediately
                              ↓
                    Redis queue (emails)
                              ↓
              queue:work → SendEmailJob → Graph/SMTP
                              ↓
              GET /api/v1/integrations/logs/{id}  →  sent | failed
```

This prevents Exchange Graph latency from hanging PHP-FPM workers under load.
