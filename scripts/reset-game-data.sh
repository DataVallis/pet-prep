#!/usr/bin/env bash
# PetPrep — one-off production reset of all game data (DEPLOYMENT.md D17,
# David 2026-10-08 13:50: start the ~20-tester beta from zero).
#
# Usage (on the server, as deploy, from /opt/petprep/repo):
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
# --execute, in this order (nothing is changed before step 3):
#   1. Env preflight + dry run (prints the counts).
#   2. Typed confirmation of the APP_URL host (or --confirm-host=<host>).
#   3. artisan down (as uid 1000, like deploy-production.sh) — HTTP 503.
#   4. Stop the scheduler + queue workers (no tick / job writes from here on).
#   5. Fresh DB backup with the existing backup-production-db.sh, copied to
#      backups/pre-reset/ (outside the 7-day retention pattern), and a tar of
#      storage/app/pet-media from the app container next to it. Abort on failure
#      (no data deleted; the trap brings the app back).
#   6. php artisan petprep:reset-game-data --execute --i-understand-… --backup-done=<file>
#      (one DB transaction, then media files, queues, cache).
#   7. Always (EXIT trap): start the workers again and artisan up.
#
# Restore: see the end of the output (restore-production-db.sh + untar the media).
# Test harness (stubbed docker): scripts/tests/reset-game-data.test.sh
# Paths can be relocated for tests with PETPREP_ROOT (default /opt/petprep).

set -Eeuo pipefail

PETPREP_ROOT="${PETPREP_ROOT:-/opt/petprep}"
REPO_DIR="${PETPREP_ROOT}/repo"
ENV_FILE="${PETPREP_ROOT}/.env"
SCRIPTS_DIR="${PETPREP_ROOT}/scripts"
BACKUP_DIR="${PETPREP_ROOT}/backups"
PRE_RESET_DIR="${BACKUP_DIR}/pre-reset"
COMPOSE_FILE="${REPO_DIR}/backend/compose.production.yaml"
COMPOSE_PROJECT="${DEPLOY_COMPOSE_PROJECT:-backend}"
APP_UID="${DEPLOY_APP_UID:-1000}"
WORKERS=(queue queue-broadcasts scheduler)
LONG_FLAG="--i-understand-this-deletes-all-game-data"

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

# ---- 1. Preflight + dry run ----------------------------------------------------------
[ -f "$ENV_FILE" ] || { echo "ERROR: ${ENV_FILE} is missing." >&2; exit 1; }
[ -f "$COMPOSE_FILE" ] || { echo "ERROR: ${COMPOSE_FILE} is missing." >&2; exit 1; }
if [ -z "$(dc ps -q --status running app 2>/dev/null || true)" ]; then
    echo "ERROR: the app container is not running — nothing was changed." >&2
    exit 1
fi

env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"'"'"' \r' || true; }
APP_URL_VALUE="$(env_value APP_URL)"
HOST="${APP_URL_VALUE#*://}"; HOST="${HOST%%/*}"; HOST="${HOST%%:*}"
[ -n "$HOST" ] || { echo "ERROR: APP_URL is not set in ${ENV_FILE}." >&2; exit 1; }

log "Dry run on ${HOST}:"
artisan petprep:reset-game-data

if [ "$EXECUTE" != "1" ]; then
    log "Dry run only — nothing was changed. To reset: bash scripts/reset-game-data.sh --execute"
    exit 0
fi

# ---- 2. Confirmation (before anything changes) ----------------------------------------
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

# ---- 3.–4. Maintenance + workers stopped; the EXIT trap always brings them back ----------
MAINTENANCE_ON=0
WORKERS_STOPPED=0
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
        loud "RESET FAILED (exit ${rc}). The DB step is one transaction: if it failed, nothing was deleted." \
             "Backups (if taken): ${SAFE_DB:-none}" "                    ${SAFE_MEDIA:-none}"
    fi
    exit "$rc"
}
trap 'on_exit $?' EXIT

log "Entering maintenance mode..."
artisan down --retry=15
MAINTENANCE_ON=1

log "Stopping ${WORKERS[*]}..."
WORKERS_STOPPED=1
dc stop "${WORKERS[@]}"

# ---- 5. Backups (DB + media), inside maintenance = a consistent snapshot ----------------
BACKUP_SCRIPT="${SCRIPTS_DIR}/backup-production-db.sh"
[ -x "$BACKUP_SCRIPT" ] || BACKUP_SCRIPT="${REPO_DIR}/scripts/backup-production-db.sh"
mkdir -p "$PRE_RESET_DIR"
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
# keep the pre-reset copy under another name.
SAFE_DB="${PRE_RESET_DIR}/pre-reset_db_${STAMP}.sql.gz"
cp "$DB_BACKUP" "$SAFE_DB"
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

# ---- 6. Reset ---------------------------------------------------------------------------
log "Resetting game data..."
artisan petprep:reset-game-data --execute "$LONG_FLAG" "--backup-done=${SAFE_DB}" --no-interaction

echo
echo "=================================================="
echo " GAME DATA RESET DONE on ${HOST}"
echo " DB backup:    ${SAFE_DB}"
echo " Media backup: ${SAFE_MEDIA}"
echo " Restore (only if needed):"
echo "   bash ${REPO_DIR}/scripts/restore-production-db.sh ${SAFE_DB}"
echo "   docker compose -p ${COMPOSE_PROJECT} -f ${COMPOSE_FILE} exec -T --user ${APP_UID}:${APP_UID} app \\"
echo "     tar -xzf - -C /var/www/html/storage/app < ${SAFE_MEDIA}"
echo "=================================================="
