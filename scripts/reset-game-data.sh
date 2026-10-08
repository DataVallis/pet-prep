#!/usr/bin/env bash
# PetPrep — one-off production reset of all game data (DEPLOYMENT.md D17,
# David 2026-10-08 13:50: start the ~20-tester beta from zero).
#
# Usage (on the server, as deploy, from /opt/petprep/repo, inside tmux/screen):
#   bash scripts/reset-game-data.sh                    # dry run: counts only, changes nothing
#   bash scripts/reset-game-data.sh --execute          # backup + reset (asks to type the host)
#   bash scripts/reset-game-data.sh --execute --confirm-host=api.petprep.si   # without a terminal
#
# KEEPS superadmin users (+ their API tokens), breed_configs, breed_stage_params,
# breed_stage_param_changes, migrations. DELETES everything else: families,
# parents, children, pets and all their rows, payments ledger, AI spend + AI Lab
# rows, push, sessions, cache, queues, and every file on the pet_media disk.
# The table list lives in backend/app/Services/GameDataResetService.php.
#
# --execute, in this order (nothing is changed before step 5):
#   1. Ops lock: exclusive flock on ${PETPREP_ROOT}/.ops.lock (shared with
#      deploy-production.sh) — refuses at once while a deploy runs; a deploy
#      started during the reset waits for it (DEPLOY_LOCK_WAIT) or fails cleanly.
#   2. Env preflight + dry run (prints the counts; refuses on an unclassified
#      table or an unexpected media disk root).
#   3. Typed confirmation of the APP_URL host (or --confirm-host=<host>).
#   4. Free-disk preflight: free space on backups/ ≥ 1.2 × (DB size + media size) + 100 MB.
#   5. artisan down (as uid 1000, like deploy-production.sh) — HTTP 503.
#   6. Stop the scheduler + queue workers (no tick / job writes from here on).
#   7. Fresh DB backup with the existing backup-production-db.sh, hard-linked to
#      backups/pre-reset/ (outside the 7-day retention pattern), and a tar of
#      storage/app/pet-media from the app container next to it (umask 077).
#      Abort on failure (no data deleted; the trap brings the app back).
#   8. php artisan petprep:reset-game-data --execute --i-understand-… --backup-done=<file>
#      exit 1 = nothing deleted (rolled back) · exit 2 = DB reset COMMITTED but
#      media / queue / cache cleanup failed → rerun (idempotent).
#   9. Always (EXIT trap): start the workers again and artisan up.
#
# Restore: see the end of the output and PRODUCTION_DEPLOYMENT.md §6a.
# Test harness (stubbed docker/df): scripts/tests/reset-game-data.test.sh
# Paths can be relocated for tests with PETPREP_ROOT (default /opt/petprep).

set -Eeuo pipefail
umask 077   # backups contain personal data: owner-only files

PETPREP_ROOT="${PETPREP_ROOT:-/opt/petprep}"
REPO_DIR="${PETPREP_ROOT}/repo"
ENV_FILE="${PETPREP_ROOT}/.env"
SCRIPTS_DIR="${PETPREP_ROOT}/scripts"
BACKUP_DIR="${PETPREP_ROOT}/backups"
PRE_RESET_DIR="${BACKUP_DIR}/pre-reset"
OPS_LOCK="${PETPREP_ROOT}/.ops.lock"
COMPOSE_FILE="${REPO_DIR}/backend/compose.production.yaml"
COMPOSE_PROJECT="${DEPLOY_COMPOSE_PROJECT:-backend}"
APP_UID="${DEPLOY_APP_UID:-1000}"
WORKERS=(queue queue-broadcasts scheduler)
LONG_FLAG="--i-understand-this-deletes-all-game-data"
MEDIA_DIR="/var/www/html/storage/app/pet-media"

usage() { sed -n '5,8p' "$0" | sed 's/^# \{0,1\}//'; }

EXECUTE=0
CONFIRM_HOST=""
while [ $# -gt 0 ]; do
    case "$1" in
        --execute) EXECUTE=1; shift ;;
        --confirm-host=*) CONFIRM_HOST="${1#--confirm-host=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "ERROR: unknown option $1" >&2; usage >&2; exit 2 ;;
    esac
done

log()  { echo "[reset] $*"; }
warn() { echo "[reset] WARNING: $*" >&2; }
loud() {
    local line
    echo "##################################################################" >&2
    for line in "$@"; do echo "## $line" >&2; done
    echo "##################################################################" >&2
}
dc() { docker compose -p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE" "$@"; }
# artisan in the running app container as uid 1000 (no TTY; stdin closed).
artisan() { dc exec -T --user "${APP_UID}:${APP_UID}" app php artisan "$@" < /dev/null; }
is_number() { [[ "${1:-}" =~ ^[0-9]+$ ]]; }

# ---- 1. Ops lock (execute only; held until this process exits) ----------------------
[ -f "$ENV_FILE" ] || { echo "ERROR: ${ENV_FILE} is missing." >&2; exit 1; }
[ -f "$COMPOSE_FILE" ] || { echo "ERROR: ${COMPOSE_FILE} is missing." >&2; exit 1; }
if [ "$EXECUTE" = "1" ]; then
    exec 9>"$OPS_LOCK"
    if ! flock -n 9; then
        echo "ERROR: ${OPS_LOCK} is held — a deploy (or another reset) is running. Wait for it (GitHub Actions → CI & Deploy) and retry. Nothing was changed." >&2
        exit 1
    fi
fi

# ---- 2. Preflight + dry run ------------------------------------------------------------
if [ -z "$(dc ps -q --status running app 2>/dev/null || true)" ]; then
    echo "ERROR: the app container is not running — nothing was changed." >&2
    exit 1
fi

env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"'"'"' \r' || true; }
APP_URL_VALUE="$(env_value APP_URL)"
HOST="${APP_URL_VALUE#*://}"; HOST="${HOST%%/*}"; HOST="${HOST%%:*}"
[ -n "$HOST" ] || { echo "ERROR: APP_URL is not set in ${ENV_FILE}." >&2; exit 1; }

log "Dry run on ${HOST}:"
if ! artisan petprep:reset-game-data; then
    echo "ERROR: the dry run refused (see above) — nothing was changed." >&2
    exit 1
fi

if [ "$EXECUTE" != "1" ]; then
    log "Dry run only — nothing was changed. To reset: bash scripts/reset-game-data.sh --execute"
    exit 0
fi

# ---- 3. Confirmation (before anything changes) ----------------------------------------
if [ -z "$CONFIRM_HOST" ]; then
    if [ ! -t 0 ]; then
        echo "ERROR: no terminal — pass --confirm-host=${HOST} to confirm. Nothing was changed." >&2
        exit 1
    fi
    echo
    echo "This DELETES all families, parents, children, pets, purchases and pet media on ${HOST}."
    echo "Superadmins and breed data stay. A backup is taken first."
    read -r -p "Type the host (${HOST}) to continue: " CONFIRM_HOST
fi
if [ "$CONFIRM_HOST" != "$HOST" ]; then
    echo "ERROR: confirmation did not match — nothing was changed." >&2
    exit 1
fi

# ---- 4. Free disk for the backups ------------------------------------------------------
mkdir -p "$PRE_RESET_DIR"
DB_USER="$(env_value DB_USERNAME)"; DB_NAME="$(env_value DB_DATABASE)"
MEDIA_BYTES="$(dc exec -T app du -sb "$MEDIA_DIR" < /dev/null 2>/dev/null | awk 'NR==1 {print $1}' || true)"
DB_BYTES="$(dc exec -T postgres psql -U "${DB_USER:-petprep_user}" -d "${DB_NAME:-petprep_production}" -Atc \
    'select pg_database_size(current_database())' < /dev/null 2>/dev/null | tr -d ' \r' || true)"
AVAIL_BYTES="$(df -PB1 "$PRE_RESET_DIR" | awk 'NR==2 {print $4}')"
if ! is_number "$MEDIA_BYTES" || ! is_number "$DB_BYTES" || ! is_number "$AVAIL_BYTES"; then
    echo "ERROR: could not measure media / DB size / free disk (media='${MEDIA_BYTES}' db='${DB_BYTES}' free='${AVAIL_BYTES}') — nothing was changed." >&2
    exit 1
fi
# Uncompressed sizes are an upper bound for the gzip archives.
NEED_BYTES=$(( (MEDIA_BYTES + DB_BYTES) * 12 / 10 + 100 * 1024 * 1024 ))
log "Disk: need ≈ $((NEED_BYTES / 1048576)) MB for the backups (DB $((DB_BYTES / 1048576)) MB + media $((MEDIA_BYTES / 1048576)) MB), free $((AVAIL_BYTES / 1048576)) MB."
if [ "$AVAIL_BYTES" -lt "$NEED_BYTES" ]; then
    echo "ERROR: not enough free disk in ${BACKUP_DIR} for the backups — free space first. Nothing was changed." >&2
    exit 1
fi

# ---- 5.–6. Maintenance + workers stopped; the EXIT trap always brings them back --------
MAINTENANCE_ON=0
WORKERS_STOPPED=0
PHASE="maintenance"   # maintenance | backup | reset | post_commit | done
SAFE_DB=""
SAFE_MEDIA=""
on_exit() {
    local rc=$1
    trap - EXIT
    set +e
    if [ "$WORKERS_STOPPED" = "1" ]; then
        log "Starting the workers again..."
        dc up -d "${WORKERS[@]}" || warn "could not start ${WORKERS[*]} — run: docker compose -f ${COMPOSE_FILE} up -d ${WORKERS[*]}"
    fi
    if [ "$MAINTENANCE_ON" = "1" ]; then
        log "Leaving maintenance mode..."
        artisan up || loud "ERROR: still in maintenance mode — run:" \
            "  docker compose -f ${COMPOSE_FILE} exec -T app php artisan up"
    fi
    if [ "$rc" -ne 0 ]; then
        case "$PHASE" in
            post_commit)
                loud "DB RESET IS COMMITTED — game data is deleted — but the media / queue / cache cleanup failed (see above)." \
                     "Rerun to finish (idempotent, deletes no more rows): bash ${REPO_DIR}/scripts/reset-game-data.sh --execute" \
                     "Pre-reset backups: ${SAFE_DB:-none}" "                   ${SAFE_MEDIA:-none}" ;;
            reset)
                loud "RESET FAILED BEFORE THE COMMIT (exit ${rc}) — the transaction was rolled back, NO data was deleted." \
                     "Backups: ${SAFE_DB:-none}" "         ${SAFE_MEDIA:-none}" ;;
            *)
                loud "RESET ABORTED during ${PHASE} (exit ${rc}) — no data was deleted." \
                     "Backups (if taken): ${SAFE_DB:-none}" "                    ${SAFE_MEDIA:-none}" ;;
        esac
    fi
    exit "$rc"
}
trap 'on_exit $?' EXIT

log "Entering maintenance mode..."
MAINTENANCE_ON=1   # before the call: a half-applied `down` is still undone by the trap
artisan down --retry=15

log "Stopping ${WORKERS[*]}..."
WORKERS_STOPPED=1
dc stop "${WORKERS[@]}"

# ---- 7. Backups (DB + media), inside maintenance = a consistent snapshot ----------------
PHASE="backup"
BACKUP_SCRIPT="${SCRIPTS_DIR}/backup-production-db.sh"
[ -x "$BACKUP_SCRIPT" ] || BACKUP_SCRIPT="${REPO_DIR}/scripts/backup-production-db.sh"
STAMP="$(date -u +%Y-%m-%d_%H%M%S)"
MARKER="${PRE_RESET_DIR}/.started-${STAMP}"
touch "$MARKER"
sleep 1  # the backup file must be strictly newer than the marker

log "Creating the database backup (${BACKUP_SCRIPT})..."
if ! bash "$BACKUP_SCRIPT"; then
    rm -f "$MARKER"
    echo "ERROR: database backup failed — no data was deleted." >&2
    exit 1
fi
DB_BACKUP="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'petprep_*.sql.gz' -newer "$MARKER" -print | sort | tail -n1)"
rm -f "$MARKER"
if [ -z "$DB_BACKUP" ] || [ ! -s "$DB_BACKUP" ]; then
    echo "ERROR: no fresh backup file found in ${BACKUP_DIR} — no data was deleted." >&2
    exit 1
fi
# The backup script deletes petprep_*.sql.gz after 7 days (also in subdirectories):
# keep the pre-reset copy under another name (hard link — no extra disk; copy as fallback).
SAFE_DB="${PRE_RESET_DIR}/pre-reset_db_${STAMP}.sql.gz"
ln "$DB_BACKUP" "$SAFE_DB" 2>/dev/null || cp "$DB_BACKUP" "$SAFE_DB"
chmod 600 "$SAFE_DB"
gzip -t "$SAFE_DB" || { echo "ERROR: ${SAFE_DB} is not a valid gzip — no data was deleted." >&2; exit 1; }
log "DB backup: ${SAFE_DB} ($(du -h "$SAFE_DB" | awk '{print $1}'))"

SAFE_MEDIA="${PRE_RESET_DIR}/pre-reset_media_${STAMP}.tar.gz"
log "Archiving pet media files..."
if ! dc exec -T app tar -czf - -C /var/www/html/storage/app pet-media > "$SAFE_MEDIA" < /dev/null \
    || ! gzip -t "$SAFE_MEDIA"; then
    rm -f "$SAFE_MEDIA"
    SAFE_MEDIA=""
    echo "ERROR: media archive failed — no data was deleted." >&2
    exit 1
fi
log "Media backup: ${SAFE_MEDIA} ($(du -h "$SAFE_MEDIA" | awk '{print $1}'))"

# ---- 8. Reset ---------------------------------------------------------------------------
PHASE="reset"
log "Resetting game data..."
set +e
artisan petprep:reset-game-data --execute "$LONG_FLAG" "--backup-done=${SAFE_DB}" --no-interaction
RESET_RC=$?
set -e
if [ "$RESET_RC" -eq 2 ]; then
    PHASE="post_commit"
    exit 2
fi
[ "$RESET_RC" -eq 0 ] || exit "$RESET_RC"
PHASE="done"

echo
echo "=================================================="
echo " GAME DATA RESET DONE on ${HOST}"
echo " DB backup:    ${SAFE_DB}"
echo " Media backup: ${SAFE_MEDIA}"
echo " Next: Filament → Users — change the password of admin@petprep.io (seeded,"
echo "       password in the repo) or remove its superadmin flag (M0-15)."
echo " Restore (only if needed): PRODUCTION_DEPLOYMENT.md §6a — in short, app down +"
echo " workers stopped, then:"
echo "   RESTORE_STRICT=1 bash ${REPO_DIR}/scripts/restore-production-db.sh ${SAFE_DB}"
echo "   docker compose -p ${COMPOSE_PROJECT} -f ${COMPOSE_FILE} exec -T --user ${APP_UID}:${APP_UID} app \\"
echo "     tar -xzf - -C /var/www/html/storage/app < ${SAFE_MEDIA}"
echo "=================================================="
