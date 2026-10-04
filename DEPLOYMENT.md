# HMS Deployment Guide

Pre-deployment checklist and instructions for the Multi-Branch Hotel Management System.

---

## Prerequisites

### DigitalOcean Droplet

- **OS:** Ubuntu 22.04 LTS or newer
- **RAM:** Minimum 2 GB (4 GB recommended for Reverb + Queue workers)
- **Storage:** Minimum 20 GB SSD
- **Docker:** `docker` and `docker compose` must be installed
- **Domain:** DNS A record pointing to the Droplet IP

### Install Docker on Droplet

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Docker (includes the Compose v2 plugin — do NOT install the
# legacy standalone docker-compose binary)
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh

# Add deploy user to docker group
sudo usermod -aG docker deploy

# Verify
docker --version
docker compose version
```

### Install Certbot (SSL)

```bash
sudo apt install -y certbot
```

---

## Server Setup

### 1. Clone the Repository

```bash
cd /opt
sudo git clone <your-repo-url> hms
sudo chown -R deploy:deploy hms
cd hms
```

### 2. Create `.env` File

```bash
cp .env.example .env
php artisan key:generate  # or manually generate and paste
```

Edit `.env` with production values:

```env
APP_NAME="HMS"
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY_HERE
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=hotel_system
DB_USERNAME=postgres
DB_PASSWORD=YOUR_STRONG_PASSWORD

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

REDIS_HOST=redis
REDIS_PASSWORD=YOUR_STRONG_PASSWORD

REVERB_APP_ID=YOUR_REVERB_APP_ID
REVERB_APP_KEY=YOUR_REVERB_APP_KEY
REVERB_APP_SECRET=YOUR_REVERB_APP_SECRET
REVERB_HOST=your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
```

### 3. Set the Domain

The Nginx config is rendered from a template at boot — set the domain once:

```bash
# in .env (compose reads it for the nginx service)
SSL_DOMAIN=your-actual-domain.com
```

On first boot there is no Let's Encrypt cert yet, so the entrypoint
mints a short-lived self-signed cert and nginx starts anyway. Issue
the real cert afterwards (port 80 serves the ACME challenge):

---

## First Deployment

### 1. Build and Start Services

```bash
docker compose build --no-cache
docker compose up -d
```

Verify all 6 containers are running:

```bash
docker compose ps
```

Expected output:

```
NAME                STATUS
hms-postgres-1      running (healthy)
hms-redis-1         running (healthy)
hms-php-1           running
hms-nginx-1         running
hms-queue-1         running
hms-reverb-1        running
```

### 2. Run Initial Migration

```bash
docker compose exec php php artisan migrate --force
```

### 3. Cache Configuration

```bash
docker compose exec php php artisan config:cache
docker compose exec php php artisan route:cache
docker compose exec php php artisan event:cache
docker compose exec php php artisan icons:cache
```

### 4. Set Up SSL (Let's Encrypt)

First, ensure your domain's DNS A record points to this Droplet.

```bash
# Stop nginx temporarily to free port 80
docker compose stop nginx

# Generate certificate
sudo certbot certonly --standalone \
  -d your-domain.com \
  -d www.your-domain.com \
  --non-interactive \
  --agree-tos \
  --email your-email@example.com

# Copy certs to Docker volume location
sudo cp -r /etc/letsencrypt /opt/hms/letsencrypt

# Update nginx config to use correct cert path
# (The default config already points to /etc/letsencrypt/live/your-domain.com)

# Restart nginx with SSL
docker compose up -d nginx
```

### 5. Set Up Certbot Auto-Renewal

```bash
# Create renewal cron job
sudo crontab -e
```

Add:

```
0 3 * * * cd /opt/hms && docker compose exec -T nginx certbot renew --webroot -w /var/www/certbot && docker compose exec -T nginx nginx -s reload >> /var/log/certbot-renewal.log 2>&1
```

### 6. Verify SSL

```bash
curl -I https://your-domain.com
```

Should return `HTTP/2 200` with `strict-transport-security` header.

---

## GitHub Actions CI/CD Setup

### Required Secrets

Add these secrets in your GitHub repository settings (Settings → Secrets and variables → Actions):

| Secret           | Description                | Example                                  |
| ---------------- | -------------------------- | ---------------------------------------- |
| `DEPLOY_HOST`    | Droplet IP or domain       | `203.0.113.42`                           |
| `DEPLOY_USER`    | SSH username               | `deploy`                                 |
| `DEPLOY_SSH_KEY` | Private SSH key (full PEM) | `-----BEGIN OPENSSH PRIVATE KEY-----...` |

### Generate SSH Key Pair for Deployment

On your local machine:

```bash
ssh-keygen -t ed25519 -C "github-deploy" -f ~/.ssh/hms-deploy
```

Add the **public key** to the Droplet:

```bash
# On the Droplet
echo "YOUR_PUBLIC_KEY_HERE" >> ~/.ssh/authorized_keys
```

Add the **private key** as the `DEPLOY_SSH_KEY` secret in GitHub.

### SSH Key Permissions on Droplet

```bash
chmod 700 ~/.ssh
chmod 600 ~/.ssh/authorized_keys
```

---

## How Deployment Works

1. Push to `main` branch
2. GitHub Actions runs `tests.yml` — Pint, PHPStan (level 10), then tests against PostgreSQL + Redis (hotspot provisioning forced log-only via `RADIUS_FAKE=true`; browser journeys run on installed Chrome)
3. If checks pass, `deploy.yml` triggers:
    - Re-runs the Lint + Types + Tests gate, then SSH into Droplet
    - `git pull` latest code
    - Pre-migrate database backup (`backup:run --only-db`)
    - Maintenance mode on
    - `docker compose build` — rebuilds PHP image with new deps
    - `docker compose up -d --force-recreate`
    - Wait for Postgres, then `php artisan migrate --force`
    - `php artisan db:seed --class=HotspotTierSeeder --force` — idempotent Free + Premium Wi-Fi tiers per branch
    - `php artisan optimize` + `view:cache`, restart Horizon workers (incl. the `network` queue supervisor)
    - Maintenance mode off, readiness gate on `/readyz`

## Rollback

Every deploy takes a pre-migrate backup first. To roll back:

```bash
cd /opt/hms
git reset --hard <previous-sha>   # or: git revert, then push
# restore the pre-migrate backup listed by:
docker compose exec -T php php artisan backup:list
# then download + pg_restore per docs/DR-RUNBOOK.md, and redeploy
```

`migrate:rollback` is a last resort (never for partition/data migrations).

---

## Useful Commands

### Service Management

```bash
# View running containers
docker compose ps

# View logs (all services)
docker compose logs -f

# View logs (specific service)
docker compose logs -f php
docker compose logs -f reverb
docker compose logs -f queue

# Restart a service
docker compose restart php

# Stop all services
docker compose down

# Stop and remove volumes (DELETES DATA)
docker compose down -v
```

### Artisan Commands

```bash
# Run artisan commands inside the PHP container
docker compose exec php php artisan <command>

# Examples:
docker compose exec php php artisan migrate:status
docker compose exec php php artisan cache:clear
docker compose exec php php artisan queue:restart
docker compose exec php php artisan about
```

### Database Access

```bash
# Connect to PostgreSQL
docker compose exec postgres psql -U postgres -d hotel_system

# Create a backup
docker compose exec postgres pg_dump -U postgres hotel_system > backup_$(date +%Y%m%d).sql

# Restore from backup
cat backup.sql | docker compose exec -T postgres psql -U postgres -d hotel_system
```

### Reverb (WebSockets)

```bash
# Check Reverb status
docker compose exec php php artisan reverb:status

# Restart Reverb
docker compose restart reverb
```

### Queue Workers

```bash
# View failed jobs
docker compose exec php php artisan queue:failed

# Retry a failed job
docker compose exec php php artisan queue:retry <id>

# Flush all failed jobs
docker compose exec php php artisan queue:flush

# Restart queue workers (zero-downtime)
docker compose exec php php artisan queue:restart
```

---

## Troubleshooting

### Container won't start

```bash
# Check container logs
docker compose logs php

# Common issues:
# - Missing .env file → copy .env.example .env and configure
# - Wrong DB credentials → check .env matches postgres service
# - Port 80/443 in use → stop other web servers (Apache, etc.)
```

### Database connection refused

```bash
# Verify postgres is healthy
docker compose ps postgres

# Check postgres logs
docker compose logs postgres

# Ensure DB_HOST=postgres (not 127.0.0.1) in .env
```

### WebSocket connection failed

```bash
# Check Reverb is running
docker compose ps reverb

# Check Reverb logs
docker compose logs reverb

# Test WebSocket endpoint
curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  https://your-domain.com/app/YOUR_APP_KEY
```

### SSL certificate issues

```bash
# Check certificate expiry
echo | openssl s_client -connect your-domain.com:443 2>/dev/null | openssl x509 -noout -dates

# Force renewal
sudo certbot renew --force-renewal

# Restart nginx
docker compose restart nginx
```

### High memory usage

```bash
# Check container memory
docker stats

# If queue worker uses too much memory:
# The --max-time=3600 flag auto-restarts workers every hour
# Adjust in docker/supervisord.conf if needed
```

---

## Backup Strategy

Canonical system: **Spatie backups to R2** (nightly `--only-db` 03:30,
weekly full Sunday 04:00, wired in `routes/console.php` and monitored
for max age 1 day). The host-level `pg_dump` cron below is a fallback
only — keep one source of truth to avoid restore ambiguity.

### Automated Database Backup (Daily, fallback)

```bash
# Add to crontab on Droplet
sudo crontab -e
```

```
0 2 * * * cd /opt/hms && docker compose exec -T postgres pg_dump -U postgres hotel_system | gzip > /opt/hms/backups/hotel_system_$(date +\%Y\%m\%d).sql.gz
```

### Backup Storage/Uploads

```bash
# Backup storage volume
docker run --rm -v hms-storage_data:/data -v /opt/hms/backups:/backup alpine \
  tar czf /backup/storage_$(date +%Y%m%d).tar.gz /data
```

### Restore Backup

```bash
# Restore database
gunzip -c backups/hotel_system_20260911.sql.gz | docker compose exec -T postgres psql -U postgres -d hotel_system

# Restore storage
docker run --rm -v hms-storage_data:/data -v /opt/hms/backups:/backup alpine \
  tar xzf /backup/storage_20260911.tar.gz -C /
```

---

## Environment Variables Reference

| Variable                | Default                | Description                                                  |
| ----------------------- | ---------------------- | ------------------------------------------------------------ |
| `APP_ENV`               | `production`           | Application environment                                      |
| `APP_DEBUG`             | `false`                | Debug mode (NEVER true in production)                        |
| `APP_URL`               | —                      | Full URL including https://                                  |
| `DB_CONNECTION`         | `pgsql`                | Database driver                                              |
| `DB_HOST`               | `postgres`             | Docker service name                                          |
| `DB_DATABASE`           | `hotel_system`         | Database name                                                |
| `SESSION_DRIVER`        | `redis`                | Session storage                                              |
| `CACHE_STORE`           | `redis`                | Cache storage                                                |
| `QUEUE_CONNECTION`      | `redis`                | Queue driver                                                 |
| `BROADCAST_CONNECTION`  | `reverb`               | Broadcasting driver                                          |
| `REDIS_HOST`            | `redis`                | Docker service name                                          |
| `REDIS_PASSWORD`        | —                      | Redis auth password                                          |
| `REVERB_APP_ID`         | —                      | Reverb app identifier                                        |
| `REVERB_APP_KEY`        | —                      | Reverb public key                                            |
| `REVERB_APP_SECRET`     | —                      | Reverb secret key                                            |
| `REVERB_HOST`           | —                      | Public domain for WebSocket                                  |
| `REVERB_PORT`           | `443`                  | WebSocket port                                               |
| `REVERB_SCHEME`         | `https`                | WebSocket scheme                                             |
| `HOTSPOT_PORTAL_DOMAIN` | `guest.hotels.example` | Guest portal host (Hotspot walled-garden)                    |
| `RADIUS_HOST`           | `100.64.0.1`           | Cloud RADIUS tunnel IP                                       |
| `RADIUS_DB_CONNECTION`  | `radius`               | DB connection holding radcheck/radreply/radacct              |
| `RADIUS_FAKE`           | `true`                 | `true` = log-only provisioning; `false` = live RADIUS writes |
