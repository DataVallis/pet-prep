#!/usr/bin/env bash
# PetPrep Production Deployment Script
# Deploys the exact Git commit SHA, performs DB backup, migrations, cache builds, and container healthchecks.

set -Eeuo pipefail

REPO_DIR="/opt/petprep/repo"
ENV_FILE="/opt/petprep/.env"
COMPOSE_FILE="${REPO_DIR}/backend/compose.production.yaml"
REPO_URL="https://github.com/DataVallis/pet-prep.git"

COMMIT_SHA="${1:-}"

echo "=================================================="
echo " Starting PetPrep Production Deployment"
echo " Time: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo " Target Commit: ${COMMIT_SHA:-HEAD}"
echo "=================================================="

# 1. Prepare Repository Directory
cd "${REPO_DIR}"

if [ -d "${REPO_DIR}/.git" ]; then
    echo "Git repository detected. Updating git tree..."
    git fetch origin main --tags --prune 2>/dev/null || true
    if [ -n "$COMMIT_SHA" ]; then
        git checkout -f "$COMMIT_SHA" 2>/dev/null || true
    fi
    DEPLOYED_SHA=$(git rev-parse HEAD 2>/dev/null || echo "${COMMIT_SHA:-manual}")
else
    DEPLOYED_SHA="${COMMIT_SHA:-manual-sync}"
fi

echo "Deploying target commit SHA: ${DEPLOYED_SHA}"

# 3. Copy Production .env
if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: Production environment file ${ENV_FILE} is missing." >&2
    exit 1
fi

# 3a. Preflight: realtime needs Redis queue + Reverb broadcaster (M1-09). Reads only
# these non-secret keys; never prints other values.
for pair in "QUEUE_CONNECTION=redis" "BROADCAST_CONNECTION=reverb"; do
    key="${pair%%=*}"; want="${pair#*=}"
    have=$(grep -E "^${key}=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | tr -d '"'"'"' \r' || true)
    if [ "$have" != "$want" ]; then
        echo "ERROR: ${key} must be '${want}' in ${ENV_FILE} (found '${have:-<unset>}')." >&2
        exit 1
    fi
done

rm -f "${REPO_DIR}/backend/.env"
cp "$ENV_FILE" "${REPO_DIR}/backend/.env"
chmod 600 "${REPO_DIR}/backend/.env"
echo "Installed production .env into ${REPO_DIR}/backend/.env"

# Copy backup scripts to /opt/petprep/scripts if changed
if [ -d "${REPO_DIR}/scripts" ]; then
    mkdir -p /opt/petprep/scripts
    cp -u "${REPO_DIR}/scripts/backup-production-db.sh" /opt/petprep/scripts/ 2>/dev/null || true
    cp -u "${REPO_DIR}/scripts/restore-production-db.sh" /opt/petprep/scripts/ 2>/dev/null || true
    chmod +x /opt/petprep/scripts/*.sh 2>/dev/null || true
fi

# 4. Pre-deployment Database Backup
if [ -f "/opt/petprep/scripts/backup-production-db.sh" ] && docker compose -f "$COMPOSE_FILE" ps postgres 2>/dev/null | grep -q "Up"; then
    echo "Creating pre-deployment database backup..."
    /opt/petprep/scripts/backup-production-db.sh || echo "Warning: Backup skipped (container not yet initialized)"
fi

# 5. Build Production Application Image
echo "Building production Docker images..."
docker compose -f "$COMPOSE_FILE" build app

# 6. Start Postgres and Redis
echo "Ensuring database and cache services are running..."
docker compose -f "$COMPOSE_FILE" up -d postgres redis

echo "Waiting for PostgreSQL and Redis to be healthy..."
docker compose -f "$COMPOSE_FILE" exec -T postgres pg_isready -q || sleep 5

# 7. Run Database Migrations
echo "Running database migrations (--force)..."
docker compose -f "$COMPOSE_FILE" run --rm app php artisan migrate --force

# Seed breed configs if table is empty
docker compose -f "$COMPOSE_FILE" run --rm app php artisan db:seed --class=Database\\Seeders\\BreedConfigsSeeder --force 2>/dev/null || true

# 8. Optimize Laravel Configuration & Route Caches
echo "Optimizing Laravel config and routes..."
docker compose -f "$COMPOSE_FILE" run --rm app php artisan config:cache
docker compose -f "$COMPOSE_FILE" run --rm app php artisan route:cache
docker compose -f "$COMPOSE_FILE" run --rm app php artisan view:cache

# 9. Start/Recreate All Application Containers
echo "Starting all application services..."
docker compose -f "$COMPOSE_FILE" up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy

# 10. Restart Queue Workers to load new code (signal is shared via cache: restarts queue + queue-broadcasts)
echo "Restarting queue workers..."
docker compose -f "$COMPOSE_FILE" exec -T queue php artisan queue:restart || true

# 11. Health Check Verification
echo "Verifying service health..."
sleep 4
docker compose -f "$COMPOSE_FILE" ps

# Check HTTP health endpoint via Caddy
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1/up || echo "000")
if [ "$HTTP_STATUS" != "200" ]; then
    # Also check container IP
    HTTP_STATUS=$(docker compose -f "$COMPOSE_FILE" exec -T app curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/up || echo "000")
fi

echo "Health Check /up HTTP Status: ${HTTP_STATUS}"
if [ "$HTTP_STATUS" != "200" ]; then
    echo "ERROR: Health check failed with status ${HTTP_STATUS}" >&2
    exit 1
fi

echo "=================================================="
echo " DEPLOYMENT SUCCESSFUL!"
echo " Deployed SHA: ${DEPLOYED_SHA}"
echo " Time: $(date -u +"%Y-%m-%d %H:%M:%S UTC")"
echo "=================================================="
