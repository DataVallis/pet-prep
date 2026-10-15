#!/usr/bin/env bash
# Harness for scripts/deploy-production.sh — no server, no Docker, no secrets.
#
# Every case builds a throw-away PETPREP_ROOT in a temp dir (repo/ = release "v1",
# incoming/ = release "v2") and puts PATH shims in front of the real tools:
#   docker  logs each call (+ which release is on disk at that moment) and fails on
#           demand: FAIL_MATCH (ERE over the args), UP_FAILS (number of failing
#           `artisan up` invocations, exec and run --rm count separately),
#           DOWN_FAILS (same for `artisan down`), NO_PREV_IMAGES=1 (`image inspect`
#           fails: first deploy of the M4-05b runtime, no petprep-*:production yet),
#           NO_VOLUME=1 (`volume inspect` fails), APP_RUNNING=0 (`ps -q app` empty),
#           STORAGE_BAD=1 (the uid-1000 storage check in the new image fails),
#           PENDING_RC (exit of `migrate:status --pending=3`: 3 = pending — default,
#           0 = none → no maintenance, other = unknown), REVERB_RUNNING=0, CADDY_RUNNING=0,
#           PHP_BASE_ID / CADDY_BASE_ID (base image ids), BASE_BUILD_FAIL=1.
#           PETPREP_IMAGE_TAG (compose against the new tree) is logged in front.
#   git     logs the call, then runs the real git (git-mode cases use a real temp repo).
#   curl    prints CURL_STATUS (default 200).
#   sleep   no-op.
# rsync is the real one (required).
#
# Run: bash scripts/tests/deploy-production.test.sh
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../deploy-production.sh"
REAL_GIT="$(command -v git)"
command -v rsync >/dev/null || { echo "rsync is required" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SECRET="sentinel-secret-value-$$"

PASS=0; FAIL=0; CASE=""
ok()   { PASS=$((PASS + 1)); echo "    ok   - $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "    FAIL - $1"; }
check() { local desc=$1; shift; if "$@"; then ok "$desc"; else bad "$desc"; fi; }

# ---- shims -------------------------------------------------------------------------
SHIMS="${TMP}/shims"; mkdir -p "$SHIMS"
cat > "${SHIMS}/docker" <<'EOF'
#!/usr/bin/env bash
args="$*"
code=$(cat "${PETPREP_ROOT}/repo/backend/app.txt" 2>/dev/null || echo "?")
tag=""; [ -n "${PETPREP_IMAGE_TAG:-}" ] && tag="PETPREP_IMAGE_TAG=${PETPREP_IMAGE_TAG} "
# Server identity exported by the script for compose (D18), logged with `up`/`run` calls.
case "$args" in *" up -d"*|*" run "*) tag="${tag}[sites=${PETPREP_CADDY_SITES:-unset} domain=${PETPREP_API_DOMAIN:-unset}] " ;; esac
echo "docker ${tag}${args} [code=${code}]" >> "$CALL_LOG"
bump() { local f="${PETPREP_ROOT}/.$1"; local n; n=$(cat "$f" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$f"; echo "$n"; }
if [[ "$args" == *"artisan up"* ]]; then
    n=$(bump up_calls); [ "$n" -le "${UP_FAILS:-0}" ] && exit 1
fi
if [[ "$args" == *"artisan down"* ]]; then
    n=$(bump down_calls); [ "$n" -le "${DOWN_FAILS:-0}" ] && exit 1
fi
if [ -n "${FAIL_MATCH:-}" ] && [[ "$args" =~ $FAIL_MATCH ]]; then exit 1; fi
case "$args" in
    # D19 base ids: the caddy base image and the PHP `base` build stage.
    "image inspect --format {{.Id}} caddy:2.8-alpine"*) echo "${CADDY_BASE_ID:-sha256:caddybase1}" ;;
    "buildx build -q --load --target base "*)
        [ "${BASE_BUILD_FAIL:-0}" = "1" ] && exit 1
        echo "${PHP_BASE_ID:-sha256:phpbase1}" ;;
    "image inspect "*) [ "${NO_PREV_IMAGES:-0}" = "1" ] && exit 1 ;;
    "volume inspect "*) [ "${NO_VOLUME:-0}" = "1" ] && exit 1 ;;
    *" ps -q app"*) [ "${APP_RUNNING:-1}" = "1" ] && echo "c0ffee" ;;
    *"--entrypoint sh app -c "*) [ "${STORAGE_BAD:-0}" = "1" ] && exit 1 ;;
    *"ps -q --status running postgres"*) echo "c0ffee" ;;
    *"ps -q --status running reverb"*) [ "${REVERB_RUNNING:-1}" = "1" ] && echo "c0ffee" ;;
    *"ps -q --status running caddy"*) [ "${CADDY_RUNNING:-1}" = "1" ] && echo "c0ffee" ;;
    # D19: exit 3 = migrations pending (default: the classic maintenance flow), 0 = none.
    *"migrate:status --pending=3"*) exit "${PENDING_RC:-3}" ;;
    *"exec -T caddy cat /etc/caddy/Caddyfile"*)
        [ -f "${PETPREP_ROOT}/caddy.running" ] || exit 1
        cat "${PETPREP_ROOT}/caddy.running" ;;
esac
exit 0
EOF
cat > "${SHIMS}/git" <<EOF
#!/usr/bin/env bash
echo "git \$* [code=\$(cat "\${PETPREP_ROOT}/repo/backend/app.txt" 2>/dev/null || echo ?)]" >> "\$CALL_LOG"
exec "${REAL_GIT}" "\$@"
EOF
cat > "${SHIMS}/curl" <<'EOF'
#!/usr/bin/env bash
echo "curl $*" >> "$CALL_LOG"
printf '%s' "${CURL_STATUS:-200}"
EOF
printf '#!/usr/bin/env bash\nexit 0\n' > "${SHIMS}/sleep"
chmod +x "${SHIMS}"/*

# ---- fixtures ----------------------------------------------------------------------
write_tree() { # dir release
    mkdir -p "$1/backend" "$1/scripts"
    echo "services: {}" > "$1/backend/compose.production.yaml"
    echo "$2" > "$1/backend/app.txt"
    mkdir -p "$1/deployment"; echo "caddy-config-same" > "$1/deployment/Caddyfile"
    echo "only-in-$2" > "$1/backend/only-$2.txt"
    printf '#!/usr/bin/env bash\necho "backup (%s)"\n' "$2" > "$1/scripts/backup-production-db.sh"
}

new_root() { # mode: dir | git
    ROOT="${TMP}/${CASE}"; rm -rf "$ROOT"; mkdir -p "$ROOT/scripts"
    cat > "$ROOT/.env" <<EOF
APP_KEY=${SECRET}
DB_PASSWORD=${SECRET}
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION="reverb"
EOF
    printf '#!/usr/bin/env bash\necho "backup ran"\n' > "$ROOT/scripts/backup-production-db.sh"
    chmod +x "$ROOT/scripts/backup-production-db.sh"
    if [ "$1" = "git" ]; then
        local r="$ROOT/repo"
        write_tree "$r" v1
        printf 'backend/.env\nbackend/vendor/\n.deployed-sha\n' > "$r/.gitignore"
        "$REAL_GIT" -C "$r" init -q -b main
        "$REAL_GIT" -C "$r" -c user.email=t@t -c user.name=t add -A
        "$REAL_GIT" -C "$r" -c user.email=t@t -c user.name=t commit -qm v1
        V1_SHA=$("$REAL_GIT" -C "$r" rev-parse HEAD)
        rm "$r/backend/only-v1.txt"; write_tree "$r" v2
        "$REAL_GIT" -C "$r" -c user.email=t@t -c user.name=t add -A
        "$REAL_GIT" -C "$r" -c user.email=t@t -c user.name=t commit -qm v2
        V2_SHA=$("$REAL_GIT" -C "$r" rev-parse HEAD)
        "$REAL_GIT" -C "$r" checkout -q "$V1_SHA"
    else
        write_tree "$ROOT/repo" v1
        echo "sha-v1" > "$ROOT/repo/.deployed-sha"
        write_tree "$ROOT/incoming" v2
    fi
    mkdir -p "$ROOT/repo/backend/vendor"; echo keep > "$ROOT/repo/backend/vendor/autoload.php"
    # The running caddy container sees the same Caddyfile as repo/ (default).
    cp "$ROOT/repo/deployment/Caddyfile" "$ROOT/caddy.running"
    CALL_LOG="$ROOT/calls.log"; : > "$CALL_LOG"
}

# run_deploy [env assignments...] -- [script args...]
run_deploy() {
    local envs=()
    while [ $# -gt 0 ] && [ "$1" != "--" ]; do envs+=("$1"); shift; done
    shift
    set +e
    env PETPREP_ROOT="$ROOT" CALL_LOG="$CALL_LOG" PATH="${SHIMS}:${PATH}" DEPLOY_RETRY_SLEEP=0 \
        ${envs[@]+"${envs[@]}"} bash "$SCRIPT" "$@" > "$ROOT/out.log" 2>&1
    RC=$?
    set -e
}

code_on_disk() { cat "$ROOT/repo/backend/app.txt"; }
called()       { grep -qE -- "$1" "$CALL_LOG"; }
not_called()   { ! grep -qE -- "$1" "$CALL_LOG"; }
line_of()      { grep -nE -- "$1" "$CALL_LOG" | head -n1 | cut -d: -f1; }
last_line_of() { grep -nE -- "$1" "$CALL_LOG" | tail -n1 | cut -d: -f1; }
before()       { local a b; a=$(line_of "$1"); b=$(line_of "$2"); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -lt "$b" ]; }
after_last()   { local a b; a=$(last_line_of "$1"); b=$(line_of "$2"); [ -n "$a" ] && [ -n "$b" ] && [ "$a" -gt "$b" ]; }
out_has()      { grep -qF -- "$1" "$ROOT/out.log"; }
not_out()      { ! grep -qF -- "$1" "$ROOT/out.log"; }
no_secret()    { ! grep -qF -- "$SECRET" "$ROOT/out.log"; }
rc_is()        { [ "$RC" -eq "$1" ]; }
rc_nonzero()   { [ "$RC" -ne 0 ]; }
sha_is()       { [ "$(cat "$ROOT/repo/.deployed-sha")" = "$1" ]; }
vendor_kept()  { [ -f "$ROOT/repo/backend/vendor/autoload.php" ]; }
eq()           { [ "$1" = "$2" ]; }
dump()         { echo "    --- calls"; sed 's/^/      /' "$CALL_LOG"; echo "    --- output"; sed 's/^/      /' "$ROOT/out.log"; }

start() { CASE=$1; echo "case: $2"; }
finish() { if [ "$FAIL" -gt "${FAIL_BEFORE:-0}" ]; then dump; fi; FAIL_BEFORE=$FAIL; }

# ---- cases -------------------------------------------------------------------------
start success "success (--from)"
new_root dir; run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "artisan down runs on the OLD code, as uid 1000" called 'exec -T --user 1000:1000 app php artisan down --retry=15 \[code=v1\]'
check "artisan up as uid 1000" called 'exec -T --user 1000:1000 app php artisan up'
check "explicit compose project (same volumes for repo/ and the build tree)" called 'compose -p backend -f .*incoming/backend/compose.production.yaml'
check "buildx preflight before the build" before 'buildx version' 'build app caddy'
check "storage chowned as root in the NEW image" called 'PETPREP_IMAGE_TAG=next .*run --rm --no-deps -T --user root --entrypoint chown app -R 1000:1000 /var/www/html/storage'
check "storage verified as uid 1000 in the NEW image" called 'PETPREP_IMAGE_TAG=next .*run --rm --no-deps -T --user 1000:1000 --entrypoint sh app -c '
check "chown after the smoke test" before 'artisan optimize' 'entrypoint chown'
check "chown + verify before maintenance" before 'entrypoint sh app -c' 'artisan down'
check "re-chown after up -d (old containers gone)" after_last 'entrypoint chown app' 'up -d --remove-orphans app'
check "build cache pruned with a keep limit" called 'builder prune -f --filter until=168h --keep-storage 5GB'
check "images built from the NEW tree as :next" called 'PETPREP_IMAGE_TAG=next .*incoming/backend/compose.production.yaml --env-file .* build app caddy'
check "smoke test (artisan optimize) on the :next image" called 'PETPREP_IMAGE_TAG=next .*incoming/backend/compose.production.yaml .*run --rm --no-deps -T app php artisan optimize'
check "build BEFORE maintenance mode" before 'build app caddy' 'artisan down'
check "smoke test BEFORE maintenance mode" before 'artisan optimize' 'artisan down'
check "old :production kept as :previous" called 'tag petprep-app:production petprep-app:previous'
check ":next promoted after the code switch" called 'tag petprep-app:next petprep-app:production \[code=v2\]'
check "web image promoted too" called 'tag petprep-web:next petprep-web:production'
check "promoted before migrate" before 'tag petprep-app:next' 'migrate --force'
check "no build inside maintenance (compose build of repo/)" not_called '/repo/backend/compose.production.yaml build'
check "no shared cache rebuild (per-container caches)" not_called 'config:cache|route:cache|view:cache'
check "waits for PHP-FPM before artisan up" before 'exec -T app php-fpm-ping' 'artisan up'
check "waits after up -d" before 'up -d --remove-orphans app' 'php-fpm-ping'
check "health check end-to-end through Caddy (TLS name)" called 'curl .*--resolve api.petprep.si:443:127.0.0.1 https://api.petprep.si/up'
check "dangling images pruned after success" called 'image prune -f'
check "migrate runs on the NEW code" called 'artisan migrate --force \[code=v2\]'
check "up -d of app services before artisan up" before 'up -d --remove-orphans app reverb' 'artisan up'
check "queue:restart before artisan up" before 'queue:restart' 'artisan up'
check "new code on disk" eq "$(code_on_disk)" v2
check "stale file of v1 removed" test ! -e "$ROOT/repo/backend/only-v1.txt"
check ".deployed-sha = sha-v2" sha_is sha-v2
check "vendor/ preserved" vendor_kept
check "backend/.env installed (600)" test "$(stat -c %a "$ROOT/repo/backend/.env")" = 600
check "previous release snapshot = v1" test "$(cat "$ROOT/releases/previous/backend/app.txt")" = v1
check "no env secret in output" no_secret
check "Caddyfile unchanged → no validate" not_called 'caddy validate'
check "Caddyfile unchanged → caddy not force-recreated" not_called 'force-recreate'
finish

start preflight "preflight fail (QUEUE_CONNECTION=sync) → no docker/git call"
new_root dir; sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "no docker/git/curl call at all" test ! -s "$CALL_LOG"
check "explains the key" out_has "QUEUE_CONNECTION must be 'redis'"
check "code untouched" eq "$(code_on_disk)" v1
check "no env secret in output" no_secret
finish

start preflight_rq "preflight fail (REDIS_QUEUE=fal) → no docker/git call"
new_root dir; echo "REDIS_QUEUE=fal" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "no docker/git/curl call at all" test ! -s "$CALL_LOG"
check "no env secret in output" no_secret
finish

start serve_via_php "PET_MEDIA_SERVE_VIA=php in .env (emergency fallback) → warning, deploy continues"
new_root dir; echo "PET_MEDIA_SERVE_VIA=php" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns about the override" out_has "PET_MEDIA_SERVE_VIA='php'"
check "no env secret in output" no_secret
finish

start no_buildx "docker buildx missing → clear error, nothing changed"
new_root dir; run_deploy FAIL_MATCH='buildx version' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "docker buildx (BuildKit) is not available"
check "no build attempted" not_called 'build app caddy'
check "never entered maintenance" not_called 'artisan down'
check "code untouched" eq "$(code_on_disk)" v1
finish

start storage_chown_fail "chown of the storage volume fails → abort before maintenance"
new_root dir; run_deploy FAIL_MATCH='entrypoint chown' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "could not chown the storage volume"
check "never entered maintenance" not_called 'artisan down'
check "code untouched" eq "$(code_on_disk)" v1
check "no image promoted" not_called ' tag '
finish

start storage_check_fail "storage not writable as uid 1000 after the chown → abort before maintenance"
new_root dir; run_deploy STORAGE_BAD=1 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "storage is not fully writable as uid 1000"
check "never entered maintenance" not_called 'artisan down'
check "no backup / migrate" not_called 'migrate --force'
check "code untouched" eq "$(code_on_disk)" v1
finish

start storage_wrong_project "storage volume missing while app runs (wrong compose project) → abort"
new_root dir; run_deploy NO_VOLUME=1 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "set DEPLOY_COMPOSE_PROJECT"
check "no chown on a wrong volume" not_called 'entrypoint chown app -R 1000:1000 /var/www/html/storage \[code=v1\]'
check "never entered maintenance" not_called 'artisan down'
finish

start storage_fresh_server "no storage volume and no app container (fresh server) → warn, continue"
new_root dir; run_deploy NO_VOLUME=1 APP_RUNNING=0 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "does not exist yet (first deploy on a fresh server)"
check "no pre-maintenance chown" before 'artisan down' 'entrypoint chown'
finish

start rechown_fail "re-chown after up -d fails → warning only, deploy succeeds"
new_root dir; run_deploy FAIL_MATCH='-f [^ ]*/repo/backend/compose.production.yaml run --rm --no-deps -T --user root --entrypoint chown' -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "storage re-normalisation after the switch failed"
check "artisan up" called 'artisan up \[code=v2\]'
finish

start builder_prune_fail "builder prune fails → warning only"
new_root dir; run_deploy FAIL_MATCH='builder prune' -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "docker builder prune failed"
finish

start build_fail "image build fails → nothing changed: no maintenance, no code switch, no promote"
new_root dir; run_deploy FAIL_MATCH='build app' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "image build failed — nothing was changed"
check "never entered maintenance" not_called 'artisan down'
check "no artisan up needed" not_called 'artisan up'
check "code untouched" eq "$(code_on_disk)" v1
check ".deployed-sha still sha-v1" sha_is sha-v1
check "no image promoted" not_called ' tag '
check "no migrate" not_called 'migrate --force'
check "vendor/ preserved" vendor_kept
check "no env secret in output" no_secret
finish

start smoke_fail "smoke test (artisan optimize on :next) fails → nothing changed"
new_root dir; run_deploy FAIL_MATCH='artisan optimize' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "cannot build its caches with the production env"
check "never entered maintenance" not_called 'artisan down'
check "code untouched" eq "$(code_on_disk)" v1
check "no image promoted" not_called ' tag '
finish

start migrate_fail "migrate fails → code reverted, app up"
new_root dir; run_deploy FAIL_MATCH='migrate --force' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "migrate attempted on v2" called 'migrate --force \[code=v2\]'
check "old code back on disk" eq "$(code_on_disk)" v1
check "previous images restored" called 'tag petprep-app:previous petprep-app:production \[code=v1\]'
check "previous web image restored" called 'tag petprep-web:previous petprep-web:production'
check "restore after the promote" after_last 'tag petprep-app:previous petprep-app:production' 'tag petprep-app:next'
check "workers restarted on the old code" called 'restart reverb queue queue-broadcasts scheduler \[code=v1\]'
check "artisan up after revert (old code)" called 'artisan up \[code=v1\]'
check "says it reverted" out_has "Code reverted to sha-v1"
check "vendor/ preserved" vendor_kept
check "no env secret in output" no_secret
finish

start first_runtime "first M4-05b deploy (no petprep-*:production yet) → success, nothing kept as :previous"
new_root dir; run_deploy NO_PREV_IMAGES=1 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warning printed" out_has "first deploy of the M4-05b runtime"
check "nothing tagged :previous" not_called 'petprep-app:previous'
check ":next promoted" called 'tag petprep-app:next petprep-app:production'
finish

start first_runtime_migrate_fail "first M4-05b deploy, migrate fails → code reverted (old compose = Sail image), no image restore"
new_root dir; run_deploy NO_PREV_IMAGES=1 FAIL_MATCH='migrate --force' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "old code back on disk" eq "$(code_on_disk)" v1
check "no :previous to restore" not_called 'tag petprep-app:previous'
check "artisan up (old code)" called 'artisan up \[code=v1\]'
finish

start post_migrate "failure after migrate (container start) → NOT reverted, up, exit != 0"
new_root dir; run_deploy FAIL_MATCH='up -d --remove-orphans' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "new code stays on disk" eq "$(code_on_disk)" v2
check ".deployed-sha = sha-v2" sha_is sha-v2
check "images NOT restored (schema is new)" not_called 'tag petprep-app:previous petprep-app:production'
check "containers (re)started on new code" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy \[code=v2\]'
check "workers restarted" called 'restart reverb queue queue-broadcasts scheduler \[code=v2\]'
check "artisan up (new code)" called 'artisan up \[code=v2\]'
check "loud not-reverted message" out_has "code is NOT reverted"
finish

start fpm_unhealthy "PHP-FPM never answers after up -d → NOT reverted, up, exit != 0"
new_root dir; run_deploy FAIL_MATCH='php-fpm-ping' DEPLOY_APP_WAIT_TRIES=3 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "did not become healthy"
check "tried 3 times" test "$(grep -c 'php-fpm-ping' "$CALL_LOG")" -eq 3
check "new code stays on disk" eq "$(code_on_disk)" v2
check "artisan up (new code)" called 'artisan up \[code=v2\]'
check "no queue:restart / health check after the failure" not_called 'queue:restart|curl'
finish

start health_fail "health check fails → NOT reverted, exit != 0"
new_root dir; run_deploy CURL_STATUS=502 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "new code stays on disk" eq "$(code_on_disk)" v2
check "app left maintenance" called 'artisan up \[code=v2\]'
check "5 attempts, TLS name then IP site each time" test "$(grep -c '^curl' "$CALL_LOG")" -eq 10
check "IP-site fallback with Host header" called 'curl .*-H Host: 138.199.172.97 http://127.0.0.1/up'
check "reports status" out_has "Health check failed with status 502"
check "no prune after a failed deploy" not_called 'image prune'
finish

start up_once "artisan up fails once, then succeeds"
new_root dir; run_deploy UP_FAILS=2 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "attempt 1 warned" out_has "artisan up failed (attempt 1/3)"
check "attempt 2 started" out_has "attempt 2/3"
check "no loud maintenance error" not_out "STILL IN MAINTENANCE"
finish

start up_always "artisan up always fails → loud error, exit != 0"
new_root dir; run_deploy UP_FAILS=999 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "loud maintenance error" out_has "PRODUCTION IS STILL IN MAINTENANCE MODE"
check "manual command printed" out_has "exec -T app php artisan up"
check "exactly 6 up invocations (3 attempts x exec+run, no re-try in trap)" test "$(grep -c 'artisan up' "$CALL_LOG")" -eq 6
check "new code kept (schema migrated)" eq "$(code_on_disk)" v2
finish

start first_deploy "artisan down impossible (fresh server) → warn, continue"
new_root dir; run_deploy DOWN_FAILS=2 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warning printed" out_has "could not enter maintenance mode"
check "no artisan up (never went down)" not_called 'artisan up'
finish

start backup_fail "backup fails while postgres runs → abort before code switch"
new_root dir; printf '#!/usr/bin/env bash\nexit 3\n' > "$ROOT/incoming/scripts/backup-production-db.sh"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "code never switched" eq "$(code_on_disk)" v1
check "images built but not promoted" not_called ' tag '
check "artisan up" called 'artisan up \[code=v1\]'
finish

start backup_release_copy "the release's backup script wins over a broken installed copy"
new_root dir; printf '#!/usr/bin/env bash\nexit 3\n' > "$ROOT/scripts/backup-production-db.sh"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "release copy ran" out_has "backup (v2)"
check "code switched" eq "$(code_on_disk)" v2
finish

start backup_installed_fallback "no backup script in the release → installed copy runs"
new_root dir; rm -f "$ROOT/incoming/scripts/backup-production-db.sh"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "installed copy ran" out_has "backup ran"
finish

start git_success "git mode: success"
new_root git; run_deploy -- "$V2_SHA"
check "exit 0" rc_is 0
check "down before checkout" before 'artisan down' "checkout -f ${V2_SHA}"
check "build context = git archive of the commit" called "archive --format=tar ${V2_SHA}"
check "built from releases/build-src before maintenance" before 'build-src/backend/compose.production.yaml --env-file .* build app caddy' 'artisan down'
check "repo checkout still at v1 while building" called 'build app caddy \[code=v1\]'
check "new code on disk" eq "$(code_on_disk)" v2
check ".deployed-sha = v2 sha" sha_is "$V2_SHA"
finish

start git_build_fail "git mode: build fails → HEAD never moved, no maintenance"
new_root git; run_deploy FAIL_MATCH='build app' -- "$V2_SHA"
check "exit != 0" rc_nonzero
check "HEAD still at PREV_SHA" eq "$("$REAL_GIT" -C "$ROOT/repo" rev-parse HEAD)" "$V1_SHA"
check "never checked out the new commit" not_called "checkout -f ${V2_SHA}"
check "old code on disk" eq "$(code_on_disk)" v1
check "never entered maintenance" not_called 'artisan down'
finish

start git_migrate_fail "git mode: migrate fails → checkout PREV_SHA, images restored, app up"
new_root git; run_deploy FAIL_MATCH='migrate --force' -- "$V2_SHA"
check "exit != 0" rc_nonzero
check "HEAD back at PREV_SHA" eq "$("$REAL_GIT" -C "$ROOT/repo" rev-parse HEAD)" "$V1_SHA"
check "images restored" called 'tag petprep-app:previous petprep-app:production'
check "artisan up (old code)" called 'artisan up \[code=v1\]'
finish

start git_unknown "git mode: unknown SHA → fails before maintenance"
new_root git; run_deploy -- deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
check "exit != 0" rc_nonzero
check "no docker call" not_called '^docker'
finish

start caddy_changed "Caddyfile changed → validated before migrate, caddy recreated after"
new_root dir; echo "caddy-config-NEW" > "$ROOT/incoming/deployment/Caddyfile"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "validate runs on the new tree (one-off, no deps)" called 'run --rm --no-deps -T caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile \[code=v2\]'
check "validate with the promoted (new) caddy image" before 'tag petprep-web:next' 'caddy validate'
check "validate before migrate" before 'caddy validate' 'migrate --force'
check "caddy force-recreated" called 'up -d --force-recreate --no-deps caddy \[code=v2\]'
check "recreate after migrate" before 'migrate --force' 'force-recreate'
check "recreate before artisan up" before 'force-recreate' 'artisan up'
check "new Caddyfile on disk" eq "$(cat "$ROOT/repo/deployment/Caddyfile")" caddy-config-NEW
finish

start caddy_stale "running caddy already stale (repo == new, container differs) → recreated"
new_root dir; echo "caddy-config-OLDER" > "$ROOT/caddy.running"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "validated" called 'caddy validate'
check "caddy force-recreated" called 'force-recreate --no-deps caddy'
finish

start caddy_first "caddy not running (first run) → treated as changed"
new_root dir; rm -f "$ROOT/caddy.running"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warning printed" out_has "treating it as changed"
check "validated" called 'caddy validate'
check "caddy force-recreated" called 'force-recreate --no-deps caddy'
finish

start caddy_invalid "invalid new Caddyfile → abort pre-migration, code reverted, old caddy untouched"
new_root dir; echo "caddy-config-BROKEN" > "$ROOT/incoming/deployment/Caddyfile"
run_deploy FAIL_MATCH='caddy validate' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "Caddyfile is invalid"
check "no migrate" not_called 'migrate --force'
check "caddy not recreated" not_called 'force-recreate'
check "images restored" called 'tag petprep-web:previous petprep-web:production'
check "old code + old Caddyfile back on disk" eq "$(code_on_disk) $(cat "$ROOT/repo/deployment/Caddyfile")" "v1 caddy-config-same"
check "artisan up (old code)" called 'artisan up \[code=v1\]'
finish

start idempotent "re-running the same deploy is a no-op success"
new_root dir; run_deploy -- --from "$ROOT/incoming" sha-v2
run_deploy -- --from "$ROOT/incoming" sha-v2
check "second run exit 0" rc_is 0
check "previous release recorded as sha-v2" out_has "Previous release: sha-v2"
check "code v2" eq "$(code_on_disk)" v2
finish

start identity_prod "server identity: production .env (APP_URL api.petprep.si) → production sites, unchanged health check"
new_root dir; echo "APP_URL=https://api.petprep.si" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" --expect-domain api.petprep.si sha-v2
check "exit 0" rc_is 0
check "says domain + site set" out_has "API domain api.petprep.si, Caddy sites production"
check "compose sees the production site set" called '\[sites=production domain=api.petprep.si\] .*up -d --remove-orphans app'
check "derived keys appended to repo/backend/.env" test "$(grep -c '^PETPREP_CADDY_SITES=production$' "$ROOT/repo/backend/.env")" -eq 1
check "health check on api.petprep.si" called 'curl .*--resolve api.petprep.si:443:127.0.0.1 https://api.petprep.si/up'
check "no env secret in output" no_secret
finish

start identity_default "server identity: no APP_URL at all → production defaults"
new_root dir; run_deploy CURL_STATUS=502 -- --from "$ROOT/incoming" sha-v2
check "exit != 0 (health stubbed to 502)" rc_nonzero
check "production site set" out_has "Caddy sites production"
check "IP-site fallback still on production" called 'curl .*-H Host: 138.199.172.97 http://127.0.0.1/up'
finish

start identity_dev "server identity: second API-only server (APP_URL api-dev.petprep.si) → api-only, health on its domain, no IP fallback"
new_root dir; echo 'APP_URL="https://api-dev.petprep.si/"' >> "$ROOT/.env"
run_deploy CURL_STATUS=502 -- --from "$ROOT/incoming" --expect-domain api-dev.petprep.si sha-v2
check "exit != 0 (health stubbed to 502)" rc_nonzero
check "says domain + site set" out_has "API domain api-dev.petprep.si, Caddy sites api-only"
check "compose sees api-only" called '\[sites=api-only domain=api-dev.petprep.si\] .*up -d --remove-orphans app'
check "health check on the dev domain" called 'curl .*--resolve api-dev.petprep.si:443:127.0.0.1 https://api-dev.petprep.si/up'
check "never api.petprep.si" not_called 'api\.petprep\.si'
check "no raw-IP fallback (would be a false 200)" not_called 'Host: 138.199.172.97'
check "5 attempts, TLS name only" test "$(grep -c '^curl' "$CALL_LOG")" -eq 5
check "derived keys appended to repo/backend/.env" test "$(grep -c '^PETPREP_API_DOMAIN=api-dev.petprep.si$' "$ROOT/repo/backend/.env")" -eq 1
finish

start identity_dev_ok "server identity: dev deploy succeeds"
new_root dir; echo "APP_URL=https://api-dev.petprep.si" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" --expect-domain=api-dev.petprep.si sha-v2
check "exit 0" rc_is 0
check "new code on disk" eq "$(code_on_disk)" v2
finish

start identity_explicit "server identity: explicit PETPREP_* in .env win (kept, not duplicated)"
new_root dir; printf 'APP_URL=https://api-dev.petprep.si\nPETPREP_API_DOMAIN=api-dev.petprep.si\nPETPREP_CADDY_SITES=production\n' >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "explicit site set used" out_has "Caddy sites production"
check "not appended twice" test "$(grep -c '^PETPREP_CADDY_SITES=' "$ROOT/repo/backend/.env")" -eq 1
finish

start identity_bad_sites "server identity: unknown PETPREP_CADDY_SITES → preflight error, no docker"
new_root dir; echo "PETPREP_CADDY_SITES=website" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "PETPREP_CADDY_SITES must be 'production' or 'api-only'"
check "no docker/git/curl call at all" test ! -s "$CALL_LOG"
finish

start identity_bad_domain "server identity: APP_URL host with odd characters → preflight error"
new_root dir; echo 'APP_URL=https://API_Dev.petprep.si' >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "is not a plain lower-case host name"
check "no docker/git/curl call at all" test ! -s "$CALL_LOG"
finish

start expect_mismatch "--expect-domain api-dev.petprep.si on the PRODUCTION server (wrong host) → refuse, nothing changed"
new_root dir; echo "APP_URL=https://api.petprep.si" >> "$ROOT/.env"
run_deploy -- --from "$ROOT/incoming" --expect-domain api-dev.petprep.si sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "but the deploy expects 'api-dev.petprep.si' — wrong server?"
check "no docker/git/curl call at all" test ! -s "$CALL_LOG"
check "code untouched" eq "$(code_on_disk)" v1
finish

start expect_missing_arg "--expect-domain without a value → usage error"
new_root dir; run_deploy -- --from "$ROOT/incoming" --expect-domain
check "exit 2" rc_is 2
finish

start health_override "DEPLOY_HEALTH_HOST / empty DEPLOY_HEALTH_IP_HOST override the derived values"
new_root dir; run_deploy CURL_STATUS=502 DEPLOY_HEALTH_HOST=api.example.test DEPLOY_HEALTH_IP_HOST= -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "health on the override host" called 'curl .*https://api.example.test/up'
check "empty IP host = no fallback" not_called 'Host: 138.199.172.97'
finish

# ---- D19: no pending migrations → no maintenance; reverb kept when unchanged ----------
start nomaint "no pending migrations → no artisan down/up, everything else as before"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "pending check with the NEW image, no deps" called 'PETPREP_IMAGE_TAG=next .*run --rm --no-deps -T app php artisan migrate:status --pending=3 \[code=v1\]'
check "pending check after the smoke test" before 'artisan optimize' 'migrate:status'
check "pending check before the backup" test "$(grep -n 'Checking for pending migrations' "$ROOT/out.log" | cut -d: -f1)" -lt "$(grep -n 'Creating pre-deployment database backup' "$ROOT/out.log" | cut -d: -f1)"
check "says so" out_has "No pending migrations — deploying WITHOUT maintenance mode"
check "never artisan down" not_called 'artisan down'
check "backup still runs" out_has "backup (v2)"
check "images promoted" called 'tag petprep-app:next petprep-app:production \[code=v2\]'
check "migrate --force still runs (no-op guard)" called 'migrate --force \[code=v2\]'
check "workers recreated first" before 'up -d --no-deps queue queue-broadcasts scheduler' 'up -d --remove-orphans app'
check "workers recreated after migrate" before 'migrate --force' 'up -d --no-deps queue queue-broadcasts scheduler'
check "app services recreated" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy \[code=v2\]'
check "php-fpm-ping before queue:restart" before 'php-fpm-ping' 'queue:restart'
check "one idempotent artisan up (lifts an old flag)" test "$(grep -c 'artisan up' "$CALL_LOG")" -eq 1
check "artisan up after queue:restart" before 'queue:restart' 'artisan up'
check "health check" called 'curl .*https://api.petprep.si/up'
check "new code on disk" eq "$(code_on_disk)" v2
check "no maintenance error text" not_out "STILL IN MAINTENANCE"
check "no env secret in output" no_secret
finish

start pending "pending migrations → classic maintenance flow"
new_root dir; run_deploy PENDING_RC=3 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "says so" out_has "Pending migrations — maintenance mode for the schema change"
check "down on the OLD code" called 'artisan down --retry=15 \[code=v1\]'
check "pending check before down" before 'migrate:status' 'artisan down'
check "down before migrate" before 'artisan down' 'migrate --force'
check "no separate worker-first recreate" not_called 'up -d --no-deps queue queue-broadcasts scheduler'
check "artisan up once at the end" test "$(grep -c 'artisan up' "$CALL_LOG")" -eq 1
finish

start pending_unknown "pending check fails (DB down / first deploy) → maintenance (fail safe)"
new_root dir; run_deploy PENDING_RC=1 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "could not tell whether migrations are pending (exit 1"
check "maintenance used" called 'artisan down --retry=15'
finish

start force_maint "DEPLOY_FORCE_MAINTENANCE=1 → maintenance, no pending check"
new_root dir; run_deploy PENDING_RC=0 DEPLOY_FORCE_MAINTENANCE=1 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "no pending check" not_called 'migrate:status'
check "maintenance used" called 'artisan down --retry=15'
finish

start nomaint_migrate_fail "no pending migrations, migrate fails anyway → revert, never went down"
new_root dir; run_deploy PENDING_RC=0 FAIL_MATCH='migrate --force' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "old code back" eq "$(code_on_disk)" v1
check "images restored" called 'tag petprep-app:previous petprep-app:production'
check "never artisan down" not_called 'artisan down'
check "no artisan up needed (not in maintenance)" not_called 'artisan up'
finish

start nomaint_backup_fail "no pending migrations, backup fails → abort before the switch, never went down"
new_root dir; printf '#!/usr/bin/env bash\nexit 3\n' > "$ROOT/incoming/scripts/backup-production-db.sh"
run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "code never switched" eq "$(code_on_disk)" v1
check "no promote" not_called ' tag '
check "never artisan down / up" not_called 'artisan (down|up)'
finish

start nomaint_post_fail "no pending migrations, health check fails → not reverted, exit != 0, no down"
new_root dir; run_deploy PENDING_RC=0 CURL_STATUS=502 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "new code stays" eq "$(code_on_disk)" v2
check "never artisan down" not_called 'artisan down'
finish

start nomaint_up_fails "no pending migrations, the idempotent artisan up fails → warning only"
new_root dir; run_deploy PENDING_RC=0 UP_FAILS=999 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "artisan up (no-op check) failed"
finish

start nomaint_git "git mode, no pending migrations → no maintenance"
new_root git; run_deploy PENDING_RC=0 -- "$V2_SHA"
check "exit 0" rc_is 0
check "pending check on the build-src image" called 'build-src/backend/compose.production.yaml --env-file .* run --rm --no-deps -T app php artisan migrate:status --pending=3'
check "never artisan down" not_called 'artisan down'
check "new code on disk" eq "$(code_on_disk)" v2
finish

start reverb_first "first D19 deploy (no fingerprints) → reverb + caddy recreated, fingerprints recorded"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "all services in the up -d" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy'
check "reverb fingerprint written" test -s "$ROOT/releases/reverb.fingerprint"
check "caddy fingerprint written" test -s "$ROOT/releases/caddy.fingerprint"
check "PHP base stage id resolved from the NEW tree" called 'buildx build -q --load --target base -f .*incoming/backend/docker/production/Dockerfile'
check "caddy base image id read" called 'image inspect --format \{\{.Id\}\} caddy:2.8-alpine'
finish

start reverb_kept "second deploy, only app code changed → reverb AND caddy kept"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
fp1="$(cat "$ROOT/releases/reverb.fingerprint")"; cfp1="$(cat "$ROOT/releases/caddy.fingerprint")"
echo "v3" > "$ROOT/incoming/backend/app.txt"; mkdir -p "$ROOT/incoming/backend/app/Http"; echo "x" > "$ROOT/incoming/backend/app/Http/C.php"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "exit 0" rc_is 0
check "says what is kept" out_has "unchanged, not restarted: reverb caddy"
check "up -d of app + workers only, --no-deps" called 'up -d --remove-orphans --no-deps app queue queue-broadcasts scheduler \[code=v3\]'
check "reverb not in any up -d" not_called 'up -d .*reverb'
check "caddy not in any up -d" not_called 'up -d .*caddy'
check "fingerprints unchanged" eq "$(cat "$ROOT/releases/reverb.fingerprint") $(cat "$ROOT/releases/caddy.fingerprint")" "$fp1 $cfp1"
finish

start reverb_config "reverb inputs changed (backend/config) → reverb recreated, caddy kept"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
fp1="$(cat "$ROOT/releases/reverb.fingerprint")"
mkdir -p "$ROOT/incoming/backend/config"; echo "<?php return ['x' => 2];" > "$ROOT/incoming/backend/config/reverb.php"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "exit 0" rc_is 0
check "reverb recreated, caddy kept" called 'up -d --remove-orphans --no-deps app queue queue-broadcasts scheduler reverb \[code=v2\]'
check "fingerprint changed" test "$(cat "$ROOT/releases/reverb.fingerprint")" != "$fp1"
finish

start reverb_listeners "app/Listeners or routes/ changed → reverb recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
mkdir -p "$ROOT/incoming/backend/app/Listeners" "$ROOT/incoming/backend/routes"
echo "l" > "$ROOT/incoming/backend/app/Listeners/L.php"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "listener → reverb recreated" called 'up -d .*scheduler reverb'
echo "r" > "$ROOT/incoming/backend/routes/channels.php"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v4
check "routes → reverb recreated" called 'up -d .*scheduler reverb'
finish

start reverb_base "PHP base image changed (same Dockerfile) → reverb recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 PHP_BASE_ID=sha256:phpbase2 -- --from "$ROOT/incoming" sha-v2
check "reverb recreated" called 'up -d .*scheduler reverb'
check "caddy kept" not_called 'up -d .*caddy'
finish

start reverb_base_unknown "PHP base id cannot be resolved → reverb recreated (fail safe), deploy succeeds"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 BASE_BUILD_FAIL=1 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "warns" out_has "could not resolve the PHP base image id"
check "reverb recreated" called 'up -d .*reverb'
finish

start reverb_env "server .env changed → reverb and caddy recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
echo "REVERB_APP_SECRET=${SECRET}-rotated" >> "$ROOT/.env"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "all recreated" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy'
check "no env secret in output" no_secret
finish

start reverb_composer "composer.lock changed → reverb and caddy recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
echo '{"packages":[1]}' > "$ROOT/incoming/backend/composer.lock"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "all recreated" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy'
finish

start caddy_public "public/ changed → caddy recreated, reverb kept"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
mkdir -p "$ROOT/incoming/backend/public/css"; echo "a{}" > "$ROOT/incoming/backend/public/css/app.css"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "caddy recreated, reverb kept" called 'up -d --remove-orphans --no-deps app queue queue-broadcasts scheduler caddy \[code=v2\]'
check "reverb not in any up -d" not_called 'up -d .*reverb'
finish

start caddy_file_changed "Caddyfile changed → caddy recreated (force) even with matching other inputs"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
echo "caddy-config-NEW" > "$ROOT/incoming/deployment/Caddyfile"
: > "$CALL_LOG"; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v3
check "validated" called 'caddy validate'
check "caddy in the up -d" called 'up -d .*caddy \[code=v2\]'
check "and force-recreated" called 'up -d --force-recreate --no-deps caddy'
finish

start caddy_down "caddy not running → recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 CADDY_RUNNING=0 -- --from "$ROOT/incoming" sha-v2
check "caddy recreated" called 'up -d .*caddy'
finish

start caddy_forced "DEPLOY_RECREATE_CADDY=1 → recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 DEPLOY_RECREATE_CADDY=1 -- --from "$ROOT/incoming" sha-v2
check "caddy recreated" called 'up -d .*caddy'
check "reverb kept" not_called 'up -d .*reverb'
finish

start reverb_down "reverb not running → recreated even with the same fingerprint"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 REVERB_RUNNING=0 -- --from "$ROOT/incoming" sha-v2
check "reverb recreated" called 'up -d .*reverb'
finish

start reverb_forced "DEPLOY_RECREATE_REVERB=1 → recreated"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 DEPLOY_RECREATE_REVERB=1 -- --from "$ROOT/incoming" sha-v2
check "reverb recreated" called 'up -d .*reverb'
finish

start reverb_failed_deploy "a failed deploy never records a fingerprint"
new_root dir; run_deploy PENDING_RC=0 CURL_STATUS=502 -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "no reverb fingerprint" test ! -e "$ROOT/releases/reverb.fingerprint"
check "no caddy fingerprint" test ! -e "$ROOT/releases/caddy.fingerprint"
finish

start reverb_kept_post_fail "services kept, post-migration failure → trap brings every service up"
new_root dir; run_deploy PENDING_RC=0 -- --from "$ROOT/incoming" sha-v2
: > "$CALL_LOG"; run_deploy PENDING_RC=0 FAIL_MATCH='php-fpm-ping' DEPLOY_APP_WAIT_TRIES=1 -- --from "$ROOT/incoming" sha-v3
check "exit != 0" rc_nonzero
check "trap brings every service up" called 'up -d --remove-orphans app reverb queue queue-broadcasts scheduler caddy'
finish

start nomaint_premig_revert "no maintenance, failure before containers changed (migrate) → revert without worker restart"
new_root dir; run_deploy PENDING_RC=0 FAIL_MATCH='migrate --force' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "old code back" eq "$(code_on_disk)" v1
check "no worker restart (FPM containers untouched, still the old release)" not_called 'restart reverb queue'
check "no up -d of app services" not_called 'up -d --remove-orphans'
finish

start maint_premig_revert "with maintenance, migrate fails → workers still restarted (unchanged behaviour)"
new_root dir; run_deploy PENDING_RC=3 FAIL_MATCH='migrate --force' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "workers restarted" called 'restart reverb queue queue-broadcasts scheduler \[code=v1\]'
finish

start ops-lock "a running game-data reset holds the ops lock (D17)"
new_root dir
flock "$ROOT/.ops.lock" sleep 30 &
HOLDER=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do [ -f "$ROOT/.ops.lock" ] && break; sleep 0.1; done
sleep 0.3
run_deploy DEPLOY_LOCK_WAIT=1 -- --from "$ROOT/incoming" sha-v2
kill "$HOLDER" 2>/dev/null || true; wait "$HOLDER" 2>/dev/null || true
check "exit != 0" rc_nonzero
check "explains the lock" out_has "is still held after 1 s"
check "no docker call at all" eq "$(wc -l < "$CALL_LOG" | tr -d ' ')" 0
check "code untouched" eq "$(code_on_disk)" v1
finish

start ops-lock-free "the lock is free again after a reset"
new_root dir; run_deploy DEPLOY_LOCK_WAIT=1 -- --from "$ROOT/incoming" sha-v2
check "exit 0" rc_is 0
check "lock acquired" out_has "Ops lock acquired."
finish

echo
echo "passed: ${PASS}, failed: ${FAIL}"
[ "$FAIL" -eq 0 ]
