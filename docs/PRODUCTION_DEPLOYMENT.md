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
├── incoming/             # CI upload of the commit being deployed (staging, not mounted)
├── releases/previous/    # Copy of repo/ before the last code switch (automatic revert)
├── repo/                 # Live codebase, bind-mounted into every PHP container
│   ├── .deployed-sha     # Commit currently deployed (written by deploy-production.sh)
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

Every pull request and push to `main` runs the test jobs of `.github/workflows/deploy-production.yml` (backend Pest on PostgreSQL, mobile tsc + Jest). **Pre-production phase (David, 2026-10-03): every push/merge to `main` deploys automatically** once all test jobs pass (also "Run workflow" on `main`); restore manual-only deploys before real users (DEPLOYMENT.md D2):

1. **Test Job:** Runs Pest & PHPUnit test suites on PHP 8.3 with all required extensions.
2. **Deploy Job:**
   - Targets the `production` GitHub Environment.
   - Prevents concurrent deployments via `concurrency: production`.
   - Needs `backend-tests`, `mobile-checks` and `deploy-script-tests` (shellcheck + `scripts/tests/deploy-production.test.sh`, stubbed docker/git/curl).
   - Uses `rsync` over SSH to upload the exact commit tree to the staging dir `/opt/petprep/incoming` — **never directly into `repo/`**, because every PHP container bind-mounts `repo/backend` and would run the new code immediately.
   - Executes `/opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>` on the server, which: checks the env (before any git/docker call) → records the previous release → `artisan down` on the old code → DB backup → syncs `incoming/` into `repo/` → build → migrate → caches → recreates containers → `queue:restart` → `artisan up` (3 attempts) → verifies `/up` returns HTTP 200.
   - On failure **before** migrations succeeded the script reverts `repo/` to the previous release (from `releases/previous`) and leaves maintenance; on failure **after** migrations it keeps the new code (schema is new), restarts containers, leaves maintenance and fails loudly. Details: comment block in the script, DEPLOYMENT.md D10.

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

# Re-deploy the tree that is already in repo/ (no code switch, no automatic revert)
/opt/petprep/scripts/deploy-production.sh

# Deploy the last CI upload again (same flow as CI, with automatic revert)
/opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>
```

If `repo/` is a git checkout, `deploy-production.sh <COMMIT_SHA>` checks the commit out inside the maintenance window instead (aborts if the commit is unknown) and reverts with `git checkout -f <previous HEAD>` on a pre-migration failure.

If a deploy ends with "PRODUCTION IS STILL IN MAINTENANCE MODE", bring it up manually:
`docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan up`.

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
A failed deploy reverts the code by itself when the failure happens before migrations succeed. For a deliberate rollback to an older commit, re-run the workflow for that commit (GitHub → Actions → CI & Deploy → re-run the job of the known-good `main` commit), which uploads it to `incoming/` and deploys it with the normal flow. Only roll code back past a migration if that migration is backward compatible — otherwise restore the database too (below). `cat /opt/petprep/repo/.deployed-sha` shows what is live.

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
