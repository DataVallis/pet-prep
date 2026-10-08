#!/usr/bin/env bash
# PetPrep Production Database Restore Script
# Restores a compressed PostgreSQL dump into the production database

set -Eeuo pipefail

ENV_FILE="/opt/petprep/.env"
COMPOSE_FILE="/opt/petprep/repo/backend/compose.production.yaml"

if [ $# -lt 1 ]; then
    echo "Usage: $0 <path_to_backup_file.sql.gz>" >&2
    echo "Available backups in /opt/petprep/backups:"
    ls -lh /opt/petprep/backups/*.sql.gz 2>/dev/null || echo "No backups found."
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo "ERROR: Backup file $BACKUP_FILE does not exist." >&2
    exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
    echo "ERROR: Environment file $ENV_FILE not found." >&2
    exit 1
fi

DB_USER=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")
DB_NAME=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"' | tr -d "'")

echo "=== RESTORE WARNING ==="
echo "Target Database: $DB_NAME (User: $DB_USER)"
echo "Source File: $BACKUP_FILE"
echo "This operation will overwrite existing database data."
echo "======================="

# RESTORE_STRICT=1 (D17, pre-reset restore): stop at the first error and apply the
# dump in ONE transaction — all or nothing. Default (unchanged): psql continues
# past errors, as before.
PSQL_STRICT=()
if [ "${RESTORE_STRICT:-0}" = "1" ]; then
    PSQL_STRICT=(-v ON_ERROR_STOP=1 --single-transaction)
    echo "Strict mode: ON_ERROR_STOP=1, single transaction."
fi

# Stream gunzip into psql inside the container
gunzip -c "$BACKUP_FILE" | docker compose -f "$COMPOSE_FILE" exec -T postgres psql ${PSQL_STRICT[@]+"${PSQL_STRICT[@]}"} -U "$DB_USER" -d "$DB_NAME"

echo "SUCCESS: Database restored from $BACKUP_FILE"
