#!/usr/bin/env bash
# Harness for scripts/reset-game-data.sh — no server, no Docker, no secrets.
#
# Every case builds a throw-away PETPREP_ROOT (.env, repo/backend/compose file,
# scripts/backup-production-db.sh stub that writes a gzip into backups/) and puts
# a `docker` shim in front of PATH that logs each call and fails on demand:
#   FAIL_MATCH   ERE over the docker args → exit 1
#   APP_RUNNING  0 → `ps -q --status running app` prints nothing
#   BACKUP_FAIL  1 → the backup stub fails
#   RESET_RC     exit code of `artisan petprep:reset-game-data --execute` (default 0)
#   DRY_RC       exit code of the dry run (default 0)
# `exec … tar -czf -` prints a real (tiny) gzip so `gzip -t` passes; `du -sb` prints
# MEDIA_BYTES (default 1000), psql prints DB_BYTES (default 5000).
# `df` shim prints DF_AVAIL bytes free (default 10^12).
#
# Run: bash scripts/tests/reset-game-data.test.sh
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../reset-game-data.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SECRET="sentinel-secret-value-$$"

PASS=0; FAIL=0
ok()   { PASS=$((PASS + 1)); echo "    ok   - $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "    FAIL - $1"; }
check() { local desc=$1; shift; if "$@"; then ok "$desc"; else bad "$desc"; fi; }

SHIMS="${TMP}/shims"; mkdir -p "$SHIMS"
cat > "${SHIMS}/docker" <<'EOF'
#!/usr/bin/env bash
args="$*"
echo "docker ${args}" >> "$CALL_LOG"
if [ -n "${FAIL_MATCH:-}" ] && [[ "$args" =~ $FAIL_MATCH ]]; then exit 1; fi
case "$args" in
    *"ps -q --status running app"*) [ "${APP_RUNNING:-1}" = "1" ] && echo "c0ffee" ;;
    *"tar -czf -"*) printf 'media' | gzip -c ;;
    *"du -sb "*) printf '%s\t/var/www/html/storage/app/pet-media\n' "${MEDIA_BYTES:-1000}" ;;
    *"pg_database_size"*) echo "${DB_BYTES:-5000}" ;;
    *"petprep:reset-game-data --execute"*) exit "${RESET_RC:-0}" ;;
    *"petprep:reset-game-data") exit "${DRY_RC:-0}" ;;
esac
exit 0
EOF
chmod +x "${SHIMS}/docker"
cat > "${SHIMS}/df" <<'EOF'
#!/usr/bin/env bash
echo "Filesystem 1-blocks Used Available Capacity Mounted"
echo "/dev/test 2000000000000 1 ${DF_AVAIL:-1000000000000} 1% /"
EOF
chmod +x "${SHIMS}/df"

# setup <case>: fresh root; sets ROOT and CALL_LOG.
setup() {
    ROOT="${TMP}/$1"
    mkdir -p "${ROOT}/repo/backend" "${ROOT}/scripts" "${ROOT}/backups"
    printf 'APP_URL=https://api.petprep.si\nDB_PASSWORD=%s\nDB_DATABASE=petprep_production\n' "$SECRET" > "${ROOT}/.env"
    touch "${ROOT}/repo/backend/compose.production.yaml"
    cat > "${ROOT}/scripts/backup-production-db.sh" <<'EOF'
#!/usr/bin/env bash
echo "backup-stub" >> "$CALL_LOG"
[ "${BACKUP_FAIL:-0}" = "1" ] && exit 1
printf 'dump' | gzip -c > "${PETPREP_ROOT}/backups/petprep_petprep_production_$(date -u +%Y-%m-%d_%H%M%S).sql.gz"
EOF
    chmod +x "${ROOT}/scripts/backup-production-db.sh"
    CALL_LOG="${ROOT}/calls.log"; : > "$CALL_LOG"
}

# run <args…>: runs the script with stdin from /dev/null (no terminal); RC + OUT.
run() {
    set +e
    OUT=$(PATH="${SHIMS}:${PATH}" PETPREP_ROOT="$ROOT" CALL_LOG="$CALL_LOG" bash "$SCRIPT" "$@" < /dev/null 2>&1)
    RC=$?
    set -e
}

called()     { grep -qE -- "$1" "$CALL_LOG"; }
not_called() { ! grep -qE -- "$1" "$CALL_LOG"; }
line_of()    { grep -nE -- "$1" "$CALL_LOG" | head -n1 | cut -d: -f1; }
before()     { local a b; a=$(line_of "$1"); b=$(line_of "$2"); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]; }
no_secret()  { ! grep -q "$SECRET" <<< "$OUT"; }
out_has()    { grep -qF -- "$1" <<< "$OUT"; }
out_lacks()  { ! grep -qF -- "$1" <<< "$OUT"; }
perms_are()  { local want=$1 f; shift; for f in "$@"; do [ "$(stat -c %a "$f")" = "$want" ] || return 1; done; }
links_are()  { local want=$1 f; shift; for f in "$@"; do [ "$(stat -c %h "$f")" = "$want" ] || return 1; done; }

echo "case: dry run (default)"
setup dry; run
check "exit 0" [ "$RC" -eq 0 ]
check "runs the dry run as uid 1000" called "exec -T --user 1000:1000 app php artisan petprep:reset-game-data$"
check "no --execute" not_called "--execute"
check "no backup, no maintenance, no stop" bash -c "! grep -qE 'backup-stub|artisan down|stop ' '$CALL_LOG'"
check "never prints env secrets" no_secret

echo "case: --execute without a terminal and without --confirm-host"
setup notty; run --execute
check "exit 1" [ "$RC" -eq 1 ]
check "asks for --confirm-host" grep -q -- "--confirm-host=api.petprep.si" <<< "$OUT"
check "no backup / reset" bash -c "! grep -qE 'backup-stub|--execute|artisan down' '$CALL_LOG'"

echo "case: --execute with the wrong host"
setup wronghost; run --execute --confirm-host=petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "no backup / reset" bash -c "! grep -qE 'backup-stub|--execute|artisan down' '$CALL_LOG'"

echo "case: app container not running"
setup noapp; APP_RUNNING=0 run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "nothing but the ps check" bash -c "[ \$(wc -l < '$CALL_LOG') -eq 1 ]"

echo "case: --execute happy path"
setup happy; run --execute --confirm-host=api.petprep.si
check "exit 0" [ "$RC" -eq 0 ]
check "maintenance before stopping workers" before "artisan down --retry=15" "stop queue queue-broadcasts scheduler"
check "workers stopped before the backup (consistent snapshot)" before "stop queue queue-broadcasts scheduler" "backup-stub"
check "DB backup before the media archive" before "backup-stub" "tar -czf - -C /var/www/html/storage/app pet-media"
check "media archive before the reset" before "tar -czf - -C /var/www/html/storage/app pet-media" "--execute"
check "reset gets the long flag, the backup file and --no-interaction" \
    called "artisan petprep:reset-game-data --execute --i-understand-this-deletes-all-game-data --backup-done=${ROOT}/backups/pre-reset/pre-reset_db_[0-9_-]+\.sql\.gz --no-interaction"
check "workers started after the reset" before "--execute" "up -d queue queue-broadcasts scheduler"
check "artisan up last" before "up -d queue queue-broadcasts scheduler" "artisan up$"
check "disk measured before maintenance" before "pg_database_size" "artisan down"
check "media measured before maintenance" before "du -sb /var/www/html/storage/app/pet-media" "artisan down"
check "backups are owner-only (umask 077)" perms_are 600 "${ROOT}"/backups/pre-reset/pre-reset_media_*.tar.gz "${ROOT}"/backups/pre-reset/pre-reset_db_*.sql.gz
check "pre-reset DB copy is a hard link of the dump" links_are 2 "${ROOT}"/backups/pre-reset/pre-reset_db_*.sql.gz
check "restore hint uses strict mode" grep -q "RESTORE_STRICT=1 bash" <<< "$OUT"
check "reminds to fix admin@petprep.io" grep -q "admin@petprep.io" <<< "$OUT"
check "lock released after the run" flock -n "${ROOT}/.ops.lock" true
check "pre-reset DB copy exists (outside the retention pattern)" bash -c "ls '${ROOT}'/backups/pre-reset/pre-reset_db_*.sql.gz"
check "pre-reset media archive is a gzip" bash -c "gzip -t '${ROOT}'/backups/pre-reset/pre-reset_media_*.tar.gz"
check "prints the restore commands" grep -q "restore-production-db.sh ${ROOT}/backups/pre-reset/pre-reset_db_" <<< "$OUT"
check "never prints env secrets" no_secret

echo "case: backup fails"
setup nobackup; BACKUP_FAIL=1 run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "no reset" not_called "--execute"
check "workers started again" called "up -d queue queue-broadcasts scheduler"
check "artisan up" called "artisan up$"
check "says no data was deleted" grep -q "no data was deleted" <<< "$OUT"

echo "case: media archive fails"
setup notar; FAIL_MATCH="tar -czf" run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "no reset" not_called "--execute"
check "workers started again + artisan up" bash -c "grep -q 'up -d queue' '$CALL_LOG' && grep -qE 'artisan up$' '$CALL_LOG'"
check "no half-written media archive left" bash -c "! ls '${ROOT}'/backups/pre-reset/pre-reset_media_* 2>/dev/null"

echo "case: artisan down fails"
setup nodown; FAIL_MATCH="artisan down" run --execute --confirm-host=api.petprep.si
check "exit != 0" [ "$RC" -ne 0 ]
check "no reset, workers untouched" bash -c "! grep -qE -- '--execute|stop |up -d' '$CALL_LOG'"
check "artisan up attempted anyway (maintenance flag set before down)" called "artisan up$"

echo "case: the reset command fails before the commit (exit 1)"
setup resetfail; RESET_RC=1 run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "workers started again" called "up -d queue queue-broadcasts scheduler"
check "artisan up" called "artisan up$"
check "says nothing was deleted" out_has "RESET FAILED BEFORE THE COMMIT"
check "not reported as committed" out_lacks "IS COMMITTED"

echo "case: the DB reset committed but the cleanup failed (exit 2)"
setup postcommit; RESET_RC=2 run --execute --confirm-host=api.petprep.si
check "exit 2" [ "$RC" -eq 2 ]
check "workers started again" called "up -d queue queue-broadcasts scheduler"
check "artisan up" called "artisan up$"
check "says the DB reset is committed" out_has "DB RESET IS COMMITTED"
check "says to rerun" out_has "reset-game-data.sh --execute"
check "no success banner" out_lacks "GAME DATA RESET DONE"

echo "case: the dry run refuses (unclassified table / media root)"
setup dryrefuse; DRY_RC=1 run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "nothing after the dry run" bash -c "! grep -qE 'artisan down|backup-stub|--execute|du -sb' '$CALL_LOG'"

echo "case: not enough free disk"
setup nodisk; DF_AVAIL=1000 run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "explains" out_has "not enough free disk"
check "no maintenance, no backup" bash -c "! grep -qE 'artisan down|backup-stub|stop ' '$CALL_LOG'"

echo "case: sizes cannot be measured"
setup nomeasure; DB_BYTES=oops run --execute --confirm-host=api.petprep.si
check "exit 1" [ "$RC" -eq 1 ]
check "no maintenance" not_called "artisan down"

echo "case: a deploy holds the ops lock"
setup locked
flock "${ROOT}/.ops.lock" sleep 30 &
HOLDER=$!
sleep 0.3
run --execute --confirm-host=api.petprep.si
kill "$HOLDER" 2>/dev/null || true; wait "$HOLDER" 2>/dev/null || true
check "exit 1" [ "$RC" -eq 1 ]
check "explains the lock" out_has "a deploy (or another reset) is running"
check "no docker call at all" bash -c "[ ! -s '$CALL_LOG' ]"

echo "case: the dry run does not need the lock"
setup dryunlocked
flock "${ROOT}/.ops.lock" sleep 30 &
HOLDER=$!
sleep 0.3
run
kill "$HOLDER" 2>/dev/null || true; wait "$HOLDER" 2>/dev/null || true
check "exit 0" [ "$RC" -eq 0 ]

echo
echo "reset-game-data.sh harness: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
