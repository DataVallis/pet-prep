#!/usr/bin/env bash
# check() evals its condition later, so single quotes are intended.
# shellcheck disable=SC2016,SC2034
# Harness for scripts/backup-offsite.sh (DEPLOYMENT.md D5). Stubs docker, curl
# and `date +%u`; real flock and timeout. No database, no restic repository.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT}/scripts/backup-offsite.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

PASS=0
FAIL=0
check() {
    if eval "$2"; then PASS=$((PASS + 1)); else FAIL=$((FAIL + 1)); echo "FAIL: $1"; fi
}

mkdir -p "$WORK/bin" "$WORK/root/backups"
DUMP_OK='--\n-- PostgreSQL database dump\n--\nCREATE TABLE pets ();\n--\n-- PostgreSQL database dump complete\n--\n\n\\unrestrict abc\n\n'

# docker stub: records every call in $LOG; behaviour switched by files in $WORK.
cat > "$WORK/bin/docker" <<STUB
#!/usr/bin/env bash
echo "docker \$*" >> "\$LOG"
case "\$*" in
    "volume inspect backend_app_storage") exit 0 ;;
    "volume inspect"*) exit 1 ;;
    *" ps postgres"*) echo "petprep-postgres  Up 2 hours" ;;
    *" exec -T postgres pg_dump"*)
        printf -- '--\n-- PostgreSQL database dump\n--\nCREATE TABLE pets ();\n'
        [ -e "\$WORK/pgdump-fails" ] && { echo "pg_dump: error" >&2; exit 1; }
        printf -- '--\n-- PostgreSQL database dump complete\n--\n' ;;
    *"alpine"*) echo "3" ;;
    *"restic/restic"*" cat config"*) [ -e "\$WORK/repo-initialised" ] || exit 10 ;;
    *"restic/restic"*" init"*) touch "\$WORK/repo-initialised" ;;
    *"restic/restic"*" snapshots --tag db-pending --json"*)
        if [ -e "\$WORK/stale-pending" ]; then printf '[{"id":"%s","short_id":"aaaa"}]\n' "\$(printf 'a%.0s' {1..64})"; else echo '[]'; fi ;;
    *"restic/restic"*" backup --stdin"*)
        # Like restic: whatever arrived before EOF is a snapshot.
        cat > "\$WORK/stdin-backup"
        echo '{"message_type":"summary","snapshot_id":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}' ;;
    *"restic/restic"*" dump "*)
        if [ -e "\$WORK/dump-truncated" ]; then
            printf -- '--\n-- PostgreSQL database dump\n--\nCREATE TABLE pets ();\n'
        else
            printf -- '$DUMP_OK'
        fi ;;
    *"rclone/rclone"*" size --json "*)
        if [[ "\$*" == *"mirror:"* ]]; then
            if [ -e "\$WORK/mirror-empty" ]; then echo '{"count":0,"bytes":0}'; elif [ -e "\$WORK/mirror-partial" ]; then echo '{"count":90,"bytes":1}'; else echo '{"count":100,"bytes":1234}'; fi
        else
            if [ -e "\$WORK/bucket-empty" ]; then echo '{"count":0,"bytes":0}'; else echo '{"count":100,"bytes":1234}'; fi
        fi ;;
    *"rclone/rclone"*" purge "*) [ -e "\$WORK/purge-fails" ] && exit 1; true ;;
    *"rclone/rclone"*" lsf --dirs-only "*) printf '2020-01-01/\n%s/\nnot-a-date/\n' "\$(/bin/date -u +%Y-%m-%d)" ;;
    *"rclone/rclone"*" sync "*)
        cp "\$(echo "\$*" | sed -n 's/.*--env-file \([^ ]*\).*/\1/p')" "\$WORK/rclone-env-seen" 2>/dev/null || true
        [ -e "\$WORK/rclone-fails" ] && exit 1 ;;
    *"restic/restic"*" ls "*)
        printf 'drwxr-xr-x 0 0 0 2026-10-14 /data/app/pet-media/12\n'
        if [[ "\$*" == *"--recursive"* ]]; then
            printf -- '-rw-r--r-- 0 0 5 2026-10-14 /data/app/pet-media/12/reference-g1.jpg\n'
            printf -- '-rw-r--r-- 0 0 5 2026-10-14 /data/app/pet-media/looks/3/idle-adult-g1.mp4\n'
        fi ;;
esac
exit 0
STUB
cat > "$WORK/bin/curl" <<'STUB'
#!/usr/bin/env bash
echo "curl $*" >> "$LOG"
STUB
cat > "$WORK/bin/date" <<'STUB'
#!/usr/bin/env bash
if [ "$*" = "-u +%u" ]; then echo "${TEST_DOW:-3}"; else exec /bin/date "$@"; fi
STUB
chmod +x "$WORK/bin/docker" "$WORK/bin/curl" "$WORK/bin/date"

printf 'DB_USERNAME=petprep\nDB_DATABASE=petprep_production\n' > "$WORK/root/.env"
cat > "$WORK/root/backup.env" <<ENV
RESTIC_REPOSITORY=sftp:storagebox:petprep
RESTIC_PASSWORD=\$(touch $WORK/pwned)
BACKUP_HEALTHCHECK_URL=https://hc.example/ping
ENV
mkdir -p "$WORK/root/backup-ssh"
touch "$WORK/root/backup-ssh/config"

export LOG="$WORK/log" WORK
run() {
    : > "$LOG"
    rm -f "$WORK/stdin-backup"
    set +e
    out="$(PATH="$WORK/bin:$PATH" PETPREP_ROOT="$WORK/root" PETPREP_BACKUP_LOCK_WAIT=2 bash "$SCRIPT" "$@" 2>&1)"
    rc=$?
    set -e
}
line_of() { grep -n -- "$1" "$LOG" | head -n 1 | cut -d: -f1; }

# 1. Not configured.
mv "$WORK/root/backup.env" "$WORK/root/backup.env.off"
run
check "missing backup.env → exit 1" '[ "$rc" -eq 1 ]'
check "missing backup.env → says not configured" 'grep -q "not configured" <<<"$out"'
check "missing backup.env → no restic call" '! grep -q "restic/restic" "$LOG"'
mv "$WORK/root/backup.env.off" "$WORK/root/backup.env"

# 2. Quotes / inline comments are refused (docker --env-file keeps them).
cp "$WORK/root/backup.env" "$WORK/env.bak"
echo 'BACKUP_HEALTHCHECK_URL=https://hc.example/ping   # optional' >> "$WORK/root/backup.env"
run
check "inline comment → exit 1" '[ "$rc" -eq 1 ] && grep -q "inline comment" <<<"$out"'
cp "$WORK/env.bak" "$WORK/root/backup.env"

# 3. First nightly run (weekday): init, db pending → db, media, retention, status, ping.
run
check "first run exits 0" '[ "$rc" -eq 0 ]'
check "repository initialised on exit 10" 'grep -q "restic/restic:[0-9.]* .* init" "$LOG" || grep -q "restic/restic:[0-9.]* init" "$LOG"'
check "db streamed through stdin" 'grep -q "PostgreSQL database dump complete" "$WORK/stdin-backup"'
check "db stored as db-pending first" 'grep -q "backup --stdin --stdin-filename db/petprep.sql --tag db-pending --json" "$LOG"'
check "db re-tagged db after success" 'grep -q "tag --set db bbbbbbbb" "$LOG"'
check "sftp via -F config (root ssh owner check)" 'grep -q "sftp.command=ssh -F /root/.ssh/config storagebox -s sftp" "$LOG"'
check "media volume of the compose project, read-only" 'grep -q "backend_app_storage:/data:ro" "$LOG"'
check "media backed up from pet-media, ignoring ctime" 'grep -q "backup /data/app/pet-media --tag media --ignore-ctime" "$LOG"'
check "ssh dir mounted read-only" 'grep -q "backup-ssh:/root/.ssh:ro" "$LOG"'
check "credentials passed as env-file (never sourced)" 'grep -q -- "--env-file $WORK/root/backup.env" "$LOG" && [ ! -e "$WORK/pwned" ]'
check "retention: every snapshot of 35 days per tag" 'grep -q "forget --group-by host,tags --keep-within 35d --prune" "$LOG"'
check "-i only on the stdin DB backup (no SIGTTIN hang in a terminal)" '[ "$(grep -c "run --rm -i " "$LOG")" -eq 1 ] && grep "run --rm -i " "$LOG" | grep -q "backup --stdin"'
check "no check on a weekday" '! grep -q " check " "$LOG"'
check "status file written" '[ -s "$WORK/root/backups/offsite-last-success" ]'
check "success ping" 'grep -q "curl .*https://hc.example/ping$" "$LOG"'
check "db before media" '[ "$(line_of "backup --stdin")" -lt "$(line_of "backup /data/app/pet-media")" ]'
check "no temp files left" '[ -z "$(find "$WORK/root/backups" -name ".offsite-*")" ]'

# 4. Ops lock released before the media upload: a deploy can take it meanwhile.
check "lock released after db (flock -u before media)" 'grep -q "flock -u 9" "$SCRIPT" && [ "$(grep -n "flock -u 9" "$SCRIPT" | cut -d: -f1)" -lt "$(grep -n "backup \"\$MEDIA_PATH\"" "$SCRIPT" | cut -d: -f1)" ]'

# 5. Sunday run on an existing repository: no init, check with a data sample.
TEST_DOW=7 run
check "sunday run exits 0" '[ "$rc" -eq 0 ]'
check "no second init" '! grep -q " init" "$LOG"'
check "sunday check with data subset" 'grep -q "check --read-data-subset=5%" "$LOG"'

# 6. Leftover db-pending snapshot of a killed run is forgotten first.
touch "$WORK/stale-pending"
run
rm -f "$WORK/stale-pending"
check "stale pending → forgotten before the new dump" '[ -n "$(line_of "forget aaaaaaaa")" ] && [ "$(line_of "forget aaaaaaaa")" -lt "$(line_of "backup --stdin")" ]'

# 7. pg_dump fails mid-stream → the partial snapshot is forgotten, never tagged db.
rm -f "$WORK/root/backups/offsite-last-success"
touch "$WORK/pgdump-fails"
run
rm -f "$WORK/pgdump-fails"
check "failed pg_dump → exit 1" '[ "$rc" -eq 1 ]'
check "failed pg_dump → partial snapshot forgotten" 'grep -q "forget bbbbbbbb" "$LOG"'
check "failed pg_dump → never tagged db" '! grep -q "tag --set db" "$LOG"'
check "failed pg_dump → fail ping" 'grep -q "curl .*https://hc.example/ping/fail" "$LOG"'
check "failed pg_dump → no retention" '! grep -q -- "--keep-daily" "$LOG"'
check "failed pg_dump → no media upload" '! grep -q "backup /data/app/pet-media" "$LOG"'
check "failed pg_dump → no status file" '[ ! -e "$WORK/root/backups/offsite-last-success" ]'

# 8. Ops lock held by a deploy → gives up after the wait, no dump.
( exec 9>>"$WORK/root/.ops.lock"; flock 9; sleep 6 ) &
holder=$!
sleep 1
run
wait "$holder"
check "busy lock → exit 1" '[ "$rc" -eq 1 ]'
check "busy lock → explains" 'grep -q "ops lock busy" <<<"$out"'
check "busy lock → no dump" '! grep -q "backup --stdin" "$LOG"'

# 9. Missing media volume (wrong project / docker down) → loud failure + fail ping.
run_project() {
    : > "$LOG"
    set +e
    out="$(PATH="$WORK/bin:$PATH" PETPREP_ROOT="$WORK/root" PETPREP_COMPOSE_PROJECT=other bash "$SCRIPT" 2>&1)"
    rc=$?
    set -e
}
run_project
check "unknown volume → exit 1 with reason" '[ "$rc" -eq 1 ] && grep -q "other_app_storage not found" <<<"$out"'
check "unknown volume → fail ping" 'grep -q "ping/fail" "$LOG"'

# 10. verify: complete dump + nested media files → ok; truncated dump → fails.
run verify
check "verify passes" '[ "$rc" -eq 0 ] && grep -q "Restore test passed" <<<"$out"'
check "verify lists media recursively (nested pet / look folders)" 'grep -q "live 3, latest snapshot 2" <<<"$out"'
touch "$WORK/dump-truncated"
run verify
rm -f "$WORK/dump-truncated"
check "truncated dump → verify fails" '[ "$rc" -eq 1 ] && grep -q "truncated" <<<"$out"'

# 11. A second run while one is in progress refuses at once.
( exec 8>>"$WORK/root/.offsite.lock"; flock 8; sleep 4 ) &
holder=$!
sleep 1
run
wait "$holder"
check "overlapping run → exit 1" '[ "$rc" -eq 1 ] && grep -q "still in progress" <<<"$out"'
check "overlapping run → touches nothing" '! grep -q "restic/restic" "$LOG"'

# 12. Unknown mode.
run nonsense
check "unknown mode → exit 1 with usage" '[ "$rc" -eq 1 ] && grep -q "Usage" <<<"$out"'

# 13. M4-05: media on object storage (PET_MEDIA_DISK=s3) → rclone mirror to a second bucket.
cp "$WORK/root/.env" "$WORK/env.app.bak"
cat >> "$WORK/root/.env" <<'ENV'
PET_MEDIA_DISK=s3
PET_MEDIA_S3_BUCKET=petprep-media
PET_MEDIA_S3_KEY=src-key-id
PET_MEDIA_S3_SECRET=src-secret
ENV
run
check "s3 without mirror config → exit 1 with reason" '[ "$rc" -eq 1 ] && grep -q "MEDIA_MIRROR_S3_BUCKET is missing" <<<"$out"'
check "s3 without mirror config → the DB is still backed up first" 'grep -q "tag --set db bbbbbbbb" "$LOG" && ! grep -q "rclone/rclone" "$LOG"'
cat >> "$WORK/root/backup.env" <<'ENV'
MEDIA_MIRROR_S3_BUCKET=petprep-media-mirror
MEDIA_MIRROR_S3_KEY=dst-key-id
MEDIA_MIRROR_S3_SECRET=dst-secret
ENV
run
check "s3 run exits 0" '[ "$rc" -eq 0 ]'
check "s3: bucket synced to mirror current/ with a dated deleted/ dir and a deletion cap" 'grep -q "rclone/rclone:[0-9.]* sync media:petprep-media/pet-media mirror:petprep-media-mirror/current --backup-dir mirror:petprep-media-mirror/deleted/$(/bin/date -u +%Y-%m-%d) --max-delete 50" "$LOG"'
check "s3: secrets never on a command line" '! grep -q "src-secret\|dst-secret" "$LOG"'
check "s3: rclone gets both remotes from the env-file" 'grep -q "RCLONE_CONFIG_MEDIA_SECRET_ACCESS_KEY=src-secret" "$WORK/rclone-env-seen" && grep -q "RCLONE_CONFIG_MIRROR_ENDPOINT=https://nbg1.your-objectstorage.com" "$WORK/rclone-env-seen"'
check "s3: deleted/ older than 35 days purged, today and junk kept" 'grep -q "purge mirror:petprep-media-mirror/deleted/2020-01-01" "$LOG" && [ "$(grep -c " purge " "$LOG")" -eq 1 ]'
check "s3: volume media still backed up (local copies until prune)" 'grep -q "backup /data/app/pet-media --tag media" "$LOG"'
check "s3: rclone env-file removed" '[ ! -e "$WORK/root/backups/.offsite-rclone.env" ]'
touch "$WORK/rclone-fails"
run
rm -f "$WORK/rclone-fails"
check "s3: failed sync → exit 1 + fail ping, no status" '[ "$rc" -eq 1 ] && grep -q "media mirror (rclone sync) failed" <<<"$out" && grep -q "ping/fail" "$LOG"'
run verify
check "s3 verify counts bucket and mirror" '[ "$rc" -eq 0 ] && grep -q "Media objects: bucket 100, mirror 100" <<<"$out"'
touch "$WORK/mirror-empty"
run verify
rm -f "$WORK/mirror-empty"
check "s3 verify: empty mirror of a non-empty bucket fails" '[ "$rc" -eq 1 ] && grep -q "mirror holds no files" <<<"$out"'
touch "$WORK/mirror-partial"
run verify
rm -f "$WORK/mirror-partial"
check "s3 verify: mirror < 95 % of the bucket fails" '[ "$rc" -eq 1 ] && grep -q "only 90 of 100" <<<"$out"'
touch "$WORK/bucket-empty"
run
rm -f "$WORK/bucket-empty"
check "s3: empty bucket + full mirror → no sync, run fails" '[ "$rc" -eq 1 ] && ! grep -q " sync media:" "$LOG" && grep -q "bucket is EMPTY" <<<"$out"'
check "s3: empty bucket → restic retention still ran" 'grep -q "forget --group-by host,tags --keep-within 35d" "$LOG"'
touch "$WORK/purge-fails"
run
rm -f "$WORK/purge-fails"
check "s3: purge failure → retention still runs, run fails at the end" '[ "$rc" -eq 1 ] && grep -q "forget --group-by host,tags --keep-within 35d" "$LOG" && grep -q "could not purge" <<<"$out" && [ "$(line_of "purge mirror:")" -lt "$(line_of "forget --group-by")" ]'
cp "$WORK/env.app.bak" "$WORK/root/.env"

echo "backup-offsite harness: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
