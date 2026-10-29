#!/usr/bin/env bash
# Harness for scripts/mobile-ota.sh — no EAS, no network: `eas` and `npx` are shims.
# Checks (David 2026-10-29) that `eas update` runs with EXPO_PUBLIC_GIT_COMMIT = the short
# HEAD commit (the app's »Različica aplikacije« line shows it), plus the existing outputs:
# a production OTA sets released/platforms, a dry run publishes nothing.
#
# Run: bash scripts/tests/mobile-ota.test.sh   (needs bash, git, node)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../mobile-ota.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/bin"
# eas: build:list → one finished build (the runtime matches); update → record the env it saw.
cat > "$TMP/bin/eas" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
  build:list) echo '[{"id":"b1"}]' ;;
  update)
    platform=""
    while [ $# -gt 0 ]; do case "$1" in --platform) platform="$2"; shift 2 ;; *) shift ;; esac; done
    echo "${platform} commit=${EXPO_PUBLIC_GIT_COMMIT:-<unset>} api=${EXPO_PUBLIC_API_URL:-<unset>}" >> "$LOG"
    ;;
  *) echo "eas shim: unexpected $*" >&2; exit 64 ;;
esac
SHIM
cat > "$TMP/bin/npx" <<'SHIM'
#!/usr/bin/env bash
[ "$1" = expo-updates ] || { echo "npx shim: unexpected $*" >&2; exit 64; }
echo '{"runtimeVersion":"rt-test"}'
SHIM
chmod +x "$TMP/bin/eas" "$TMP/bin/npx"

REPO_DIR="$TMP/repo"
mkdir -p "$REPO_DIR/mobile"
cat > "$REPO_DIR/mobile/eas.json" <<'JSON'
{ "build": { "production": { "channel": "production", "env": { "EXPO_PUBLIC_API_URL": "https://api.example.test" } },
             "preview": { "channel": "preview", "env": {} } } }
JSON
git init -q "$REPO_DIR"
git -C "$REPO_DIR" add -A
git -C "$REPO_DIR" -c user.name=t -c user.email=t@t commit -q -m init
SHA="$(git -C "$REPO_DIR" rev-parse --short=7 HEAD)"

fails=0
check() { # name, got, want
  if [ "$2" = "$3" ]; then echo "ok   $1"; else echo "FAIL $1: got '$2', want '$3'"; fails=$((fails + 1)); fi
}

# 1. Production OTA: both platforms published with the commit; released + platforms set.
LOG="$TMP/log-prod"; OUT="$TMP/out-prod"; : > "$LOG"; : > "$OUT"
(cd "$REPO_DIR" && LOG="$LOG" PATH="$TMP/bin:$PATH" GITHUB_OUTPUT="$OUT" GITHUB_STEP_SUMMARY=/dev/null \
  bash "$SCRIPT" "msg" >/dev/null)
check production-commit "$(cat "$LOG")" "ios commit=${SHA} api=https://api.example.test
android commit=${SHA} api=https://api.example.test"
check production-output "$(cat "$OUT")" "released=true
platforms=ios,android"

# 2. A stale EXPO_PUBLIC_GIT_COMMIT from the caller is replaced by HEAD.
LOG="$TMP/log-stale"; : > "$LOG"
(cd "$REPO_DIR" && LOG="$LOG" EXPO_PUBLIC_GIT_COMMIT=deadbee PATH="$TMP/bin:$PATH" GITHUB_OUTPUT=/dev/null \
  bash "$SCRIPT" "msg" --channel preview >/dev/null)
check stale-commit-replaced "$(head -1 "$LOG")" "ios commit=${SHA} api=<unset>"

# 3. Dry run: nothing published, no release output.
LOG="$TMP/log-dry"; OUT="$TMP/out-dry"; : > "$LOG"; : > "$OUT"
(cd "$REPO_DIR" && LOG="$LOG" PATH="$TMP/bin:$PATH" GITHUB_OUTPUT="$OUT" bash "$SCRIPT" "msg" --dry-run >/dev/null)
check dry-run-publishes-nothing "$(cat "$LOG")$(cat "$OUT")" ""

if [ "$fails" -ne 0 ]; then echo "$fails case(s) failed"; exit 1; fi
echo "all mobile-ota cases passed"
