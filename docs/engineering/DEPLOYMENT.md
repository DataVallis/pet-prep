# PetPrep — Deployment overview

**Source of truth for production:** `docs/PRODUCTION_DEPLOYMENT.md` (architecture, server layout, CI/CD, backups, rollback) and `docs/PRODUCTION_ENV.md` (env variables). This file only summarises and tracks review findings.

## Current state (2026-10-03)

- **2026-10-03:** first successful deploy through GitHub Actions (manual "Run workflow" on `main`). Repository secret `PRODUCTION_SSH_PRIVATE_KEY` = dedicated ED25519 key `github-actions-deploy@petprep`, public half in `/home/deploy/.ssh/authorized_keys` (private copy removed from the server). `FAL_AI_API_KEY` set in `/opt/petprep/.env`; verified `fal OK`, migration `pet_media_jobs` ran, all containers recreated.

- **Live:** `https://api.petprep.si` (health `/up` responds 200).
- **Server:** Hetzner CX23, Ubuntu 26.04, `138.199.172.97`. Admin `root@`, deploy user `deploy@` (key `~/.ssh/petprep_deploy_key`).
- **Stack:** single-host Docker Compose (`backend/compose.production.yaml`): Caddy (TLS, proxies Reverb `/app/*`) → `app` (Laravel, PHP 8.3) · `reverb` · `queue` (`--queue=default`, fal.ai) · `queue-broadcasts` (`--queue=broadcasts`, PetUpdated → Reverb, M1-09) · `scheduler` (`schedule:work`) · `postgres:18` · `redis`.
- **Paths:** `/opt/petprep/{.env, repo/, backups/, scripts/}`.
- **Pipeline:** `.github/workflows/deploy-production.yml` ("CI & Deploy") — every PR and push to `main` runs backend tests (PostgreSQL) and mobile checks; **pre-production phase: every merge to `main` deploys automatically** after the test jobs pass (D2). CI uploads to `/opt/petprep/incoming`; the deploy script switches the code inside maintenance mode and reverts it on a pre-migration failure (D10). Pre-deploy DB backup, 7-day retention.

## SSH access from a new computer

The server only accepts keys. Add the new machine's public key from a computer that already has access:
```bash
# on the NEW computer
ls ~/.ssh/id_ed25519.pub || ssh-keygen -t ed25519 -C "david@datavallis.com"
cat ~/.ssh/id_ed25519.pub            # copy this line
# on the computer that ALREADY has access
ssh root@138.199.172.97 'cat >> ~/.ssh/authorized_keys' <<< 'ssh-ed25519 AAAA... david@new-mac'
# back on the NEW computer
ssh root@138.199.172.97 'hostname && docker ps --format "{{.Names}}: {{.Status}}"'
```
For deployments as `deploy@`, copy `~/.ssh/petprep_deploy_key` securely (e.g. AirDrop / password manager) or add the new public key to `/home/deploy/.ssh/authorized_keys` the same way.

## Review findings (to fix — tracked in ROADMAP M0/M5)

| # | Finding | Risk |
|---|---|---|
| D1 | ✅ *Fixed 2026-10-02 (M0-08): Pest runs on a `postgres:18` service; mobile tsc + Jest job added.* CI test job copies `backend/.env.example` (sqlite) — migrations use PostgreSQL-only SQL (CHECK constraints, GIN index) → tests fail in CI, so auto-deploy is blocked or was bypassed. Add a `postgres:18` service + pgsql env to the workflow. | High |
| D2 | ✅ *2026-10-02: manual deploys introduced. 2026-10-03 (David): pre-production phase → merges to `main` deploy automatically after tests; restore manual-only before real users.* Auto-deploy on every push to `main` with no manual approval → any merge goes live. Add required reviewers on the `production` environment or deploy on tags. | High |
| D3 | Production runs **PHP 8.3** (`docker/8.3`, CI 8.3) while local Sail uses 8.5. Pick one (8.4/8.5) for dev, CI and prod. | Medium |
| D4 | ✅ *Fixed 2026-10-04 (M2-01, see D10): CI rsyncs to `/opt/petprep/incoming`, the script switches the code itself; `git checkout` failures now abort (manual git mode).* `deploy-production.sh` ignores `git checkout` failures (`|| true`) and rsync `--delete` overwrites the server tree — fine for rsync deploys, but the script's git branch is dead code. | Low |
| D5 | Backups stay on the same disk (`/opt/petprep/backups`) → copy off-site (Hetzner Storage Box / S3) and test a restore. | Medium |
| D6 | Mobile app has quick-login buttons with seeded test credentials and defaults to the production API → hide behind `__DEV__`, ensure `TestUsersSeeder` never runs in prod. | High |
| D7 | `REVENUECAT_SECRET_KEY` must be set in `/opt/petprep/.env` before RevenueCat is enabled — that handler is still fail-open (M3-08). fal.ai webhooks are now signature-verified and fail closed (M4-04); no fal secret exists. | High |
| D8 | Caddyfile also serves plain HTTP on the raw IP (`http://138.199.172.97`) incl. API/admin → restrict or redirect once the domain is stable. | Medium |
| D9 | **M1-08/09 (2026-10-04), required on deploy:** (a) `/opt/petprep/.env` must have `BROADCAST_CONNECTION=reverb` and `QUEUE_CONNECTION=redis` (a `sync` queue would deliver inline again; `log`/`null` broadcasts nothing) plus `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` — no new keys. (b) New service `queue-broadcasts` must be running: `deploy-production.sh` now starts it (`up -d … queue queue-broadcasts …`); without it broadcasts pile up in Redis. (c) Channel auth is `POST https://api.petprep.si/api/broadcasting/auth` (Caddy already routes `/api/*` to `app`; no Caddyfile change). (d) Apps on the old public channel `pet.updated.{id}` stop receiving updates — ship the mobile build from the same branch. Optional: point the server-side broadcaster at `reverb:8080` over the Docker network instead of the public hostname (saves a TLS round trip through Caddy). | High |
| D10 | ✅ *Fixed 2026-10-04 (M2-01 review, PR #14).* All PHP containers bind-mount `repo/backend`, so changing the files switches the **running** app to the new code at once. Before: CI rsynced straight into `repo/` and the script entered maintenance only right before `migrate` → minutes of new code on the old schema (500s, scheduler errors); a failed migrate brought the app up on new code + old schema; one failed `artisan up` left the app in maintenance forever. **Now:** CI uploads to `/opt/petprep/incoming` and runs `incoming/scripts/deploy-production.sh --from /opt/petprep/incoming <SHA>`. Order: env preflight (no git/docker before it) → previous release recorded (`repo/.deployed-sha` + tree snapshot `/opt/petprep/releases/previous`) → `artisan down --retry=15` on the old code (exec, fallback `run --rm`, warn-and-continue on a fresh server) → backup (aborts if Postgres runs and the backup fails; `DEPLOY_ALLOW_NO_BACKUP=1` overrides) → code switch → build → migrate → caches → `up -d` → `queue:restart` → `artisan up` (3 attempts, flag reset only on success, else loud error + manual command, exit ≠ 0) → health check. **Rollback:** failure before migrations succeeded → code reverted to the previous release, workers restarted, `artisan up` (old code + old schema). Failure after → code **not** reverted (new schema), stale caches cleared, containers restarted, `artisan up`, loud error, exit ≠ 0. If the revert itself fails the app stays in maintenance. Caveat: migrations run one transaction each, so a failure in migration N keeps 1..N-1 — reverting relies on additive migrations; otherwise restore the pre-deploy backup. Tested by `scripts/tests/deploy-production.test.sh` (stubbed docker/git/curl, CI job `deploy-script-tests`); not yet run against the server. | High |
| D11 | **M2-02 (2026-10-04), on deploy:** (a) **Trusted proxies:** Laravel now trusts `X-Forwarded-For` / `-Proto` from private and loopback peers (`127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`, override with `TRUSTED_PROXIES`). Before, `$request->ip()` was Caddy's container IP for every request — every guest-keyed limit (webhooks, and now `POST /api/child/pin-login`) was effectively global. Safe because only Caddy publishes ports (`app` has none) and Caddy overwrites `X-Forwarded-For` with `{remote_host}`. Side effect: URLs generated from the request now see `https` from Caddy (correct). (b) Migration `2026_10_04_150000_add_pin_only_child_profiles` is additive (nullable `users.email` / `password`, new `users.birth_year`, new `child_login_pins`); `down()` refuses while PIN-only children exist. (c) Existing tokens (`abilities ["*"]`) keep working; nobody is logged out. (d) PIN-login lockouts use the cache store (`CACHE_STORE`, Redis in production) — a cache flush resets them. | Medium |
