#!/usr/bin/env bash
# PetPrep production deployment script.
#
# Usage:
#   deploy-production.sh --from /opt/petprep/incoming <COMMIT_SHA>   # CI (GitHub Actions)
#   deploy-production.sh <COMMIT_SHA>                                # manual, git checkout in repo/
#   deploy-production.sh                                             # manual, re-deploy tree in place
#
# RUNTIME (M4-05b, DEPLOYMENT.md D14)
#   The PHP containers (app = php-fpm, reverb, queue, queue-broadcasts, scheduler)
#   run the production image `petprep-app` (backend/docker/production/Dockerfile):
#   code + vendor (composer install --no-dev) are BAKED INTO THE IMAGE, nothing is
#   bind-mounted except /opt/petprep/.env and the app_storage volume. Caddy runs
#   `petprep-web` (caddy + public/). An image therefore is a release:
#     petprep-{app,web}:next        built by this deploy (before maintenance mode)
#     petprep-{app,web}:production  what compose runs (promoted from :next)
#     petprep-{app,web}:previous    the release before (automatic revert)
#   The old Sail image `petprep-backend:production` is never touched (rollback to
#   the pre-M4-05b compose file keeps working, PRODUCTION_DEPLOYMENT.md §7).
#   repo/ still matters: it holds the compose file, the Caddyfile (bind-mounted
#   into caddy) and the ops scripts, so it is switched only inside maintenance.
#
# STEPS
#   1. Env preflight (QUEUE_CONNECTION=redis, BROADCAST_CONNECTION=reverb, REDIS_QUEUE
#      unset|default). Runs before ANY git/docker command; prints only these keys.
#   2. Record the previous release: PREV_SHA (repo/.deployed-sha, or git HEAD in git
#      mode) and, for --from deploys, a copy of the current tree in
#      /opt/petprep/releases/previous.
#   3. Build the new images as :next from the NEW tree (--from dir, `git archive` of
#      the commit, or repo/ in place) and smoke-test them: `php artisan optimize`
#      (config / events / routes / views / Filament) in a one-off container with
#      the production env. Still BEFORE maintenance mode: a failing build or smoke
#      test aborts the deploy while the old release keeps serving (nothing changed).
#   4. `artisan down --retry=15` on the OLD code (exec in the running app container,
#      fallback `run --rm`; if both fail — first deploy on a fresh server — warn and
#      continue). The down file lives on the shared app_storage volume, so HTTP → 503,
#      the scheduler skips its ticks and queue workers pause.
#   5. Pre-migration DB backup (aborts the deploy if Postgres runs but the backup
#      fails; DEPLOY_ALLOW_NO_BACKUP=1 overrides).
#   6. Switch the code (rsync from the --from dir, or `git checkout -f SHA`), promote
#      the images (:production → :previous, :next → :production). If the Caddyfile
#      differs from what the RUNNING caddy container sees (see CADDY below), validate
#      the new one with the new caddy image right away (still pre-migration → a bad
#      Caddyfile reverts code + images and the old Caddy keeps serving). Then start
#      postgres/redis, migrate, seed breed configs, recreate the app containers (each
#      builds its own caches on start, docker/production/entrypoint.sh), wait until
#      PHP-FPM answers, queue:restart, `artisan up` (3 attempts, exec then run --rm
#      fallback), end-to-end health check through Caddy (`/up`, 5 attempts).
#
# CADDY
#   caddy bind-mounts the single file deployment/Caddyfile. A sync/checkout replaces a
#   changed file with a new inode, so the running container keeps reading the OLD
#   file and `caddy reload` would not see the change either. The script therefore
#   snapshots the Caddyfile as the running container sees it (`exec caddy cat`; caddy
#   not running → treated as changed), compares it with the new file after the code
#   switch, validates a changed file in a one-off container (`run --rm --no-deps caddy
#   caddy validate`, same env_file), and recreates caddy (`up -d --force-recreate
#   --no-deps caddy`) together with the other containers after the migrations.
#   Comparing with the running container (not with releases/previous) also catches
#   a container that was already stale before this deploy. (A new petprep-web image
#   recreates caddy on `up -d` anyway.)
#
# FAILURE HANDLING (EXIT trap)
#   * Failure while building / smoke-testing (step 3): nothing was changed — no
#     maintenance, no code switch, :production untouched. Exit ≠ 0.
#   * Failure BEFORE migrations succeeded (backup, code switch, Caddyfile, DB start,
#     migrate): the schema is still the old one, so the code is reverted to the
#     previous release (rsync back from releases/previous, or `git checkout -f
#     PREV_SHA`), the images are put back (:previous → :production; on the first
#     M4-05b deploy there is no :previous and the old compose file runs the Sail
#     image anyway), containers are recreated if they had been, workers restarted,
#     then `artisan up`. Old code + old schema = consistent. Caveat: Laravel runs each
#     migration in its own transaction, so a failure in migration N leaves 1..N-1
#     applied; reverting assumes those are additive/backward compatible (project
#     rule). If not, restore the pre-deploy backup (PRODUCTION_DEPLOYMENT.md §7).
#     If the revert itself fails, the app is LEFT in maintenance (new code on the old
#     schema is the dangerous case).
#   * Failure AFTER migrations succeeded (container start, PHP-FPM not healthy, queue
#     restart, health check): the schema is new and the old code may not work on it,
#     so the code is NOT reverted. Containers are (re)started on the new release, the
#     app leaves maintenance (new code + new schema = consistent) and a loud error is
#     printed.
#   * `artisan up` failing 3x leaves the app in maintenance (scheduler + queues
#     paused): loud error with the manual command.
#   The original non-zero exit code is always preserved.
#
# Idempotent: re-running with the same SHA re-applies the same tree (the image build
# is a cache hit), migrations are no-ops, `artisan down/up` tolerate being repeated.
# No env values except the three preflight keys are printed.
#
# Test harness (stubbed docker/git/curl, no server): scripts/tests/deploy-production.test.sh
# Paths can be relocated for tests with PETPREP_ROOT (default /opt/petprep).

set -Eeuo pipefail

PETPREP_ROOT="${PETPREP_ROOT:-/opt/petprep}"
REPO_DIR="${PETPREP_ROOT}/repo"
ENV_FILE="${PETPREP_ROOT}/.env"
SCRIPTS_DIR="${PETPREP_ROOT}/scripts"
PREV_TREE="${PETPREP_ROOT}/releases/previous"
BUILD_SRC="${PETPREP_ROOT}/releases/build-src"
COMPOSE_FILE="${REPO_DIR}/backend/compose.production.yaml"
SHA_FILE="${REPO_DIR}/.deployed-sha"
CADDYFILE="${REPO_DIR}/deployment/Caddyfile"
CADDY_RUNNING_COPY="${PETPREP_ROOT}/releases/Caddyfile.running"
RETRY_SLEEP="${DEPLOY_RETRY_SLEEP:-3}"
APP_WAIT_TRIES="${DEPLOY_APP_WAIT_TRIES:-40}"
HEALTH_HOST="${DEPLOY_HEALTH_HOST:-api.petprep.si}"
HEALTH_IP_HOST="${DEPLOY_HEALTH_IP_HOST:-138.199.172.97}"
APP_SERVICES=(app reverb queue queue-broadcasts scheduler caddy)
WORKER_SERVICES=(reverb queue queue-broadcasts scheduler)
IMAGES=(petprep-app petprep-web)
# Paths a code sync never touches (also protected from --delete). backend/vendor
# stays on disk only for a rollback to the Sail runtime (it bind-mounts the code).
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
BUILD_DIR=""            # tree the :next images are built from
MAINTENANCE_ON=0        # 1 while the app is known to be in maintenance mode
LEAVE_FAILED=0          # artisan up already failed 3x (the trap doesn't retry again)
CODE_SWITCHED=0         # 1 as soon as repo/ may differ from the previous release
IMAGES_PROMOTED=0       # 1 once :next was tagged :production
PREV_IMAGES=()          # images whose old :production was saved as :previous
MIGRATED=0              # 1 after `migrate --force` succeeded
CONTAINERS_RECREATED=0  # 1 once `up -d` of the app services was attempted
CADDY_CHANGED=0         # 1 if the new Caddyfile differs from the running container's
FAILED_AT=""

trap 'FAILED_AT="line ${LINENO}: ${BASH_COMMAND}"' ERR
trap 'on_exit $?' EXIT

# Compose against the NEW tree with the :next tag (build + smoke test). Same
# project name ("backend", the compose file's directory) → same network/volumes.
dcn() {
    PETPREP_IMAGE_TAG=next docker compose -f "${BUILD_DIR}/backend/compose.production.yaml" \
        --env-file "$ENV_FILE" "$@"
}

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

# :production → :previous (if it exists), :next → :production.
promote_images() {
    local img
    PREV_IMAGES=()
    for img in "${IMAGES[@]}"; do
        if docker image inspect "${img}:production" > /dev/null 2>&1; then
            docker tag "${img}:production" "${img}:previous"
            PREV_IMAGES+=("$img")
        else
            warn "no ${img}:production yet (first deploy of the M4-05b runtime) — nothing to keep as :previous."
        fi
    done
    IMAGES_PROMOTED=1
    for img in "${IMAGES[@]}"; do
        docker tag "${img}:next" "${img}:production"
    done
}

# Undo promote_images (pre-migration revert).
restore_images() {
    local img rc=0
    [ "$IMAGES_PROMOTED" = "1" ] || return 0
    for img in ${PREV_IMAGES[@]+"${PREV_IMAGES[@]}"}; do
        docker tag "${img}:previous" "${img}:production" || rc=1
    done
    return "$rc"
}

# PHP-FPM answers its ping (caches built by the entrypoint, pool up).
wait_for_app() {
    local i
    for i in $(seq 1 "$APP_WAIT_TRIES"); do
        if dc exec -T app php-fpm-ping; then
            log "PHP-FPM is up (check ${i})."
            return 0
        fi
        sleep 3
    done
    echo "ERROR: PHP-FPM in the app container did not become healthy (docker compose -f ${COMPOSE_FILE} logs app)." >&2
    return 1
}

# End-to-end through Caddy: real TLS name first, then the plain-HTTP IP site.
health_status() {
    local s
    s=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
        --resolve "${HEALTH_HOST}:443:127.0.0.1" "https://${HEALTH_HOST}/up" || true)
    if [ "$s" != "200" ]; then
        s=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
            -H "Host: ${HEALTH_IP_HOST}" "http://127.0.0.1/up" || true)
    fi
    echo "${s:-000}"
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
    local rc=$1
    trap - EXIT ERR
    if [ "$rc" -eq 0 ]; then return 0; fi
    set +e  # the cleanup must run to the end
    loud "DEPLOY FAILED (exit ${rc}) at ${FAILED_AT:-unknown step}"

    if [ "$MIGRATED" = "0" ]; then
        # Schema is still the old one → put the old release back (code + images).
        if [ "$CODE_SWITCHED" = "1" ] || [ "$IMAGES_PROMOTED" = "1" ]; then
            log "Failure before migrations completed: reverting to ${PREV_SHA:-previous release} (${SWITCH_MODE})..."
            if ! revert_code; then
                loud "ERROR: CODE REVERT FAILED — repo/ may hold the NEW code on the OLD schema." \
                     "The app stays in maintenance mode. Fix manually (PRODUCTION_DEPLOYMENT.md §7), then artisan up."
                exit "$rc"
            fi
            if ! restore_images; then
                loud "ERROR: COULD NOT RESTORE THE PREVIOUS IMAGES (petprep-app/web:previous → :production)." \
                     "The app stays in maintenance mode. Fix manually (PRODUCTION_DEPLOYMENT.md §7), then artisan up."
                exit "$rc"
            fi
            if [ "$CONTAINERS_RECREATED" = "1" ]; then
                dc up -d --remove-orphans "${APP_SERVICES[@]}" || warn "could not restart app services."
            fi
            # Containers of the Sail runtime bind-mount the code and may have loaded
            # new classes while the new tree was on disk: restart them on the old code.
            dc restart "${WORKER_SERVICES[@]}" || warn "could not restart workers."
            log "Code reverted to ${PREV_SHA:-previous release}."
        fi
    else
        # Schema is new → the old code may break on it; keep the new release (consistent).
        loud "Failure AFTER migrations succeeded: code is NOT reverted (schema is new)." \
             "Production runs ${DEPLOYED_SHA:-the new code} on the new schema. Investigate and redeploy" \
             "(rollback steps: PRODUCTION_DEPLOYMENT.md §7)."
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
# M4-05b: Caddy serves the pet media files; `php` (emergency fallback) streams them
# through PHP-FPM again — fine for a day, not for the beta.
for pair in "AI_REFERENCE_IMAGE_PROFILE=nano_banana_pro" "AI_STATE_VIDEO_PROFILE=kling_v3_pro" "PET_MEDIA_SERVE_VIA=caddy"; do
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
    BUILD_DIR="$SOURCE_DIR"
    log "Snapshotting current release (${PREV_SHA:-unknown}) to ${PREV_TREE}..."
    mkdir -p "$PREV_TREE"
    sync_tree "$REPO_DIR" "$PREV_TREE"
elif [ -n "$COMMIT_SHA" ] && [ -d "${REPO_DIR}/.git" ]; then
    SWITCH_MODE="git"
    PREV_SHA=$(git -C "$REPO_DIR" rev-parse HEAD)
    git -C "$REPO_DIR" fetch origin --tags --prune || warn "git fetch failed — using local objects."
    git -C "$REPO_DIR" cat-file -e "${COMMIT_SHA}^{commit}" \
        || { echo "ERROR: commit ${COMMIT_SHA} not found in ${REPO_DIR}." >&2; exit 1; }
    # Build context = the commit itself (repo/ is checked out only inside maintenance).
    rm -rf "$BUILD_SRC"; mkdir -p "$BUILD_SRC"
    git -C "$REPO_DIR" archive --format=tar "$COMMIT_SHA" | tar -x -C "$BUILD_SRC"
    BUILD_DIR="$BUILD_SRC"
else
    warn "no --from dir and no git checkout: re-deploying the tree in place (automatic code revert unavailable)."
    BUILD_DIR="$REPO_DIR"
fi
log "Previous release: ${PREV_SHA:-unknown}"

# ---- 3. Build + smoke-test the new images (old release keeps serving) -------------
log "Building production images petprep-app:next / petprep-web:next from ${BUILD_DIR}..."
if ! dcn build app caddy; then
    echo "ERROR: image build failed — nothing was changed, the current release keeps serving." >&2
    exit 1
fi
log "Smoke test: php artisan optimize in a one-off container of the new image..."
if ! dcn run --rm --no-deps -T app php artisan optimize; then
    echo "ERROR: the new image cannot build its caches with the production env — nothing was changed." >&2
    exit 1
fi

# Caddyfile as the running caddy container sees it (empty → caddy not running).
mkdir -p "$(dirname "$CADDY_RUNNING_COPY")"
if ! dc exec -T caddy cat /etc/caddy/Caddyfile > "$CADDY_RUNNING_COPY" 2>/dev/null; then
    rm -f "$CADDY_RUNNING_COPY"
    warn "could not read the running Caddyfile (caddy not running?) — treating it as changed."
fi

# ---- 4. Maintenance mode BEFORE touching the code --------------------------------
enter_maintenance

# ---- 5. Pre-migration database backup --------------------------------------------
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

# ---- 6. Switch the code + images ---------------------------------------------------
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

log "Promoting images: :next -> :production (old :production kept as :previous)..."
promote_images

# Caddyfile changed? Validate before anything irreversible (pre-migration failure →
# code + images reverted, the running caddy container is untouched).
if [ -f "$CADDY_RUNNING_COPY" ] && cmp -s "$CADDY_RUNNING_COPY" "$CADDYFILE"; then
    log "Caddyfile unchanged — caddy is not force-recreated."
else
    CADDY_CHANGED=1
    log "Caddyfile changed — validating the new one..."
    if ! dc run --rm --no-deps -T caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile; then
        echo "ERROR: new deployment/Caddyfile is invalid — aborting (old Caddy keeps running)." >&2
        exit 1
    fi
fi

# Install ops scripts (copy + rename: never overwrite a running script in place).
mkdir -p "$SCRIPTS_DIR"
for s in backup-production-db.sh restore-production-db.sh deploy-production.sh; do
    if [ -f "${REPO_DIR}/scripts/${s}" ]; then
        cp "${REPO_DIR}/scripts/${s}" "${SCRIPTS_DIR}/.${s}.tmp"
        chmod +x "${SCRIPTS_DIR}/.${s}.tmp"
        mv -f "${SCRIPTS_DIR}/.${s}.tmp" "${SCRIPTS_DIR}/${s}"
    fi
done

# ---- 7. Database, migrations -------------------------------------------------------
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

# ---- 8. Containers (caches are built per container on start), workers -------------
log "Starting all application services on the new images..."
CONTAINERS_RECREATED=1
dc up -d --remove-orphans "${APP_SERVICES[@]}"
if [ "$CADDY_CHANGED" = "1" ]; then
    # Single-file bind mount: only a new container sees the new Caddyfile.
    log "Recreating caddy for the new Caddyfile..."
    dc up -d --force-recreate --no-deps caddy
fi

log "Waiting for PHP-FPM (entrypoint builds the caches first)..."
wait_for_app

# Signal shared via cache: restarts queue + queue-broadcasts on the new code.
log "Restarting queue workers..."
dc exec -T queue php artisan queue:restart || warn "queue:restart failed."

# ---- 9. Leave maintenance, health check ------------------------------------------
leave_maintenance || exit 1

log "Verifying service health (through Caddy)..."
dc ps
HTTP_STATUS="000"
for attempt in 1 2 3 4 5; do
    HTTP_STATUS=$(health_status)
    [ "$HTTP_STATUS" = "200" ] && break
    warn "health check attempt ${attempt}/5: HTTP ${HTTP_STATUS}"
    sleep "$RETRY_SLEEP"
done
log "Health check /up HTTP status: ${HTTP_STATUS}"
if [ "$HTTP_STATUS" != "200" ]; then
    echo "ERROR: Health check failed with status ${HTTP_STATUS}" >&2
    exit 1
fi

# Untagged images of older releases (keeps :production, :previous and the Sail image).
docker image prune -f > /dev/null || warn "docker image prune failed."

echo "=================================================="
echo " DEPLOYMENT SUCCESSFUL!"
echo " Deployed SHA: ${DEPLOYED_SHA} (previous: ${PREV_SHA:-unknown})"
echo " Time: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo "=================================================="
