#!/usr/bin/env bash
# check() evals its condition later, so single quotes are intended.
# shellcheck disable=SC2016,SC2034
# Harness for scripts/backup-production-db.sh (retention must stay top-level and
# never fail the backup). Stubs docker; no real database involved.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT}/scripts/backup-production-db.sh"
WORK="$(mktemp -d)"
trap 'chmod -R u+rwx "$WORK" 2>/dev/null || true; rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
check() {
    if eval "$2"; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL: $1"; fi
}

mkdir -p "$WORK/bin" "$WORK/backups/pre-reset"
cat > "$WORK/bin/docker" <<'STUB'
#!/usr/bin/env bash
case "$*" in
    *" ps postgres"*) echo "petprep-postgres  Up 2 hours" ;;
    *" exec "*) echo "-- fake pg_dump output" ;;
esac
STUB
chmod +x "$WORK/bin/docker"
printf 'DB_USERNAME=petprep\nDB_DATABASE=petprep_production\n' > "$WORK/.env"

old_top="$WORK/backups/petprep_petprep_production_2000-01-01_000000.sql.gz"
old_nested="$WORK/backups/pre-reset/petprep_petprep_production_2000-01-01_000000.sql.gz"
pre_reset_copy="$WORK/backups/pre-reset/pre-reset_db_2000-01-01.sql.gz"
for f in "$old_top" "$old_nested" "$pre_reset_copy"; do echo x | gzip > "$f"; touch -d '2000-01-01' "$f"; done
# As on the server: the deploy user cannot read pre-reset/ (root, umask 077).
chmod 000 "$WORK/backups/pre-reset"

set +e
out="$(PATH="$WORK/bin:$PATH" PETPREP_BACKUP_DIR="$WORK/backups" PETPREP_ENV_FILE="$WORK/.env" \
    PETPREP_COMPOSE_FILE="$WORK/compose.yaml" bash "$SCRIPT" 2>&1)"
rc=$?
set -e
chmod 755 "$WORK/backups/pre-reset"

check "exit 0 with an unreadable pre-reset/ subfolder" '[ "$rc" -eq 0 ]'
check "reports success" 'grep -q "Backup completed successfully" <<<"$out"'
check "never mentions Permission denied" '! grep -q "Permission denied" <<<"$out"'
check "new backup written" '[ "$(find "$WORK/backups" -maxdepth 1 -name "petprep_petprep_production_*.sql.gz" -newer "$WORK/.env" | wc -l)" -eq 1 ]'
check "old top-level backup pruned" '[ ! -e "$old_top" ]'
check "nested old backup untouched" '[ -e "$old_nested" ]'
check "pre-reset copy untouched" '[ -e "$pre_reset_copy" ]'

echo "backup-production-db harness: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
