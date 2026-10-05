#!/usr/bin/env bash
# PetPrep production deployment script.
#
# Usage:
#   deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>   # CI (GitHub Actions)
#   deploy-production.sh <COMMIT_SHA>                                # manual, git checkout in repo/
#   deploy-production.sh                                             # manual, re-deploy tree in place
#
# WHY THE ORDER MATTERS
#   Every PHP container (app, reverb, queue, queue-broadcasts, scheduler) bind-mounts
#   the code (`backend/:/var/www/html`). Changing the files in /opt/petprep/repo
#   therefore switches the RUNNING containers to the new code immediately — long
#   before migrations run. So the code is switched only while the app is already in
#   maintenance mode, and CI uploads the new tree to a staging directory
#   (/opt/petprep/incoming) instead of rsyncing straight into repo/.
#
# STEPS
#   1. Env preflight (QUEUE_CONNECTION=redis, BROADCAST_CONNECTION=reverb, REDIS_QUEUE
#      unset|default). Runs before ANY git/docker command; prints only these keys.
#   2. Record the previous release: PREV_SHA (repo/.deployed-sha, or git HEAD in git
#      mode) and, for --from deploys, a copy of the current tree in
#      /opt/petprep/releases/previous.
#   3. `artisan down --retry=15` on the OLD code (exec in the running app container,
#      fallback `run --rm`; if both fail — first deploy on a fresh server — warn and
#      continue). The down file lives on the shared app_storage volume, so HTTP → 503,
#      the scheduler skips its ticks and queue workers pause.
#   4. Pre-migration DB backup (aborts the deploy if Postgres runs but the backup
#      fails; DEPLOY_ALLOW_NO_BACKUP=1 overrides).
#   5. Switch the code (rsync from the --from dir, or `git checkout -f SHA`), build,
#      start postgres/redis, migrate, seed breed configs, config/route/view caches,
#      recreate app containers, queue:restart, `artisan up` (3 attempts, exec then
#      run --rm fallback), health check.
#
# FAILURE HANDLING (EXIT trap)
#   * Failure BEFORE migrations succeeded (backup, code switch, build, DB start,
#     migrate): the schema is still the old one, so the code is reverted to the
#     previous release (rsync back from releases/previous, or `git checkout -f
#     PREV_SHA`), caches are rebuilt for the old code if they had been rebuilt,
#     long-running containers are restarted (so no new class stays in memory), then
#     `artisan up`. Old code + old schema = consistent. Caveat: Laravel runs each
#     migration in its own transaction, so a failure in migration N leaves 1..N-1
#     applied; reverting assumes those are additive/backward compatible (project
#     rule). If not, restore the pre-deploy backup (PRODUCTION_DEPLOYMENT.md §7).
#     If the revert itself fails, the app is LEFT in maintenance (new code on the old
#     schema is the dangerous case).
#   * Failure AFTER migrations succeeded (seed, caches, container start, queue
#     restart, health check): the schema is new and the old code may not work on it,
#     so the code is NOT reverted. Stale caches are cleared if the cache step did not
#     finish, containers are (re)started on the new code, the app leaves maintenance
#     (new code + new schema = consistent) and a loud error is printed.
#   * `artisan up` failing 3x leaves the app in maintenance (scheduler + queues
#     paused): loud error with the manual command.
#   The original non-zero exit code is always preserved.
#
# Idempotent: re-running with the same SHA re-applies the same tree, migrations are
# no-ops, `artisan down/up` tolerate being repeated. No env values except the three
# preflight keys are printed.
#
# Test harness (stubbed docker/git/curl, no server): scripts/tests/deploy-production.test.sh
# Paths can be relocated for tests with PETPREP_ROOT (default /opt/petprep).

set -Eeuo pipefail

PETPREP_ROOT="${PETPREP_ROOT:-/opt/petprep}"
REPO_DIR="${PETPREP_ROOT}/repo"
ENV_FILE="${PETPREP_ROOT}/.env"
SCRIPTS_DIR="${PETPREP_ROOT}/scripts"
PREV_TREE="${PETPREP_ROOT}/releases/previous"
COMPOSE_FILE="${REPO_DIR}/backend/compose.production.yaml"
SHA_FILE="${REPO_DIR}/.deployed-sha"
RETRY_SLEEP="${DEPLOY_RETRY_SLEEP:-3}"
APP_SERVICES=(app reverb queue queue-broadcasts scheduler caddy)
WORKER_SERVICES=(reverb queue queue-broadcasts scheduler)
# Paths a code sync never touches (also protected from --delete).
RSYNC_EXCLUDES=(
    --exclude=/.git --exclude=/.idea --exclude=/.deployed-sha
    --exclude=/backend/.env --exclude=/backend/storage --exclude=/backend/vendor
    --exclude=/mobile/node_modules
)

usage() { sed -n '4,7p' "$0" | sed 's/^# \{0,1\}//'; }

SOURCE_DIR=""
while [ $# -gt 0 ]; do
    case "$1" in
        --from)
            SOURCE_DIR="${2:-}"
            [ -n "$SOURCE_DIR" ] || { echo "ERROR: --from needs a directory" >&2; exit 2; }
            shift 2 ;;
        --from=*) SOURCE_DIR="${1#--from=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        --) shift; break ;;
        -*) echo "ERROR: unknown option $1" >&2; usage >&2; exit 2 ;;
        *) break ;;
    esac
done
COMMIT_SHA="${1:-}"

log()  { echo "[deploy] $*"; }
warn() { echo "[deploy] WARNING: $*" >&2; }
loud() {
    local line
    echo "##################################################################" >&2
    for line in "$@"; do echo "## $line" >&2; done
    echo "##################################################################" >&2
}
dc() { docker compose -f "$COMPOSE_FILE" "$@"; }

# ---- state read by the EXIT trap -------------------------------------------------
SWITCH_MODE="none"      # dir | git | none
PREV_SHA=""
DEPLOYED_SHA=""
MAINTENANCE_ON=0        # 1 while the app is known to be in maintenance mode
LEAVE_FAILED=0          # artisan up already failed 3x (the trap doesn't retry again)
CODE_SWITCHED=0         # 1 as soon as repo/ may differ from the previous release
MIGRATED=0              # 1 after `migrate --force` succeeded
CACHES_DONE=0           # 1 after config/route/view caches were rebuilt
CONTAINERS_RECREATED=0  # 1 once `up -d` of the app services was attempted
FAILED_AT=""

trap 'FAILED_AT="line ${LINENO}: ${BASH_COMMAND}"' ERR
trap 'on_exit $?' EXIT

# artisan <args…>: run in the running app container, else in a one-off container.
artisan() {
    dc exec -T app php artisan "$@" || dc run --rm app php artisan "$@"
}

enter_maintenance() {
    log "Entering maintenance mode (old code still active)..."
    if artisan down --retry=15; then
        MAINTENANCE_ON=1
    else
        warn "could not enter maintenance mode (first deploy on a fresh server?) — continuing without it."
    fi
}

# The flag is reset only after a successful `artisan up`.
leave_maintenance() {
    [ "$MAINTENANCE_ON" = "1" ] || return 0
    [ "$LEAVE_FAILED" = "0" ] || return 1
    local attempt
    for attempt in 1 2 3; do
        log "Leaving maintenance mode (attempt ${attempt}/3)..."
        if artisan up; then
            MAINTENANCE_ON=0
            return 0
        fi
        warn "artisan up failed (attempt ${attempt}/3)."
        if [ "$attempt" -lt 3 ]; then sleep "$RETRY_SLEEP"; fi
    done
    LEAVE_FAILED=1
    loud "ERROR: PRODUCTION IS STILL IN MAINTENANCE MODE (HTTP 503, scheduler + queues paused)." \
         "Bring it up manually on the server:" \
         "  docker compose -f ${COMPOSE_FILE} exec -T app php artisan up" \
         "  (or: docker compose -f ${COMPOSE_FILE} run --rm app php artisan up)"
    return 1
}

build_caches() {
    dc run --rm app php artisan config:cache
    dc run --rm app php artisan route:cache
    dc run --rm app php artisan view:cache
}

# --checksum: compare content, not size+mtime (same-size edits in the same second
# would otherwise be skipped); unchanged files are not rewritten.
sync_tree() { rsync -a --checksum --delete "${RSYNC_EXCLUDES[@]}" "$1/" "$2/"; }

revert_code() {
    case "$SWITCH_MODE" in
        dir) sync_tree "$PREV_TREE" "$REPO_DIR" || return 1 ;;
        git) git -C "$REPO_DIR" checkout -f "$PREV_SHA" || return 1 ;;
        *) return 0 ;;
    esac
    if [ -n "$PREV_SHA" ]; then echo "$PREV_SHA" > "$SHA_FILE"; else rm -f "$SHA_FILE"; fi
}

on_exit() {
    local rc=$1 c
    trap - EXIT ERR
    if [ "$rc" -eq 0 ]; then return 0; fi
    set +e  # the cleanup must run to the end
    loud "DEPLOY FAILED (exit ${rc}) at ${FAILED_AT:-unknown step}"

    if [ "$MIGRATED" = "0" ]; then
        # Schema is still the old one → put the old code back.
        if [ "$CODE_SWITCHED" = "1" ]; then
            log "Failure before migrations completed: reverting code to ${PREV_SHA:-previous release} (${SWITCH_MODE})..."
            if ! revert_code; then
                loud "ERROR: CODE REVERT FAILED — repo/ may hold the NEW code on the OLD schema." \
                     "The app stays in maintenance mode. Fix manually (PRODUCTION_DEPLOYMENT.md §7), then artisan up."
                exit "$rc"
            fi
            if [ "$CACHES_DONE" = "1" ]; then
                log "Rebuilding caches for the old code..."
                build_caches || warn "cache rebuild for the old code failed."
            fi
            if [ "$CONTAINERS_RECREATED" = "1" ]; then
                dc up -d --remove-orphans "${APP_SERVICES[@]}" || warn "could not restart app services."
            fi
            # Long-running workers may have lazily loaded new classes while the new
            # tree was on disk: restart them on the old code.
            dc restart "${WORKER_SERVICES[@]}" || warn "could not restart workers."
            log "Code reverted to ${PREV_SHA:-previous release}."
        fi
    else
        # Schema is new → the old code may break on it; keep the new code (consistent).
        loud "Failure AFTER migrations succeeded: code is NOT reverted (schema is new)." \
             "Production runs ${DEPLOYED_SHA:-the new code} on the new schema. Investigate and redeploy."
        if [ "$CACHES_DONE" = "0" ]; then
            # Caches built by the old code would otherwise be served with the new code.
            for c in config:clear route:clear view:clear event:clear; do
                dc run --rm app php artisan "$c" || warn "artisan ${c} failed."
            done
        fi
        dc up -d --remove-orphans "${APP_SERVICES[@]}" || warn "could not start app services."
        dc restart "${WORKER_SERVICES[@]}" || warn "could not restart workers."
    fi

    leave_maintenance
    exit "$rc"
}

echo "=================================================="
echo " Starting PetPrep Production Deployment"
echo " Time: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo " Target Commit: ${COMMIT_SHA:-<tree in place>}"
echo "=================================================="

# ---- 1. Env preflight (no git/docker before this) --------------------------------
if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: Production environment file ${ENV_FILE} is missing." >&2
    exit 1
fi
env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"'"'"' \r' || true; }
# Realtime needs Redis queue + Reverb broadcaster (M1-09).
for pair in "QUEUE_CONNECTION=redis" "BROADCAST_CONNECTION=reverb"; do
    key="${pair%%=*}"; want="${pair#*=}"
    have=$(env_value "$key")
    if [ "$have" != "$want" ]; then
        echo "ERROR: ${key} must be '${want}' in ${ENV_FILE} (found '${have:-<unset>}')." >&2
        exit 1
    fi
done
# Jobs go to REDIS_QUEUE (default "default"); the `queue` worker listens only to
# `default`, so any other value would strand fal.ai jobs.
rq=$(env_value REDIS_QUEUE)
if [ -n "$rq" ] && [ "$rq" != "default" ]; then
    echo "ERROR: REDIS_QUEUE must be unset or 'default' in ${ENV_FILE} (found '${rq}')." >&2
    exit 1
fi
# AI models (M4, David 2026-10-05): the defaults are nano_banana_pro / kling_v3_pro.
# An old .env copied from .env.example may still pin flux_schnell / kling_v16_legacy
# (removed — the app falls back to the default and logs an error). Warn, don't block.
for pair in "AI_REFERENCE_IMAGE_PROFILE=nano_banana_pro" "AI_STATE_VIDEO_PROFILE=kling_v3_pro"; do
    key="${pair%%=*}"; want="${pair#*=}"
    have=$(env_value "$key")
    if [ -n "$have" ] && [ "$have" != "$want" ]; then
        echo "WARNING: ${key}='${have}' in ${ENV_FILE} overrides the production default '${want}' — remove the line unless intended." >&2
    fi
done
log "Env preflight OK."

# ---- 2. Record the previous release ----------------------------------------------
cd "$REPO_DIR"
if [ -f "$SHA_FILE" ]; then PREV_SHA=$(tr -d ' \n\r' < "$SHA_FILE"); fi

if [ -n "$SOURCE_DIR" ]; then
    [ -f "${SOURCE_DIR}/backend/compose.production.yaml" ] \
        || { echo "ERROR: ${SOURCE_DIR} does not look like a PetPrep tree." >&2; exit 1; }
    SWITCH_MODE="dir"
    log "Snapshotting current release (${PREV_SHA:-unknown}) to ${PREV_TREE}..."
    mkdir -p "$PREV_TREE"
    sync_tree "$REPO_DIR" "$PREV_TREE"
elif [ -n "$COMMIT_SHA" ] && [ -d "${REPO_DIR}/.git" ]; then
    SWITCH_MODE="git"
    PREV_SHA=$(git -C "$REPO_DIR" rev-parse HEAD)
    git -C "$REPO_DIR" fetch origin --tags --prune || warn "git fetch failed — using local objects."
    git -C "$REPO_DIR" cat-file -e "${COMMIT_SHA}^{commit}" \
        || { echo "ERROR: commit ${COMMIT_SHA} not found in ${REPO_DIR}." >&2; exit 1; }
else
    warn "no --from dir and no git checkout: re-deploying the tree in place (automatic code revert unavailable)."
fi
log "Previous release: ${PREV_SHA:-unknown}"

# ---- 3. Maintenance mode BEFORE touching the code --------------------------------
enter_maintenance

# ---- 4. Pre-migration database backup --------------------------------------------
if [ -x "${SCRIPTS_DIR}/backup-production-db.sh" ] \
    && [ -n "$(dc ps -q --status running postgres 2>/dev/null || true)" ]; then
    log "Creating pre-deployment database backup..."
    if ! "${SCRIPTS_DIR}/backup-production-db.sh"; then
        if [ "${DEPLOY_ALLOW_NO_BACKUP:-0}" = "1" ]; then
            warn "backup failed — continuing because DEPLOY_ALLOW_NO_BACKUP=1."
        else
            echo "ERROR: pre-deployment backup failed (set DEPLOY_ALLOW_NO_BACKUP=1 to override)." >&2
            exit 1
        fi
    fi
else
    warn "backup skipped (postgres not running or backup script missing — first deploy?)."
fi

# ---- 5. Switch the code ------------------------------------------------------------
CODE_SWITCHED=1   # set before the attempt: a partial switch must be reverted too
case "$SWITCH_MODE" in
    dir)
        log "Syncing ${SOURCE_DIR} -> ${REPO_DIR}..."
        sync_tree "$SOURCE_DIR" "$REPO_DIR"
        DEPLOYED_SHA="${COMMIT_SHA:-unknown}"
        ;;
    git)
        log "Checking out ${COMMIT_SHA}..."
        git -C "$REPO_DIR" checkout -f "$COMMIT_SHA"
        DEPLOYED_SHA=$(git -C "$REPO_DIR" rev-parse HEAD)
        ;;
    none)
        CODE_SWITCHED=0
        DEPLOYED_SHA="${COMMIT_SHA:-${PREV_SHA:-manual}}"
        ;;
esac
echo "$DEPLOYED_SHA" > "$SHA_FILE"
log "Deploying commit: ${DEPLOYED_SHA}"

rm -f "${REPO_DIR}/backend/.env"
cp "$ENV_FILE" "${REPO_DIR}/backend/.env"
chmod 600 "${REPO_DIR}/backend/.env"
log "Installed production .env into ${REPO_DIR}/backend/.env"

# Install ops scripts (copy + rename: never overwrite a running script in place).
mkdir -p "$SCRIPTS_DIR"
for s in backup-production-db.sh restore-production-db.sh deploy-production.sh; do
    if [ -f "${REPO_DIR}/scripts/${s}" ]; then
        cp "${REPO_DIR}/scripts/${s}" "${SCRIPTS_DIR}/.${s}.tmp"
        chmod +x "${SCRIPTS_DIR}/.${s}.tmp"
        mv -f "${SCRIPTS_DIR}/.${s}.tmp" "${SCRIPTS_DIR}/${s}"
    fi
done

# ---- 6. Build, database, migrations ----------------------------------------------
log "Building production Docker image..."
dc build app

log "Ensuring database and cache services are running..."
dc up -d postgres redis
for i in $(seq 1 30); do
    if dc exec -T postgres pg_isready -q; then break; fi
    if [ "$i" -eq 30 ]; then echo "ERROR: PostgreSQL not ready after 30 s." >&2; exit 1; fi
    sleep 1
done

log "Running database migrations (--force)..."
dc run --rm app php artisan migrate --force
MIGRATED=1

# Breed configs (idempotent seeder).
dc run --rm app php artisan db:seed --class='Database\Seeders\BreedConfigsSeeder' --force \
    || warn "BreedConfigsSeeder failed."

# ---- 7. Caches, containers, workers ----------------------------------------------
log "Optimizing Laravel config, routes and views..."
build_caches
CACHES_DONE=1

log "Starting all application services..."
CONTAINERS_RECREATED=1
dc up -d --remove-orphans "${APP_SERVICES[@]}"

# Signal shared via cache: restarts queue + queue-broadcasts on the new code.
log "Restarting queue workers..."
dc exec -T queue php artisan queue:restart || warn "queue:restart failed."

# ---- 8. Leave maintenance, health check ------------------------------------------
leave_maintenance || exit 1

log "Verifying service health..."
sleep 4
dc ps

HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1/up || echo "000")
if [ "$HTTP_STATUS" != "200" ]; then
    HTTP_STATUS=$(dc exec -T app curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/up || echo "000")
fi
log "Health check /up HTTP status: ${HTTP_STATUS}"
if [ "$HTTP_STATUS" != "200" ]; then
    echo "ERROR: Health check failed with status ${HTTP_STATUS}" >&2
    exit 1
fi

echo "=================================================="
echo " DEPLOYMENT SUCCESSFUL!"
echo " Deployed SHA: ${DEPLOYED_SHA} (previous: ${PREV_SHA:-unknown})"
echo " Time: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo "=================================================="
