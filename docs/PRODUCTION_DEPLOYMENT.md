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
                           +---------------------------+
                           |     CADDY (petprep-web)   |
                           |  80 / 443 · HTTPS / TLS   |
                           |  public/ static files     |
                           |  pet media (read-only     |
                           |  app_storage, X-Accel)    |
                           +-------------+-------------+
                                         |
                       +-----------------+-----+
                       | php_fastcgi           |
                       v                       v
               Laravel HTTP API          Laravel Reverb
          (app:9000, PHP-FPM + OPcache)  WebSocket (8080)
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

1. **Caddy (`petprep-web:production` = `caddy:2.8-alpine` + the app's `public/`, M4-05b):**
   - Public entrypoint (80 / 443).
   - Automated Let's Encrypt TLS certificate provisioning and renewals.
   - Proxies WebSocket upgrades and `/app/*`, `/apps/*` requests to `reverb:8080`.
   - Serves `public/` itself (Filament css/js `Cache-Control: public, max-age=2592000`, `/build/*` 1 year immutable, zstd/gzip for text).
   - Everything else → `php_fastcgi app:9000` (PHP-FPM).
   - **Pet media:** `GET /api/media/{id}` is checked by Laravel (signature + authz); Laravel answers with an internal `X-Accel-Redirect` header and Caddy serves the file from `app_storage` mounted **read-only** at `/srv/storage` (Range / ETag / 304). The header never reaches a client. `PET_MEDIA_SERVE_VIA=php` switches back to streaming from PHP.
   - `deployment/Caddyfile` is a single-file bind mount (see §4 for how a changed file is rolled out).

2. **Laravel Application (`petprep-app:production`, `backend/docker/production/Dockerfile`, M4-05b):**
   - Laravel 11 on **PHP-FPM 8.3** (official `php:8.3-fpm-bookworm` + pdo_pgsql, pgsql, redis, intl, zip, bcmath, gd, exif, pcntl, opcache), listening on 9000 inside the Docker network only.
   - Code + `vendor/` (`composer install --no-dev --optimize-autoloader`) are **baked into the image**; nothing is bind-mounted except `/opt/petprep/.env` (read-only) and the `app_storage` volume (`storage/`).
   - Runs as the non-root user `petprep` (uid 1000 — same owner as the files in `app_storage`).
   - OPcache on, never re-checks files (`validate_timestamps=0`; every deploy recreates the containers). FPM `pm=dynamic`, max 12 children, `request_terminate_timeout=65s`.
   - Production optimized (`APP_ENV=production`, `APP_DEBUG=false`). On every start the entrypoint builds the caches inside the container: `php artisan optimize` (config, events, routes, views, Filament components, Blade icons).
   - Health: `php-fpm-ping` (compose healthcheck, deploy waits for it).
   - The same image runs `reverb`, `queue`, `queue-broadcasts` and `scheduler` with a CLI command (their entrypoint caches config + events).

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
├── incoming/             # CI upload of the commit being deployed (staging, not mounted; build context of the new images)
├── releases/previous/    # Copy of repo/ before the last code switch (automatic revert)
├── releases/build-src/   # git-mode deploys only: `git archive` of the commit = build context
├── repo/                 # Compose file, Caddyfile (bind-mounted into caddy), ops scripts. Since M4-05b the PHP
│                         # containers run the code baked into petprep-app (backend/vendor here is only for a
│                         # rollback to the Sail runtime, which bind-mounts repo/backend)
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

Docker images on the server (M4-05b):

| Image | Meaning |
|---|---|
| `petprep-app:production` / `petprep-web:production` | what compose runs (current release) |
| `petprep-app:previous` / `petprep-web:previous` | the release before (automatic revert of a failed deploy; manual rollback) |
| `petprep-app:next` / `petprep-web:next` | built by the last deploy (same image ID as `:production` after a successful deploy) |
| `petprep-backend:production` | the old Sail runtime image (rollback to the pre-M4-05b compose file) — remove after a stable week |

---

## 4. Automated CI/CD (GitHub Actions)

Every pull request and push to `main` runs `.github/workflows/deploy-production.yml` ("CI & Deploy"). **Pre-production phase (David, 2026-10-03): every push/merge to `main` that changes `backend/`, `deployment/`, `scripts/` or the workflow deploys automatically** once `CI OK` is green; "Run workflow" on `main` always tests everything and deploys; restore manual-only deploys before real users (DEPLOYMENT.md D2). Since 2026-10-06 the pipeline does not repeat work (DEPLOYMENT.md D15):

1. **`plan`** (`scripts/ci-plan.sh`): computes the changed paths (PR: against the current `main`; push to `main`: against the last green `main` run — unknown → full run) and decides which suites run and whether `main` deploys. On a push to `main` it skips every suite that already passed in a successful PR run of this workflow on the **identical git tree** (marker artifacts `ci-green-<suite>-<tree>`). Docs-only changes finish in under a minute and don't deploy; mobile-only changes run only the mobile checks and don't deploy.
2. **Suites** (each only when planned): `backend-tests` (Pest `--parallel` on PostgreSQL 18, PHP 8.3, one database per process), `mobile-checks` (tsc + Jest), `deploy-script-tests` (shellcheck + `scripts/tests/deploy-production.test.sh` with stubbed docker/git/curl + `scripts/tests/ci-plan.test.sh` with stubbed gh), `production-image` (M4-05b: hadolint, builds the `app` and `web` targets with buildx — GHA layer cache, no push —, `caddy validate` of the real Caddyfile inside the web image, smoke test: uid 1000, `php-fpm -t`, extensions, no dev packages / `.env` / tests in the image, entrypoint `optimize`).
3. **`CI OK`**: always reported; green only when `plan` succeeded and every suite passed or was skipped by the plan. **This is the one check the merge helper gates on** (free private plan: no GitHub branch protection).
4. **Deploy Job:**
   - Targets the `production` GitHub Environment.
   - Prevents concurrent deployments via `concurrency: production`.
   - Needs `plan` + `CI OK` (never runs after a failed or cancelled job) and the plan's deploy flag.
   - First step `scripts/ci-deploy-guard.sh`: push runs and every re-run fail (red) unless the run's commit is still the head of `main` on origin — an older commit never overwrites a newer deploy. "Run workflow" (attempt 1) is not checked.
   - Uses `rsync` over SSH to upload the exact commit tree to the staging dir `/opt/petprep/incoming` — **never directly into `repo/`**, because every PHP container bind-mounts `repo/backend` and would run the new code immediately.
   - Executes `/opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>` on the server, which: checks the env (before any git/docker call) → records the previous release → checks `docker buildx` → **builds `petprep-app:next` + `petprep-web:next` from `incoming/` and smoke-tests them (`php artisan optimize` with the production env) while the old release keeps serving** → chowns the `app_storage` volume to uid 1000 and verifies it is writable as uid 1000 (abort otherwise — the FPM image is non-root) → `artisan down` on the old code (as uid 1000) → DB backup → syncs `incoming/` into `repo/` → promotes the images (`:production` → `:previous`, `:next` → `:production`) → validates a changed Caddyfile with the new caddy image (caddy is recreated after the migrations, because its single-file bind mount never sees a replaced file) → migrate → recreates all containers on the new images → waits for PHP-FPM (`php-fpm-ping`) → `queue:restart` → `artisan up` (3 attempts) → verifies `/up` through Caddy (`--resolve api.petprep.si:443:127.0.0.1`, fallback the IP site; 5 attempts) → prunes dangling images and build cache older than 7 days beyond 5 GB.
   - The first build on the server compiles the PHP extensions (~5–10 min, *estimate*); later builds reuse the cached layers (code change ≈ 1 min, composer.lock change ≈ 2–3 min). All of it happens **before** maintenance mode.
   - A failing build or smoke test changes nothing (exit ≠ 0, no maintenance). On failure **before** migrations succeeded the script reverts `repo/` to the previous release (from `releases/previous`) **and the images** (`:previous` → `:production`) and leaves maintenance; on failure **after** migrations it keeps the new release (schema is new), restarts containers, leaves maintenance and fails loudly. Details: comment block in the script, DEPLOYMENT.md D10 / D14.

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
A failed deploy reverts the code by itself when the failure happens before migrations succeed. For a deliberate rollback to an older commit, merge a **revert commit** (normal PR + merge → tested and deployed like any change), or move `main` where it should be and use **"Run workflow"** on `main`. **Re-running an old run fails by design** (deploy guard, DEPLOYMENT.md D15: its commit is no longer the head of `main`); "Re-run all jobs" of the run of the *current* `main` head still redeploys it. Only roll code back past a migration if that migration is backward compatible — otherwise restore the database too (below). `cat /opt/petprep/repo/.deployed-sha` shows what is live.

### Rollback of the runtime (M4-05b: PHP-FPM image → Sail image)

The first deploy of the production image is the risky one. What happens and what to do:

1. **Build or smoke test fails** (Dockerfile, composer, config): nothing changed — the Sail runtime keeps serving. Fix and redeploy.
2. **Failure before the migrations finished** (code switch, invalid Caddyfile, migrate): the script reverts `repo/` (old compose file = Sail image, bind-mounted code) by itself and restarts the workers. On the first runtime deploy there is no `petprep-app:previous` — not needed, the old compose file does not use it.
3. **Deploy finished but the app misbehaves** (e.g. Filament page broken under FPM, 502s, workers crash) — M4-05b has **no migrations**, so the code can go back without a DB restore:
   - **Only pet media broken** (videos don't play, 404 from Caddy): set `PET_MEDIA_SERVE_VIA=php` in `/opt/petprep/.env`, then `cd /opt/petprep/repo/backend && cp /opt/petprep/.env .env && docker compose -f compose.production.yaml up -d app` (PHP streams the files again, as before M4-05b).
   - **Back to the Sail runtime** (old compose file, old Caddyfile, `petprep-backend:production` image, `vendor/` still in `repo/backend`): re-run the deploy of the last commit **before** the M4-05b merge — GitHub → Actions → CI & Deploy → that run → *Re-run all jobs* (it uploads that commit to `incoming/` and its own, older deploy script does a normal deploy: builds the Sail image (cached), syncs the old tree, recreates the containers and Caddy with the old Caddyfile).
   - **Without GitHub** (on the server, as `deploy`), right after the failed deploy, while `releases/previous` still holds the pre-M4-05b tree:
     ```bash
     cp -a /opt/petprep/releases/previous /opt/petprep/releases/rollback-sail   # the script overwrites releases/previous
     /opt/petprep/releases/rollback-sail/scripts/deploy-production.sh \
       --from /opt/petprep/releases/rollback-sail "$(cat /opt/petprep/releases/rollback-sail/.deployed-sha 2>/dev/null || echo rollback)"
     ```
     (`releases/previous` has no `.deployed-sha`; the argument is only a label.) Then `curl -fsS https://api.petprep.si/up` and `docker compose -f /opt/petprep/repo/backend/compose.production.yaml ps`.
4. **A later release on the new runtime misbehaves:** `petprep-app:previous` / `petprep-web:previous` hold the release before; redeploy that commit through GitHub Actions (the build is a cache hit), or — no migrations in between — `docker tag petprep-app:previous petprep-app:production && docker tag petprep-web:previous petprep-web:production`, sync the previous tree into `repo/` and `docker compose -f /opt/petprep/repo/backend/compose.production.yaml up -d`.

Remove `petprep-backend:production` and the unused `app_bootstrap_cache` volume only after a stable week on the new runtime (DEPLOYMENT.md D14).

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

# PHP-FPM health + pool status (M4-05b; exec runs as the image user petprep)
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php-fpm-ping && echo fpm-ok
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app sh -c \
  'env -i SCRIPT_NAME=/fpm-status SCRIPT_FILENAME=/fpm-status REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'

# Release images (current, previous, last build, old Sail image)
docker image ls 'petprep-*'
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
