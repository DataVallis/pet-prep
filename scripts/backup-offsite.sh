#!/usr/bin/env bash
# PetPrep off-site backup (DEPLOYMENT.md D5, David 2026-10-14): the production
# database AND the pet media (AI images / videos) leave the server every night.
#
# Why: /opt/petprep/backups (pg_dump before each deploy) lives on the same disk
# as the database, and the pet media (Docker volume <project>_app_storage →
# storage/app/pet-media) had no backup at all. A lost disk = every family's
# pet history and every paid AI image / video gone.
#
# How: restic (encrypted, incremental, de-duplicated) in its official Docker
# image — nothing to install on the host besides Docker:
#   * database: pg_dump inside the postgres container | restic backup --stdin
#     (file /db/petprep.sql). Stored first with tag `db-pending`; only a run whose
#     pg_dump succeeded is re-tagged `db`, a failed one is forgotten — `latest
#     --tag db` is never a truncated dump. The ops lock is held ONLY for this step.
#   * media: the app_storage volume mounted read-only, app/pet-media (tag
#     `media`) — only new / changed files are uploaded (--ignore-ctime: deploys
#     chown the volume, which changes ctime but not content).
#   * retention (David 2026-10-14: deleted families must leave the backups
#     within 35 days): every snapshot of the last 35 days per tag, then prune
#     (--keep-within 35d; daily + weekly buckets overlap in restic, so
#     "14 daily + 3 weekly" would only reach back ~14 days).
#   * Sundays: restic check with a 5 % data sample.
#   * every restic call has a hard timeout; sftp keep-alives come from the ssh
#     config (a dead connection never holds anything for hours).
#
# Configuration (David, once — docs/PRODUCTION_DEPLOYMENT.md §6b):
#   /opt/petprep/backup.env (chmod 600, never in git; KEY=value, no quotes, no comments):
#     RESTIC_REPOSITORY=sftp:storagebox:petprep     (or s3:https://…/bucket)
#     RESTIC_PASSWORD=<long random — keep a copy OFF the server, without it
#                      the backups cannot be read>
#     [AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY for an S3 repository]
#     [BACKUP_HEALTHCHECK_URL=https://hc-ping.com/<uuid> — pinged on success,
#      <url>/fail on failure, so a silent cron failure gets noticed]
#   /opt/petprep/backup-ssh/ (sftp only): config (Host storagebox …), id_ed25519,
#     known_hosts. ssh runs as root in the container and would reject a
#     ~/.ssh/config owned by the deploy user, so the config is passed with -F
#     (no owner check for -F files).
#   cron (deploy user): 17 2 * * * /opt/petprep/scripts/backup-offsite.sh >> /opt/petprep/backups/offsite.log 2>&1
#
# Usage:
#   backup-offsite.sh            nightly run (init repo if needed, db, media, retention, check on Sundays)
#   backup-offsite.sh verify     restore test: latest `db` snapshot must be a complete pg_dump,
#                                latest media snapshot must hold files when the volume has some
#   backup-offsite.sh snapshots  list snapshots
#
# Exit codes: 0 ok · 1 failed (nothing on the server is changed either way).

set -Eeuo pipefail

PETPREP_ROOT="${PETPREP_ROOT:-/opt/petprep}"
BACKUP_ENV="${PETPREP_BACKUP_ENV:-${PETPREP_ROOT}/backup.env}"
SSH_DIR="${PETPREP_BACKUP_SSH_DIR:-${PETPREP_ROOT}/backup-ssh}"
ENV_FILE="${PETPREP_ENV_FILE:-${PETPREP_ROOT}/.env}"
COMPOSE_FILE="${PETPREP_COMPOSE_FILE:-${PETPREP_ROOT}/repo/backend/compose.production.yaml}"
COMPOSE_PROJECT="${PETPREP_COMPOSE_PROJECT:-backend}"
MEDIA_VOLUME="${COMPOSE_PROJECT}_app_storage"
OPS_LOCK="${PETPREP_OPS_LOCK:-${PETPREP_ROOT}/.ops.lock}"
STATUS_FILE="${PETPREP_BACKUP_STATUS_FILE:-${PETPREP_ROOT}/backups/offsite-last-success}"
LOCK_WAIT_SECONDS="${PETPREP_BACKUP_LOCK_WAIT:-1800}"
# The dump holds the ops lock; deploys wait 900 s for it.
DB_TIMEOUT="${PETPREP_BACKUP_DB_TIMEOUT:-1800}"
MEDIA_TIMEOUT="${PETPREP_BACKUP_MEDIA_TIMEOUT:-21600}"
SHORT_TIMEOUT="${PETPREP_BACKUP_SHORT_TIMEOUT:-3600}"
RESTIC_IMAGE="restic/restic:0.17.3"
CACHE_VOLUME="petprep-restic-cache"
MEDIA_PATH="/data/app/pet-media"
MODE="${1:-run}"

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }

BACKUP_HEALTHCHECK_URL=""
healthcheck() {
    # $1 = "" (success) or "/fail". Never fails the run.
    if [ -n "$BACKUP_HEALTHCHECK_URL" ] && command -v curl >/dev/null 2>&1; then
        curl -fsS -m 10 --retry 3 -o /dev/null "${BACKUP_HEALTHCHECK_URL}$1" || log "WARNING: healthcheck ping failed"
    fi
}

fail() {
    log "ERROR: $*" >&2
    healthcheck "/fail"
    exit 1
}

case "$MODE" in
    run|verify|snapshots) ;;
    *) echo "Usage: $0 [run|verify|snapshots]" >&2; exit 1 ;;
esac

[ -f "$BACKUP_ENV" ] || fail "${BACKUP_ENV} not found — off-site backup is not configured (docs/PRODUCTION_DEPLOYMENT.md §6b)."
# Only the keys this script needs; the file is never sourced as shell code.
# docker --env-file keeps quotes and comments literally → refuse them here.
read_key() { grep -E "^$1=" "$BACKUP_ENV" | tail -n 1 | cut -d '=' -f2- || true; }
for key in RESTIC_REPOSITORY RESTIC_PASSWORD BACKUP_HEALTHCHECK_URL; do
    value="$(read_key "$key")"
    case "$value" in
        \"*|\'*|*" #"*) fail "${key} in ${BACKUP_ENV} has quotes or an inline comment — write KEY=value only." ;;
    esac
done
BACKUP_HEALTHCHECK_URL="$(read_key BACKUP_HEALTHCHECK_URL)"
[ -n "$(read_key RESTIC_REPOSITORY)" ] || fail "RESTIC_REPOSITORY is missing in ${BACKUP_ENV}."
[ -n "$(read_key RESTIC_PASSWORD)" ] || fail "RESTIC_PASSWORD is missing in ${BACKUP_ENV}."

mkdir -p "${PETPREP_ROOT}/backups"

dc() { docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" "$@"; }

docker volume inspect "$MEDIA_VOLUME" >/dev/null 2>&1 || fail "Docker volume ${MEDIA_VOLUME} not found (docker running? deploy user in the docker group?)."

# restic in a throw-away container: repository credentials from backup.env,
# the ssh files for sftp (config via -F, see header), a persistent cache
# (faster incremental runs) and the media volume read-only. $1 = timeout (s).
restic_run() {
    local limit="$1"
    shift
    # -i only when stdin is a pipe (the DB dump): `timeout` puts its child in a
    # background process group, and a docker client reading a terminal there
    # stops on SIGTTIN (manual runs would hang until the timeout).
    local interactive=()
    if [ ! -t 0 ] && [ "${RESTIC_STDIN:-0}" = "1" ]; then
        interactive=(-i)
    fi
    local args=(run --rm "${interactive[@]}" --env-file "$BACKUP_ENV" --hostname petprep-production
        -v "${CACHE_VOLUME}:/root/.cache/restic"
        -v "${MEDIA_VOLUME}:/data:ro")
    local opts=()
    if [ -d "$SSH_DIR" ]; then
        args+=(-v "${SSH_DIR}:/root/.ssh:ro")
        if [ -f "${SSH_DIR}/config" ]; then
            opts=(-o "sftp.command=ssh -F /root/.ssh/config storagebox -s sftp")
        fi
    fi
    # --kill-after: the docker client forwards SIGTERM to the container (sig-proxy).
    if [ "${RESTIC_STDIN:-0}" = "1" ]; then
        timeout --kill-after=60 "$limit" docker "${args[@]}" "$RESTIC_IMAGE" "${opts[@]}" "$@"
    else
        timeout --kill-after=60 "$limit" docker "${args[@]}" "$RESTIC_IMAGE" "${opts[@]}" "$@" </dev/null
    fi
}

if [ "$MODE" = "snapshots" ]; then
    restic_run "$SHORT_TIMEOUT" snapshots
    exit 0
fi

DB_USER="$(grep -E '^DB_USERNAME=' "$ENV_FILE" 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" || true)"
DB_NAME="$(grep -E '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" || true)"
if [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    fail "DB_USERNAME / DB_DATABASE not found in ${ENV_FILE}."
fi

if [ "$MODE" = "verify" ]; then
    log "Restore test: latest DB snapshot…"
    # First 40 + last 20 lines of the dump (streamed, no temp file; pg_dump 18
    # writes \unrestrict after the "dump complete" trailer).
    head_tail="$(restic_run "$SHORT_TIMEOUT" dump latest --tag db /db/petprep.sql \
        | awk 'NR <= 40 { print } { tail[NR % 20] = $0 } END { for (i = NR - 19; i <= NR; i++) if (i > 40) print tail[i % 20] }')" \
        || fail "could not read the latest DB snapshot."
    grep -q "PostgreSQL database dump" <<<"$head_tail" || fail "latest DB snapshot is not a pg_dump."
    grep -q "PostgreSQL database dump complete" <<<"$head_tail" || fail "latest DB snapshot is truncated (no 'dump complete' trailer)."
    log "DB snapshot OK (complete pg_dump)."

    live="$(docker run --rm -v "${MEDIA_VOLUME}:/data:ro" alpine:3.20 sh -c "find ${MEDIA_PATH} -type f 2>/dev/null | wc -l" | tr -d ' ' || true)"
    case "$live" in ''|*[!0-9]*) fail "could not count the live media files." ;; esac
    # --recursive: media sit in {pet_id}/ and looks/{id}/; --long: files start with "-".
    saved="$(restic_run "$SHORT_TIMEOUT" ls --recursive --long latest --tag media "$MEDIA_PATH" | grep -c '^-' || true)"
    log "Media files: live ${live}, latest snapshot ${saved}."
    # Media added since the last run are expected to be missing; an empty
    # snapshot of a non-empty volume is not.
    if [ "$live" -gt 0 ] && [ "$saved" -eq 0 ]; then
        fail "latest media snapshot holds no files while the volume has ${live}."
    fi
    log "Restore test passed."
    exit 0
fi

# ---- Nightly run -----------------------------------------------------------------
# One run at a time (cron vs. a manual run): another run's pending DB snapshot
# would otherwise look like a leftover and be forgotten.
exec 8>>"${PETPREP_ROOT}/.offsite.lock"
flock -n 8 || fail "another off-site backup run is still in progress."

log "=== Off-site backup started ==="

set +e
restic_run "$SHORT_TIMEOUT" cat config >/dev/null 2>"${PETPREP_ROOT}/backups/.offsite-init.err"
rc=$?
set -e
if [ "$rc" -eq 10 ]; then
    log "Repository does not exist yet — restic init…"
    restic_run "$SHORT_TIMEOUT" init || fail "restic init failed (check RESTIC_REPOSITORY / ssh access)."
elif [ "$rc" -ne 0 ]; then
    fail "repository not reachable (restic exit ${rc}): $(tail -n 3 "${PETPREP_ROOT}/backups/.offsite-init.err" 2>/dev/null | tr '\n' ' ')"
fi
rm -f "${PETPREP_ROOT}/backups/.offsite-init.err"

# Leftovers of a killed run (still `db-pending`) are never a valid DB backup.
pending_ids() {
    restic_run "$SHORT_TIMEOUT" snapshots --tag db-pending --json | grep -o '"id":"[0-9a-f]\{64\}"' | cut -d '"' -f4 || true
}
mapfile -t stale < <(pending_ids)
if [ "${#stale[@]}" -gt 0 ]; then
    log "Removing ${#stale[@]} unfinished DB snapshot(s) of an earlier run…"
    restic_run "$SHORT_TIMEOUT" forget "${stale[@]}" --quiet || fail "could not remove unfinished DB snapshots."
fi

# ---- Database (ops lock held only here) ---------------------------------------------
exec 9>>"$OPS_LOCK"
if ! flock -w "$LOCK_WAIT_SECONDS" 9; then
    fail "ops lock busy for ${LOCK_WAIT_SECONDS} s (deploy or reset running) — backup skipped."
fi

dc ps postgres | grep -q "Up" || fail "postgres container is not running."

log "Database → restic (stdin, tag db-pending)…"
set +e
dc exec -T postgres pg_dump -U "$DB_USER" -d "$DB_NAME" --clean --if-exists \
    | RESTIC_STDIN=1 restic_run "$DB_TIMEOUT" backup --stdin --stdin-filename db/petprep.sql --tag db-pending --json > "${PETPREP_ROOT}/backups/.offsite-db.json"
codes=("${PIPESTATUS[@]}")
set -e
flock -u 9
exec 9>&-

db_id="$(grep -o '"snapshot_id":"[0-9a-f]*"' "${PETPREP_ROOT}/backups/.offsite-db.json" | tail -n 1 | cut -d '"' -f4 || true)"
rm -f "${PETPREP_ROOT}/backups/.offsite-db.json"
if [ "${codes[0]}" -ne 0 ] || [ "${codes[1]}" -ne 0 ] || [ -z "$db_id" ]; then
    if [ -n "$db_id" ]; then
        restic_run "$SHORT_TIMEOUT" forget "$db_id" --quiet || log "WARNING: could not remove the partial DB snapshot ${db_id} (next run removes it)."
    fi
    fail "database backup failed (pg_dump exit ${codes[0]}, restic exit ${codes[1]}) — no DB snapshot kept."
fi
restic_run "$SHORT_TIMEOUT" tag --set db "$db_id" --quiet || fail "could not mark DB snapshot ${db_id} as complete."
log "DB snapshot ${db_id:0:8} stored."

# ---- Media (no lock: the volume is mounted read-only) -----------------------------
log "Pet media → restic…"
restic_run "$MEDIA_TIMEOUT" backup "$MEDIA_PATH" --tag media --ignore-ctime --quiet || fail "media backup failed."

log "Retention (all snapshots of the last 35 days per tag) + prune…"
restic_run "$SHORT_TIMEOUT" forget --group-by host,tags --keep-within 35d --prune --quiet \
    || fail "retention / prune failed (the new snapshots are stored)."

if [ "$(date -u +%u)" = "7" ]; then
    log "Sunday: restic check (5 % data sample)…"
    restic_run "$MEDIA_TIMEOUT" check --read-data-subset=5% || fail "restic check found a problem."
fi

mkdir -p "$(dirname "$STATUS_FILE")"
date -u +%Y-%m-%dT%H:%M:%SZ > "$STATUS_FILE"
healthcheck ""
log "=== Off-site backup completed ==="
