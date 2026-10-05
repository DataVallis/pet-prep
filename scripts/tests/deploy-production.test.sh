#!/usr/bin/env bash
# Harness for scripts/deploy-production.sh — no server, no Docker, no secrets.
#
# Every case builds a throw-away PETPREP_ROOT in a temp dir (repo/ = release "v1",
# incoming/ = release "v2") and puts PATH shims in front of the real tools:
#   docker  logs each call (+ which release is on disk at that moment) and fails on
#           demand: FAIL_MATCH (ERE over the args), UP_FAILS (number of failing
#           `artisan up` invocations, exec and run --rm count separately),
#           DOWN_FAILS (same for `artisan down`), NO_PREV_IMAGES=1 (`image inspect`
#           fails: first deploy of the M4-05b runtime, no petprep-*:production yet).
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
    "image inspect "*) [ "${NO_PREV_IMAGES:-0}" = "1" ] && exit 1 ;;
    *"ps -q --status running postgres"*) echo "c0ffee" ;;
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
check "artisan down runs on the OLD code" called 'exec -T app php artisan down --retry=15 \[code=v1\]'
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

start build_fail "image build fails → nothing changed: no maintenance, no code switch, no promote"
new_root dir; run_deploy FAIL_MATCH='build app' -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "explains" out_has "image build failed — nothing was changed"
check "never entered maintenance" not_called 'artisan down'
check "no artisan up needed" not_called 'artisan up'
check "code untouched" eq "$(code_on_disk)" v1
check ".deployed-sha still sha-v1" sha_is sha-v1
check "no image promoted" not_called ' tag '
check "no migrate" not_called 'migrate'
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
new_root dir; printf '#!/usr/bin/env bash\nexit 3\n' > "$ROOT/scripts/backup-production-db.sh"
run_deploy -- --from "$ROOT/incoming" sha-v2
check "exit != 0" rc_nonzero
check "code never switched" eq "$(code_on_disk)" v1
check "images built but not promoted" not_called ' tag '
check "artisan up" called 'artisan up \[code=v1\]'
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
check "no migrate" not_called 'migrate'
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

echo
echo "passed: ${PASS}, failed: ${FAIL}"
[ "$FAIL" -eq 0 ]
