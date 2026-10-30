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
- **Firewall (UFW):** Strict policy (Inbound: 22/tcp, 80/tcp, 443/tcp, 443/udp only). 443/udp = HTTP/3 (QUIC): Caddy advertises `Alt-Svc: h3` for 30 days, so the UDP port must stay open or clients try h3 into a black hole (incident 2026-10-19, `ufw allow 443/udp`).

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
   - **Pet media:** `GET /api/media/{id}` is checked by Laravel (signature + authz); Laravel answers with an internal `X-Accel-Redirect` header and Caddy serves the file from `app_storage` mounted **read-only** at `/srv/storage` (Range / ETag / 304). The header never reaches a client. `PET_MEDIA_SERVE_VIA=php` switches back to streaming from PHP. **Object storage (M4-05, only with `PET_MEDIA_DISK=s3`):** after the same checks Laravel answers `302` to a short-lived presigned URL of the private Hetzner bucket and the phone downloads from the bucket — Caddy and the `app_storage` volume are not involved (runbook DEPLOYMENT.md D23).
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
   - `queue`: `php artisan queue:work redis --queue=auth,default,alerts --tries=3 --timeout=90` (`auth` first — M2-10c Apple token revocation after an account deletion, the Apple code lives 5 min; then fal.ai reference images; `alerts` last — M5-05 Discord posts about app errors, low priority, never ahead of real work).
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
├── releases/{reverb,caddy}.fingerprint  # D19: inputs of the running reverb / caddy (unchanged → not restarted)
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

Every pull request and push to `main` runs `.github/workflows/deploy-production.yml` ("CI & Deploy"). **Manual production deploys (David, 2026-10-14, DEPLOYMENT.md D18): a push / merge to `main` runs CI only and deploys nothing. Production `api.petprep.si` is deployed only by "Run workflow" on `main` with target `production`** (§4a) — automatically every day at 03:17 Europe/Ljubljana by `.github/workflows/daily-deploy.yml` when `main` has new commits and their push CI is green (DEPLOYMENT.md D21, time D24; GitHub may start the scheduled run later), or by David / by Claude on David's explicit request. "Run workflow" with target `none` (default) tests everything without deploying. Since 2026-10-06 the pipeline does not repeat work (DEPLOYMENT.md D16):

1. **`plan`** (`scripts/ci-plan.sh`): computes the changed paths (PR: against the current `main`; push to `main`: against the last `main` run whose deploy job succeeded — unknown, API error or a broken diff → full run) and decides which suites run and whether `main` deploys. On a push to `main` it skips every suite that already passed in a successful PR run of this workflow on the **identical git tree** (marker artifacts `ci-green-<suite>-<tree>`). Docs-only changes finish in under a minute and don't deploy; mobile-only changes run only the mobile checks and don't deploy.
2. **Suites** (each only when planned): `backend-tests` (Pest `--parallel` on PostgreSQL 18, PHP 8.3, one database per process), `mobile-checks` (tsc + Jest), `deploy-script-tests` (shellcheck + `scripts/tests/deploy-production.test.sh` with stubbed docker/git/curl + `scripts/tests/ci-plan.test.sh` with stubbed gh), `production-image` (M4-05b: hadolint, builds the `app` and `web` targets with buildx — GHA layer cache, no push —, `caddy validate` of the real Caddyfile inside the web image, smoke test: uid 1000, `php-fpm -t`, extensions, no dev packages / `.env` / tests in the image, entrypoint `optimize`).
3. **`CI OK`**: always reported; green only when `plan` succeeded and every suite passed or was skipped by the plan. **This is the one check the merge helper gates on** (free private plan: no GitHub branch protection).
4. **Deploy job** "Deploy to Hetzner Production" (environment `production`, concurrency `production`, steps in `scripts/ci-remote-deploy.sh`):
   - Runs only on "Run workflow" on `main` with target `production` (`scripts/ci-deploy-target.sh`); needs `plan` + `CI OK` (never after a failed or cancelled job).
   - Calls the deploy script with `--expect-domain api.petprep.si`: a server whose `/opt/petprep/.env` names another domain refuses before any change (wrong host secret).
   - First step `scripts/ci-deploy-guard.sh`: push runs and every re-run fail (red) unless the run's commit is still the head of `main` on origin — an older commit never overwrites a newer deploy. "Run workflow" (attempt 1) is not checked.
   - Uses `rsync` over SSH to upload the exact commit tree to the staging dir `/opt/petprep/incoming` — **never directly into `repo/`**, because every PHP container bind-mounts `repo/backend` and would run the new code immediately.
   - Executes `/opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>` on the server, which: checks the env (before any git/docker call) → records the previous release → checks `docker buildx` → **builds `petprep-app:next` + `petprep-web:next` from `incoming/` and smoke-tests them (`php artisan optimize` with the production env) while the old release keeps serving** → chowns the `app_storage` volume to uid 1000 and verifies it is writable as uid 1000 (abort otherwise — the FPM image is non-root) → **asks the new image whether migrations are pending (`migrate:status --pending=3`, D19); none → no maintenance mode at all (workers recreated first, then app; Caddy retries the app for up to 10 s during the swap; reverb and caddy not restarted when their inputs are unchanged)** → otherwise `artisan down` on the old code (as uid 1000) → DB backup → syncs `incoming/` into `repo/` → promotes the images (`:production` → `:previous`, `:next` → `:production`) → validates a changed Caddyfile with the new caddy image (caddy is recreated after the migrations, because its single-file bind mount never sees a replaced file) → migrate → recreates all containers on the new images → waits for PHP-FPM (`php-fpm-ping`) → `queue:restart` → `artisan up` (3 attempts) → verifies `/up` through Caddy (`--resolve <API domain>:443:127.0.0.1` — the API domain comes from the server's `APP_URL`; fallback the raw-IP site on production only; 5 attempts) → prunes dangling images and build cache older than 7 days beyond 5 GB.
   - The first build on the server compiles the PHP extensions (~5–10 min, *estimate*); later builds reuse the cached layers (code change ≈ 1 min, composer.lock change ≈ 2–3 min). All of it happens **before** maintenance mode.
   - A failing build or smoke test changes nothing (exit ≠ 0, no maintenance). On failure **before** migrations succeeded the script reverts `repo/` to the previous release (from `releases/previous`) **and the images** (`:previous` → `:production`) and leaves maintenance; on failure **after** migrations it keeps the new release (schema is new), restarts containers, leaves maintenance and fails loudly. Details: comment block in the script, DEPLOYMENT.md D10 / D14.

### Required GitHub Secrets in Repository Settings

Configure in **Settings > Environments > production** (or Repository Secrets):

| Secret Name | Value |
| :--- | :--- |
| `PRODUCTION_HOST` | `138.199.172.97` |
| `PRODUCTION_USER` | `deploy` |
| `PRODUCTION_SSH_PRIVATE_KEY` | Contents of `~/.ssh/petprep_deploy_key` (ed25519 private key) |

### 4a. Deploying production (manual since D18, David 2026-10-14)

**Why:** every merge to `main` (10+ a day, also the unattended breed PRs) used to deploy production, and each deploy's maintenance mode + Reverb restart dropped the phone app's connection. Now merges only run CI; production changes when David decides.

**One-time check before the first dispatch (David):** on the server, `grep -E '^(APP_URL|PETPREP_API_DOMAIN|PETPREP_CADDY_SITES)=' /opt/petprep/.env` must show `APP_URL=https://api.petprep.si` (or pin `PETPREP_API_DOMAIN=api.petprep.si` and `PETPREP_CADDY_SITES=production` there). Otherwise the deploy refuses with "this server's API domain is … but the deploy expects 'api.petprep.si'" before changing anything — and a wrong value would make Caddy serve another domain. Manual runs on the server: `/opt/petprep/scripts/deploy-production.sh --expect-domain api.petprep.si <SHA>`.

**Deploy production:** Actions → **CI & Deploy** → **Run workflow** → branch `main`, target **production**, or `gh workflow run deploy-production.yml --ref main -f target=production`. The run tests everything on the current `main` head, then "Deploy to Hetzner Production" (guard, upload, `deploy-production.sh --expect-domain api.petprep.si`). Check: `curl -fsS https://api.petprep.si/up`, `gh run list --workflow deploy-production.yml --limit 3`. Afterwards "Mobile OTA update" publishes the production OTA when `mobile/` changed since the last release (needs a production store build with the same fingerprint). Claude runs the deploy only when David explicitly asks.

**Rollback:** revert commit on `main` (PR + merge), then Run workflow → target `production` (§7). "Re-run" of an old production run is refused by the deploy guard.

**Dev server: postponed** (David 2026-10-14). The Caddyfile and the deploy script already read the server identity from `/opt/petprep/.env` (`APP_URL` → `PETPREP_API_DOMAIN`, `PETPREP_CADDY_SITES=api-only` for a non-production domain), so a second API-only server needs only a CI job and its secrets later.

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

### 6b. Off-site backups — database + pet media (D5, 2026-10-14)

`/opt/petprep/scripts/backup-offsite.sh` (installed by every deploy) sends the **database** (pg_dump streamed into restic) and the **pet media** (AI images / videos in the `backend_app_storage` volume, `app/pet-media`, tag `media`) to an encrypted, incremental [restic](https://restic.net) repository off the server — by default a Hetzner Storage Box. restic runs in its official Docker image (`restic/restic:0.17.3`); nothing to install on the host.

- **DB snapshots are all-or-nothing:** stored with tag `db-pending`, re-tagged `db` only when pg_dump exited 0; a failed or killed run's snapshot is forgotten (also at the start of the next run). `latest --tag db` is always a complete dump.
- **Ops lock only for the dump** (waits ≤ 30 min for a deploy / reset, then releases it before the media upload, retention and check) — deploys are never blocked by a long upload.
- **Retention (David 2026-10-14):** every snapshot of the last **35 days** per tag (`--keep-within 35d`). Data of a deleted family leaves the backups within 35 days (PRODUCT_SPEC §5, audiences/PARENTS.md).
- Sundays `restic check` with a 5 % data sample; every restic call has a hard timeout; sftp keep-alive from the ssh config.
- The `.sql.gz` dumps in `/opt/petprep/backups` (before every deploy, 7 days) stay — the fast local rollback; the off-site copy is the disaster recovery.

**One-time setup (David):**
1. Hetzner Console → order a **Storage Box** (smallest size is plenty to start; another location than the server, e.g. Helsinki) → enable **SSH support**, note the user (`u123456`).
2. On the server, as the deploy user:
   ```bash
   mkdir -p /opt/petprep/backup-ssh && chmod 700 /opt/petprep/backup-ssh
   ssh-keygen -t ed25519 -N '' -f /opt/petprep/backup-ssh/id_ed25519
   cat > /opt/petprep/backup-ssh/config <<'CFG'
   Host storagebox
     HostName u123456.your-storagebox.de
     User u123456
     Port 23
     IdentityFile /root/.ssh/id_ed25519
     UserKnownHostsFile /root/.ssh/known_hosts
     ServerAliveInterval 60
     ServerAliveCountMax 5
   CFG
   ssh-keyscan -p 23 u123456.your-storagebox.de > /opt/petprep/backup-ssh/known_hosts
   # install the public key on the Storage Box (asks for the box password once):
   cat /opt/petprep/backup-ssh/id_ed25519.pub | ssh -p 23 u123456@u123456.your-storagebox.de install-ssh-key
   ```
   The paths say `/root/.ssh` because ssh runs as root inside the restic container; the script passes this config with `-F` (a `~/.ssh/config` owned by the deploy user would be refused there).
3. `/opt/petprep/backup.env` (`chmod 600`, never in git) — **`KEY=value` only: no quotes, no comments** (Docker passes them literally; the script refuses them):
   ```
   RESTIC_REPOSITORY=sftp:storagebox:petprep
   RESTIC_PASSWORD=<output of: openssl rand -base64 48>
   BACKUP_HEALTHCHECK_URL=https://hc-ping.com/<uuid>
   ```
   `BACKUP_HEALTHCHECK_URL` is optional but recommended (e.g. a free healthchecks.io check, period 1 day, grace 2 h → an e-mail when a night fails or is missed).
   **Store `RESTIC_PASSWORD` and the whole `/opt/petprep/.env` (APP_KEY, DB password, provider keys) in your password manager.** Without the restic password no backup can ever be read; without APP_KEY a restored database cannot decrypt its encrypted fields or verify child PINs.
4. First run + restore test: `/opt/petprep/scripts/backup-offsite.sh && /opt/petprep/scripts/backup-offsite.sh verify` (the first media upload can take a while).
5. Nightly cron (deploy user, `crontab -e`; 02:17 UTC = after `media:retry`, before the families' morning):
   ```
   17 2 * * * /opt/petprep/scripts/backup-offsite.sh >> /opt/petprep/backups/offsite.log 2>&1
   ```
   The last success time is in `/opt/petprep/backups/offsite-last-success`.

Any S3 repository works instead (`RESTIC_REPOSITORY=s3:https://…/bucket` + `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` in `backup.env`, no `backup-ssh/`).

**Pet media on object storage (M4-05, `PET_MEDIA_DISK=s3` in `/opt/petprep/.env`):** restic can only back up the volume, so the script additionally mirrors the media bucket with rclone (`rclone/rclone:1.68.2`, Docker) to a **second bucket in another location, in a separate Hetzner Console project with its own credentials** (QA #374 M4: the mirror keys live only in `backup.env` — never in `/opt/petprep/.env`, never mounted into the app containers — so a leaked app key cannot delete the backup) — add to `backup.env`:
```
MEDIA_MIRROR_S3_BUCKET=petprep-media-mirror
MEDIA_MIRROR_S3_KEY=<access key of the mirror bucket>
MEDIA_MIRROR_S3_SECRET=<secret>
MEDIA_MIRROR_S3_ENDPOINT=https://nbg1.your-objectstorage.com
MEDIA_MIRROR_S3_REGION=nbg1
```
(`_ENDPOINT` / `_REGION` default to nbg1.) Nightly, after the DB and the volume: `rclone sync` bucket → `mirror:<bucket>/current`, files deleted / overwritten that night go to `deleted/<YYYY-MM-DD>/`, folders older than 35 days are purged (same retention as restic). Guards: an **empty bucket while the mirror has files → no sync** (wrong bucket / prefix must never empty the mirror), at most 10 % of the mirror (min 50 files) deleted per night (`--max-delete`; more → the run fails for a human); mirror / purge failures are reported after the restic retention, so the 35-day rule still runs. The credentials reach rclone only through a 600 env-file that is removed at exit. Missing mirror keys → the DB is still backed up, then the run fails (fail ping). `verify` also checks that the mirror holds ≥ 95 % of the bucket's files. The volume step keeps running for the local copies until `--prune-local`.

**Commands:** `backup-offsite.sh snapshots` (list), `backup-offsite.sh verify` (latest `db` snapshot is a complete pg_dump; latest media snapshot holds files when the volume has some) — **run `verify` monthly**. A run killed hard can leave a stale restic lock; restic ignores locks older than ~30 min, otherwise `docker run --rm -i --env-file /opt/petprep/backup.env -v /opt/petprep/backup-ssh:/root/.ssh:ro restic/restic:0.17.3 -o "sftp.command=ssh -F /root/.ssh/config storagebox -s sftp" unlock`. Restore: §7 "Off-site restore".

### 6a. One-off: reset all game data before the beta (DEPLOYMENT.md D17)

David decided on 2026-10-08 13:50 to start the ~20-tester beta from an empty game: every family, parent, child, pet, purchase, AI spend / AI Lab row and pet media file goes; **superadmin accounts (Filament) and the breed reference data stay**. The work is done by `php artisan petprep:reset-game-data` (dry run by default) through the host wrapper `scripts/reset-game-data.sh`, which takes the backups first. Run it only after the PR that adds it is deployed (`cat /opt/petprep/repo/.deployed-sha`).

**Before you start:**
- **No merges to `main` and no running deploy** during the reset: check GitHub → Actions → "CI & Deploy" (nothing running). The wrapper and `deploy-production.sh` share the lock `/opt/petprep/.ops.lock`: the reset refuses while a deploy runs; a deploy that starts during the reset waits up to 15 min (`DEPLOY_LOCK_WAIT`) and then fails without changing anything (re-run it afterwards with "Run workflow").
- **Run it inside `tmux` (or `screen`)** so a dropped SSH connection can't kill it halfway: `tmux new -s reset` (reattach with `tmux attach -t reset`).
- The wrapper checks free disk first: free space in `/opt/petprep/backups` must be ≥ 1.2 × (database size + pet media size) + 100 MB.

```bash
ssh -i ~/.ssh/petprep_deploy_key deploy@138.199.172.97
tmux new -s reset
cd /opt/petprep/repo

# 1. Dry run — changes nothing; prints rows per table to delete / keep,
#    the superadmin ids that stay, the pet media root, file count + size.
#    It refuses (exit 1) on an unclassified table or an unexpected media root.
bash scripts/reset-game-data.sh

# 2. Execute — takes the ops lock, asks you to type the host (api.petprep.si), checks disk,
#    then: artisan down → stop queue / queue-broadcasts / scheduler → DB backup + media tar
#    → reset (one transaction, then media files, queues, cache) → workers up → artisan up.
bash scripts/reset-game-data.sh --execute
#    (without a terminal: bash scripts/reset-game-data.sh --execute --confirm-host=api.petprep.si)

# 3. Check
bash scripts/reset-game-data.sh          # every DELETE row count is 0, media 0 files
curl -fsS https://api.petprep.si/up
```

**If it fails**, the last lines say which case it is (the app always comes back up):
- *"RESET ABORTED during …"* / *"RESET FAILED BEFORE THE COMMIT"* (exit 1) — **nothing was deleted** (refusal, backup / disk problem, or the DB transaction rolled back). Fix the cause and run step 2 again.
- *"DB RESET IS COMMITTED … cleanup failed"* (exit 2) — the game data **is** deleted, but deleting media files / clearing queues / cache failed. Run step 2 again: it is idempotent (deletes no more rows) and finishes the cleanup; it takes a new (small) backup, the pre-reset backups stay.

Backups of this run (owner-only files, kept outside the 7-day retention — delete them by hand once the beta is stable):
`/opt/petprep/backups/pre-reset/pre-reset_db_<UTC>.sql.gz` (hard link of the normal dump) and `/opt/petprep/backups/pre-reset/pre-reset_media_<UTC>.tar.gz`.

**Undo (restore the state before the reset)** — no deploy running, app in maintenance, workers stopped:
```bash
cd /opt/petprep/repo/backend
docker compose -f compose.production.yaml exec -T --user 1000:1000 app php artisan down
docker compose -f compose.production.yaml stop queue queue-broadcasts scheduler
# RESTORE_STRICT=1: ON_ERROR_STOP + one transaction — the restore is all or nothing
RESTORE_STRICT=1 bash /opt/petprep/repo/scripts/restore-production-db.sh /opt/petprep/backups/pre-reset/pre-reset_db_<UTC>.sql.gz
docker compose -f compose.production.yaml exec -T --user 1000:1000 app \
  tar -xzf - -C /var/www/html/storage/app < /opt/petprep/backups/pre-reset/pre-reset_media_<UTC>.tar.gz
docker compose -f compose.production.yaml up -d queue queue-broadcasts scheduler
docker compose -f compose.production.yaml exec -T --user 1000:1000 app php artisan up
# Post-restore check: the dry run must show the old row counts again (pets > 0, media files > 0)
bash /opt/petprep/repo/scripts/reset-game-data.sh
```
(The dump is `pg_dump --clean --if-exists`, so it replaces every table. If the strict restore stops with an error, nothing was changed — read the error before retrying; without `RESTORE_STRICT` psql would continue past errors. Redis queues and the cache are not restored — nothing in them is needed.)

**After the reset:**
1. **Filament → Users:** only the superadmins remain (ids from the dry run). **The seeded `admin@petprep.io` uses the password from the repository (`SuperadminSeeder`) — if it is in the list, change its password now, or remove its superadmin flag** (ROADMAP M0-15). Admins log in to `/admin` again (sessions were deleted).
2. **Testers (and David's own phones):** the old login no longer exists — the app gets 401. Delete and reinstall the app (or log out), register the parent account again (the same e-mail works), create the child profile and the dog from scratch. Purchases made in the sandbox before the reset are gone from our ledger — buy the challenge again (sandbox).
3. The AI spend counter for the month restarted at 0 (the ledger was deleted).

---

## 7. Rollback Procedure

### Code Rollback
A failed deploy reverts the code by itself when the failure happens before migrations succeed. For a deliberate rollback to an older commit, merge a **revert commit** (normal PR + merge — since D18 a merge deploys nothing), then **Run workflow** on `main` → target `production`. **Re-running an old run fails by design** (deploy guard, DEPLOYMENT.md D16: its commit is no longer the head of `main`); "Re-run all jobs" redeploys only a **production dispatch run of the current `main` head** — re-running a push run deploys nothing. Only roll code back past a migration if that migration is backward compatible — otherwise restore the database too (below). `cat /opt/petprep/repo/.deployed-sha` shows what is live.

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

### Off-site restore (lost disk / new server, D5)
Run on the (new) server after the stack is deployed with the old `/opt/petprep/.env` (from the password manager — same APP_KEY) and `backup.env` + `backup-ssh/` are back:

```bash
cd /opt/petprep/repo/backend
R="docker run --rm -i --env-file /opt/petprep/backup.env --hostname petprep-production -v /opt/petprep/backup-ssh:/root/.ssh:ro"
O='-o sftp.command=ssh -F /root/.ssh/config storagebox -s sftp'
docker compose -p backend -f compose.production.yaml exec app php artisan down
$R restic/restic:0.17.3 "$O" snapshots --tag db     # pick the snapshot ID (newest = latest)

# Database: chosen snapshot → .sql.gz; check the trailer, then a strict restore
$R restic/restic:0.17.3 "$O" dump <ID> /db/petprep.sql | gzip > /opt/petprep/backups/offsite-restore.sql.gz
zcat /opt/petprep/backups/offsite-restore.sql.gz | tail -n 20 | grep -q "PostgreSQL database dump complete" \
  || { echo "TRUNCATED dump — pick an older snapshot ID"; exit 1; }
RESTORE_STRICT=1 /opt/petprep/scripts/restore-production-db.sh /opt/petprep/backups/offsite-restore.sql.gz

# Pet media: into the volume
docker run --rm -i --env-file /opt/petprep/backup.env --hostname petprep-production \
  -v /opt/petprep/backup-ssh:/root/.ssh:ro -v backend_app_storage:/data restic/restic:0.17.3 "$O" \
  restore latest --tag media --target /
docker run --rm -v backend_app_storage:/data alpine:3.20 chown -R 1000:1000 /data/app/pet-media   # user `petprep` of the PHP image
docker compose -p backend -f compose.production.yaml exec app php artisan up
```

**Pet media on object storage (M4-05):** the bucket is a separate service — after a lost server it is still there; only point the new server's `.env` at it. If the bucket itself is lost: create a new bucket, copy the mirror back (`docker run --rm --env-file <rclone env with both remotes, see backup-offsite.sh> rclone/rclone:1.68.2 copy mirror:petprep-media-mirror/current media:<new bucket>/pet-media`), set `PET_MEDIA_S3_BUCKET` and deploy.

**Deleted accounts come back with a restore:** a family deleted after the snapshot is in the restored database again. There is no deletion log yet (open, DEPLOYMENT.md D5) — until there is, check the parent support inbox / admin notes for deletions since the snapshot date and delete those families again in the admin before `artisan up`.

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

# Laravel's own log file (Log::*, report($e)) — inside the app_storage volume,
# NOT in /opt/petprep/repo/backend/storage/logs (that directory is not mounted)
docker exec backend-app-1 tail -n 200 storage/logs/laravel.log

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

**Errors without SSH (System health, David 2026-10-19).** Every reported exception and every error-level log line is also stored in the `system_errors` table and shown on **`/admin/system-health`** (superadmin): open errors, failed jobs (retry / forget), queue backlog, stuck routine ledgers, AI media failures, scheduler heartbeat (red after 5 min without a `pets:process-decay` tick) and disk usage. Check it first; the log file above is the fallback (full context). Kill switch: `SYSTEM_HEALTH_CAPTURE=false`.

**Breed catalogue cache (M5-R06-01).** Free / paid and the picker catalogue are read from `breed_configs` through a cache (`breed-catalog:v1`, 5 min). Filament edits and the deploy seeder clear it automatically. After a **manual SQL edit** of `breed_configs` clear it yourself, otherwise the old values apply for up to 5 minutes:

```bash
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan cache:forget breed-catalog:v1
```

Never use raw SQL to change `premium_unlock`: the model refuses any change that leaves a species without exactly one free breed (existing pets read it live for refunds and plan display) — raw SQL bypasses that check. A cache outage never blocks the game: the catalogue then reads the table directly (logged as a warning).

**Frozen same-frame clips (fix 2026-10-23).** Loops and same-pose school / game clips made before the fix play almost frozen (Kling got the same image as start and end frame). Remake them — dry run first (no fal call), then the real run (budget-checked, audited, the old clip stays visible until the new one is stored). `--pet` of a pet on a shared look remakes that look's clips once for every pet on it (`--pet` and `--look` are exclusive); without options = every affected pet and look. A remake that fails keeps the frozen clip and shows up in the next dry run again. Same as Pet → »AI media« → »Regenerate frozen clips« in `/admin`.

```bash
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:regenerate-frozen --pet=<id> --dry-run
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:regenerate-frozen --pet=<id>
```

**Clip quality check (David 2026-10-23).** Every new AI clip is checked before children see it (ffmpeg technical check + AI vision check; one automatic regeneration, a second failure → »Needs review« in `/admin` Pet → AI media, counted on the dashboard and System health). The production app image ships **ffmpeg / ffprobe** (Debian package in `backend/docker/production/Dockerfile`, ~+250 MB uncompressed; check: `docker compose … exec -T app ffprobe -version`). The AI part stays off until the vision profile price is confirmed — then set `AI_QUALITY_VISION_ENABLED=true` in `/opt/petprep/.env` and redeploy (without it the technical check alone decides and clips are marked »not AI-checked«). **Mode** (`/admin → Feature switches → Quality check`, QA #363 M1): **shadow** is the default — every verdict and its measurements are recorded (Pet → AI media column »Quality«: »would fail (shadow)«, filter »Needs review / would fail«) but every clip is still shown and nothing is regenerated. Calibrate first: run the dry run below on Suki (pet 8) and a few other pets, compare the printed metrics with what you see in the clips, and tune the `AI_QC_*` thresholds in `/opt/petprep/.env` (PRODUCTION_ENV.md; redeploy). Then switch the radio to **enforce** (one automatic regeneration, a second failure is hidden — »Needs review«, »Approve anyway« with »Open candidate« / »Open contact sheet« links). **off** = no check. Already stored clips — dry run first (technical only, free, writes nothing), then the real run (AI check cost printed first; failures are flagged and hidden), `--regenerate` remakes each failure once:

```bash
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:quality-check --pet=<id> --dry-run
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:quality-check --pet=<id> --regenerate
```

`--regenerate` prints the cost and refuses when it does not fit the budget (QA #363 m5). In shadow mode a real run marks failures »would fail« (still shown); in enforce mode it hides them. `--pet` of a pet on a shared look checks that look's rows once; `--look=<id>` a look; no option = every stored clip without a verdict (`--recheck` also the checked ones). »Approve anyway« (superadmin) in Pet → AI media shows a »Needs review« / flagged clip anyway.

**Stored video compression (David 2026-10-30).** Kling clips are stored as delivered (1076 × 1924, 24 fps, ~23 Mbps — 5 s ≈ 14–15 MB). `media:compress` re-encodes each clip file once at the same path (H.264 CRF 21, same resolution and fps) and keeps it only when it is ≥ 30 % smaller with SSIM ≥ 0.98 and the same length / frames; otherwise the original stays. The scheduled run (hourly at :37, 22:00–06:00 Ljubljana, ≤ 10 min per run, niced, 2 ffmpeg threads, ~570 MB peak RAM per encode) does nothing until the superadmin switch **`/admin → Feature switches → »Video compression (disk)«`** is on (default off). Before switching on, measure on real clips (nothing is changed by these):

```bash
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:compress --dry-run
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:compress --sample=10
docker compose -f /opt/petprep/repo/backend/compose.production.yaml exec -T app php artisan media:disk-report
```

`--sample=N` prints size before → after, saving % and SSIM per file. A manual real run (switch on) can be limited with `--limit=`, `--pet=`, `--look=`, `--budget=<seconds>` (0 = no time limit). `media:disk-report` is read-only: usage by kind, what compression saved, files on disk, orphans (no DB row), old clip candidates and leftover `*.compress-tmp` files — it never deletes; deleting production media needs David.

---

## 9. DNS & Domain Configuration

When assigning a domain name (e.g. `api.petprep.io`):

1. **DNS A Record:** Point `api.petprep.io` -> `138.199.172.97`.
2. **Update `.env` on server:**
   ```bash
   APP_URL=https://api.petprep.io
   REVERB_HOST=api.petprep.io
   REVERB_PORT=443
   REVERB_SCHEME=https
   # optional (D18): derived from APP_URL by deploy-production.sh when unset
   # PETPREP_API_DOMAIN=api.petprep.io
   # PETPREP_CADDY_SITES=api-only      # production = + petprep.si / www / raw-IP site
   ```
3. **Redeploy** (the Caddyfile serves `{$PETPREP_API_DOMAIN}`; Caddy provisions the Let's Encrypt certificate). The production job passes `--expect-domain api.petprep.si` — a new production domain also needs that value changed in the workflow.

Current name: `api.petprep.si` → `138.199.172.97` (production). (A dev server `api-dev.petprep.si` is postponed, D18.)

## 10. Marketing website (petprep.si)

The public website is a separate repository, `DataVallis/pet-prep-website`, deployed to the same server:

- **Container:** `petprep-website` (image `petprep-website:production`, `:previous` for rollback), Node 22 Next.js standalone server on port 3000, no published ports; joins `backend_petprep-network` with the alias `website`.
- **Files:** `/opt/petprep/website/incoming` (last uploaded commit, holds `deploy/compose.yaml` and `deploy/deploy.sh`), `/opt/petprep/website/.deployed-sha`.
- **Deploy:** push to `main` in the website repo → GitHub Actions (lint, build, Docker smoke test) → rsync + `deploy/deploy.sh` as `deploy`. Secrets in that repo: `PRODUCTION_SSH_PRIVATE_KEY` (same key as here), optional `PRODUCTION_HOST`, `PRODUCTION_USER`.
- **Routing:** Caddy (this repo, `deployment/Caddyfile`) serves `petprep.si` → `website:3000` and redirects `www.petprep.si` → `petprep.si`. The API stays on `api.petprep.si`.
- **Logs / restart:** `docker logs -f petprep-website`; `docker compose -f /opt/petprep/website/incoming/deploy/compose.yaml up -d --force-recreate`.
- **Rollback:** `docker tag petprep-website:previous petprep-website:production` and the restart command above.

## Disk hygiene (David 2026-10-15)

Measured 2026-10-15: 30 GB used on the 38 GB disk — **23.4 GB Docker build cache**, 5.4 GB images (incl. an unused 3 GB `petprep-backend:production` from the Sail era), 0.6 GB journald; the app itself (DB + pet media + backups) < 1 GB.

Automatic since then:
- `scripts/deploy-production.sh`: `docker builder prune -af --keep-storage 2GB` (was "older than 7 days", which never matched with several builds a day), `docker image prune -f`, and a warning when `/` is ≥ 70 % full.
- Website `deploy/deploy.sh`: same cache cap (1 GB).
- `compose.production.yaml` (all services) and the website compose: container logs `json-file` 3 × 10 MB.

One-time on the server (root) — journald cap:
```bash
sed -i 's/^#\?SystemMaxUse=.*/SystemMaxUse=200M/' /etc/systemd/journald.conf && systemctl restart systemd-journald
```

Manual cleanup if ever needed (safe while the app runs):
```bash
docker system df
docker builder prune -af          # build cache
docker image prune -f             # untagged images
journalctl --vacuum-size=200M
```

