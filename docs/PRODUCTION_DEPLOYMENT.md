# PetPrep — Production Server & Deployment Architecture

This document covers the complete DevOps architecture, configuration, deployment, monitoring, and recovery procedures for the PetPrep production environment.

---

## 1. Server Specification

- **Provider:** Hetzner Cloud
- **Plan:** CX23 (4 GB RAM, 2 vCPU, 40 GB NVMe SSD)
- **Primary IP:** `138.199.172.97`
- **OS:** Ubuntu 26.04.1 LTS
- **Administrative SSH:** `root@138.199.172.97` (Key authentication)
- **Deployment SSH:** `deploy@138.199.172.97` (Dedicated deployment key, member of `docker` group)
- **Firewall (UFW):** Strict policy (Inbound: 22/tcp, 80/tcp, 443/tcp only)

---

## 2. Production Architecture

The stack runs as a single-server Docker Compose environment inside an isolated internal Docker bridge network (`petprep-network`). Only Caddy is exposed to the public internet on ports 80 and 443.

```text
                                INTERNET
                                   |
                                   v
                           +---------------+
                           |     CADDY     |
                           |   80 / 443    |
                           | HTTPS / TLS   |
                           +-------+-------+
                                   |
                       +-----------+-----------+
                       |                       |
                       v                       v
               Laravel HTTP API          Laravel Reverb
                   (app:80)             WebSocket (8080)
                       |                       |
                       +-----------+-----------+
                                   |
                           Docker internal
                              network
                                   |
                       +-----------+-----------+
                       |                       |
                       v                       v
                  PostgreSQL                 Redis
                 (postgres:5432)         (redis:6379)
                                               |
                                   +-----------+-----------+
                                   |                       |
                                   v                       v
                              Queue Worker             Scheduler
                             (queue:work)           (schedule:work)
```

### Component Details

1. **Caddy (`caddy:2.8-alpine`):**
   - Public entrypoint (80 / 443).
   - Automated Let's Encrypt TLS certificate provisioning and renewals.
   - Proxies WebSocket upgrades and `/app/*`, `/apps/*` requests to `reverb:8080`.
   - Proxies all HTTP API, Admin, and health requests to `app:80`.

2. **Laravel Application (`petprep-backend:production`):**
   - Laravel 11 running on PHP 8.3 CLI with Supervisor.
   - Production optimized (`APP_ENV=production`, `APP_DEBUG=false`).
   - Configuration, routes, and views compiled to disk.

3. **Laravel Reverb (`reverb:8080`):**
   - High-throughput WebSocket server listening internally on port 8080.
   - Broadcasts `pet.updated` on the private channel `private-pet.{petId}` to the child app and the parent dashboard. Channel auth: `POST /api/broadcasting/auth` (Sanctum bearer token, served by `app`).

4. **Queue Workers (`queue`, `queue-broadcasts`):**
   - `queue`: `php artisan queue:work redis --queue=default --tries=3 --timeout=90` (fal.ai reference images).
   - `queue-broadcasts`: `php artisan queue:work redis --queue=broadcasts --tries=3 --backoff=2 --timeout=15` — delivers queued `PetUpdated` events to Reverb, so a Reverb outage never blocks the scheduler (M1-09).
   - Both restarted after each deployment (`queue:restart`).

5. **Scheduler (`scheduler`):**
   - Runs `php artisan schedule:work`.
   - Executes the minutely game loop (`pets:process-decay`) for decay and escalation mechanics.

6. **PostgreSQL 18 (`postgres:18-alpine`):**
   - Private internal database, persistent volume `postgres_data` mounted at `/var/lib/postgresql`.
   - No public host ports exposed.

7. **Redis (`redis:alpine`):**
   - High-speed cache, queue backend, and Reverb broadcasting driver with AOF persistence (`redis_data`).
   - No public host ports exposed.


---

## 3. Directory Layout on Server

```text
/opt/petprep/
├── .env                  # Production secrets (mode 600, deploy:deploy)
├── repo/                 # Application codebase
│   ├── backend/
│   │   ├── compose.production.yaml
│   │   └── ...
│   └── deployment/
│       └── Caddyfile
├── backups/              # Automated gzip database dumps
└── scripts/
    ├── backup-production-db.sh
    ├── restore-production-db.sh
    └── deploy-production.sh
```

---

## 4. Automated CI/CD (GitHub Actions)

Every pull request and push to `main` runs the test jobs of `.github/workflows/deploy-production.yml` (backend Pest on PostgreSQL, mobile tsc + Jest). **Deployment runs only when the workflow is started manually** (Actions → CI & Deploy → Run workflow, branch `main`):

1. **Test Job:** Runs Pest & PHPUnit test suites on PHP 8.3 with all required extensions.
2. **Deploy Job:**
   - Targets the `production` GitHub Environment.
   - Prevents concurrent deployments via `concurrency: production`.
   - Uses `rsync` over SSH to sync the exact commit tree to `/opt/petprep/repo`.
   - Executes `/opt/petprep/scripts/deploy-production.sh <COMMIT_SHA>` on the server.
   - Verifies health check (`/up`) returns HTTP 200.

### Required GitHub Secrets in Repository Settings

Configure in **Settings > Environments > production** (or Repository Secrets):

| Secret Name | Value |
| :--- | :--- |
| `PRODUCTION_HOST` | `138.199.172.97` |
| `PRODUCTION_USER` | `deploy` |
| `PRODUCTION_SSH_PRIVATE_KEY` | Contents of `~/.ssh/petprep_deploy_key` (ed25519 private key) |

---

## 5. Manual Emergency Deployment

To perform a manual deployment directly on the server:

```bash
# Connect as deploy user
ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97

# Execute deployment script
/opt/petprep/scripts/deploy-production.sh [COMMIT_SHA]
```

---

## 6. Database Backups & Retention

Database backups are created automatically before every deployment and can be triggered on demand:

```bash
# Run backup manually
/opt/petprep/scripts/backup-production-db.sh

# List existing backups
ls -lh /opt/petprep/backups/
```

- **Location:** `/opt/petprep/backups/petprep_petprep_production_YYYY-MM-DD_HHMMSS.sql.gz`
- **Retention:** Automatically deletes backups older than 7 days to preserve disk space on the 40 GB SSD.

---

## 7. Rollback Procedure

### Code Rollback
To rollback application code to a previous known-good commit:

```bash
ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97
/opt/petprep/scripts/deploy-production.sh <PREVIOUS_COMMIT_SHA>
```

### Database Restore (Disaster Recovery)
If a destructive migration occurred and database rollback is needed:

```bash
ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97

# View available backups
ls -lt /opt/petprep/backups/*.sql.gz

# Restore specific dump
/opt/petprep/scripts/restore-production-db.sh /opt/petprep/backups/petprep_petprep_production_YYYY-MM-DD_HHMMSS.sql.gz
```

---

## 8. Server Administration & Log Inspection

```bash
# Check running containers & health status
docker compose -f /opt/petprep/repo/backend/compose.production.yaml ps

# Inspect logs
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f app
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f reverb
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f queue
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f scheduler
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f caddy
docker compose -f /opt/petprep/repo/backend/compose.production.yaml logs -f postgres

# Check disk & memory usage
df -h
free -h
docker stats --no-stream
```

---

## 9. DNS & Domain Configuration

When assigning a domain name (e.g. `api.petprep.io`):

1. **DNS A Record:** Point `api.petprep.io` -> `138.199.172.97`.
2. **Update `.env` on server:**
   ```bash
   DOMAIN=api.petprep.io
   APP_URL=https://api.petprep.io
   REVERB_HOST=api.petprep.io
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```
3. **Restart Caddy:** Caddy will automatically provision Let's Encrypt SSL/TLS certificates.
