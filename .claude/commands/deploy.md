---
description: Inspect or deploy the PetPrep production stack on Hetzner
argument-hint: [status|release <sha>|backup]
---

Mode: `$ARGUMENTS` (default `status`). Delegate to the `devops` agent; follow `docs/PRODUCTION_DEPLOYMENT.md`.

- `status` — `ssh root@138.199.172.97` (or `deploy@` with `~/.ssh/petprep_deploy_key`): OS, disk/RAM (`df -h`, `free -h`), `docker compose -f /opt/petprep/repo/backend/compose.production.yaml ps`, deployed commit, latest file in `/opt/petprep/backups/`, `curl -fsS https://api.petprep.si/up`. Change nothing.
- `release <sha>` — only when David asks and `/verify` is green: run `/opt/petprep/repo/scripts/deploy-production.sh <sha>` as `deploy@` (normally GitHub Actions does this on push to `main`), then smoke test `/up`, login, WebSocket connect.
- `backup` — run `/opt/petprep/scripts/backup-production-db.sh` and report the file size.

If SSH fails with "Permission denied (publickey)", this computer's key isn't on the server yet — see "SSH access from a new computer" in `docs/engineering/DEPLOYMENT.md`.
Afterwards update `docs/engineering/DEPLOYMENT.md` (state + findings) and HANDOFF.md.
