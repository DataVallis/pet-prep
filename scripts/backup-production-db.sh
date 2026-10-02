#!/usr/bin/env bash
# PetPrep Production Database Backup Script
# Creates a compressed, timestamped PostgreSQL backup using pg_dump

set -Eeuo pipefail

BACKUP_DIR="/opt/petprep/backups"
ENV_FILE="/opt/petprep/.env"
COMPOSE_FILE="/opt/petprep/repo/backend/compose.production.yaml"
RETENTION_DAYS=7

mkdir -p "$BACKUP_DIR"

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: Environment file $ENV_FILE not found." >&2
    exit 1
fi

# Extract DB credentials safely from production .env
DB_USER=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_NAME=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")

TIMESTAMP=$(date +"%Y-%m-%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/petprep_${DB_NAME}_${TIMESTAMP}.sql.gz"

echo "=== Starting PostgreSQL Backup: ${TIMESTAMP} ==="

# Check if postgres container is running
if ! docker compose -f "$COMPOSE_FILE" ps postgres | grep -q "Up"; then
    echo "WARNING: Postgres container is not running, attempting backup skipped or container starting..."
    exit 1
fi

# Run pg_dump inside container and pipe to gzip
docker compose -f "$COMPOSE_FILE" exec -T postgres pg_dump -U "$DB_USER" -d "$DB_NAME" --clean --if-exists | gzip > "$BACKUP_FILE"

# Verify backup was created and is non-empty
if [ ! -s "$BACKUP_FILE" ]; then
    echo "ERROR: Backup file $BACKUP_FILE is empty or failed to create." >&2
    rm -f "$BACKUP_FILE"
    exit 1
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE" | awk '{print $1}')
echo "SUCCESS: Database backup created at $BACKUP_FILE (Size: $BACKUP_SIZE)"

# Clean up backups older than RETENTION_DAYS
echo "Applying retention policy: deleting backups older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -type f -name "petprep_*.sql.gz" -mtime +"$RETENTION_DAYS" -exec rm -v {} \;

echo "=== Backup completed successfully ==="
