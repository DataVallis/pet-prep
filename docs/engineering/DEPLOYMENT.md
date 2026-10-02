# PetPrep — Deployment overview

**Source of truth for production:** `docs/PRODUCTION_DEPLOYMENT.md` (architecture, server layout, CI/CD, backups, rollback) and `docs/PRODUCTION_ENV.md` (env variables). This file only summarises and tracks review findings.

## Current state (2026-10-02)

- **Live:** `https://api.petprep.si` (health `/up` responds 200).
- **Server:** Hetzner CX23, Ubuntu 26.04, `138.199.172.97`. Admin `root@`, deploy user `deploy@` (key `~/.ssh/petprep_deploy_key`).
- **Stack:** single-host Docker Compose (`backend/compose.production.yaml`): Caddy (TLS, proxies Reverb `/app/*`) → `app` (Laravel, PHP 8.3) · `reverb` · `queue` · `scheduler` (`schedule:work`) · `postgres:18` · `redis`.
- **Paths:** `/opt/petprep/{.env, repo/, backups/, scripts/}`.
- **Pipeline:** `.github/workflows/deploy-production.yml` ("CI & Deploy") — every PR and push to `main` runs backend tests (PostgreSQL) and mobile checks; **deploy to production only when started manually** (GitHub → Actions → CI & Deploy → Run workflow on `main`), after both test jobs pass. Pre-deploy DB backup, 7-day retention.

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
| D2 | ✅ *Fixed 2026-10-02 (M0-08): deploy runs only via manual "Run workflow" on `main` (Actions → CI & Deploy); pushes/PRs only run tests.* Auto-deploy on every push to `main` with no manual approval → any merge goes live. Add required reviewers on the `production` environment or deploy on tags. | High |
| D3 | Production runs **PHP 8.3** (`docker/8.3`, CI 8.3) while local Sail uses 8.5. Pick one (8.4/8.5) for dev, CI and prod. | Medium |
| D4 | `deploy-production.sh` ignores `git checkout` failures (`|| true`) and rsync `--delete` overwrites the server tree — fine for rsync deploys, but the script's git branch is dead code. | Low |
| D5 | Backups stay on the same disk (`/opt/petprep/backups`) → copy off-site (Hetzner Storage Box / S3) and test a restore. | Medium |
| D6 | Mobile app has quick-login buttons with seeded test credentials and defaults to the production API → hide behind `__DEV__`, ensure `TestUsersSeeder` never runs in prod. | High |
| D7 | Webhook secrets (`FAL_AI_WEBHOOK_SECRET`, `REVENUECAT_SECRET_KEY`) must be set in `/opt/petprep/.env` before those integrations are enabled — handlers are fail-open today. | High |
| D8 | Caddyfile also serves plain HTTP on the raw IP (`http://138.199.172.97`) incl. API/admin → restrict or redirect once the domain is stable. | Medium |
