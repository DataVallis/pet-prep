#!/usr/bin/env bash
# Harness for scripts/ci-install-ffmpeg.sh (CI ffmpeg without apt, D24) — no network, no real ffmpeg.
#
# Fake ffmpeg / ffprobe: a "good" pair writes the output file / prints a duration, a "bad"
# pair exits 1 (like a half-installed apt package). A curl shim serves fixture archives:
# $FIX/url-<n>.<k> is what the k-th download of URL number n returns — a .tar.xz file to
# copy, or the word FAIL (curl error). The last existing k repeats. sleep is recorded.
#
# Run: bash scripts/tests/ci-install-ffmpeg.test.sh   (needs bash, tar, xz)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../ci-install-ffmpeg.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --- fake binaries and archives ------------------------------------------------------
mkdir -p "$TMP/good" "$TMP/bad" "$TMP/none"
cat > "$TMP/good/ffmpeg" <<'BIN'
#!/usr/bin/env bash
case "$*" in *-version*) echo "ffmpeg version fake-good"; exit 0 ;; esac
for last; do :; done
echo clip > "$last"
BIN
cat > "$TMP/good/ffprobe" <<'BIN'
#!/usr/bin/env bash
case "$*" in *-version*) echo "ffprobe version fake-good"; exit 0 ;; esac
for last; do :; done
[ -s "$last" ] && echo "1.000000"
BIN
printf '#!/usr/bin/env bash\nexit 1\n' > "$TMP/bad/ffmpeg"
printf '#!/usr/bin/env bash\nexit 1\n' > "$TMP/bad/ffprobe"
chmod +x "$TMP"/good/* "$TMP"/bad/*

# archive NAME KIND LAYOUT: KIND good|bad|empty; LAYOUT btbn (dir/bin/ffmpeg) or jvs (dir/ffmpeg)
archive() {
  local d="$TMP/src-$1" sub
  rm -rf "$d"; mkdir -p "$d"
  if [ "$3" = btbn ]; then sub="$d/ffmpeg-master-latest-linux64-gpl/bin"; else sub="$d/ffmpeg-7.1-amd64-static"; fi
  mkdir -p "$sub"
  if [ "$2" != empty ]; then cp "$TMP/$2/ffmpeg" "$TMP/$2/ffprobe" "$sub/"; else echo readme > "$sub/README"; fi
  tar -cJf "$TMP/$1.tar.xz" -C "$d" .
}
archive good-btbn good btbn
archive good-jvs good jvs
archive bad-btbn bad btbn
archive empty-btbn empty btbn
echo "not an archive" > "$TMP/garbage.tar.xz"

mkdir -p "$TMP/bin"
cat > "$TMP/bin/curl" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
out="" url=""
while [ $# -gt 0 ]; do
  case "$1" in -o) out="$2"; shift 2 ;; --connect-timeout|--max-time) shift 2 ;; -*) shift ;; *) url="$1"; shift ;; esac
done
n="${url##*/}"; n="${n%%.*}"
c=$(( $(cat "$FIX/count-$n" 2>/dev/null || echo 0) + 1 )); echo "$c" > "$FIX/count-$n"
echo "$url" >> "$FIX/curl.log"
k="$c"; while [ "$k" -gt 0 ] && [ ! -f "$FIX/url-$n.$k" ]; do k=$((k - 1)); done
[ "$k" -gt 0 ] || { echo "curl shim: no fixture for $url" >&2; exit 22; }
src="$(cat "$FIX/url-$n.$k")"
[ "$src" != FAIL ] || { echo "curl: (28) timed out" >&2; exit 28; }
cp "$src" "$out"
SHIM
cat > "$TMP/bin/sleep" <<'SHIM'
#!/usr/bin/env bash
echo "$1" >> "$FIX/sleep.log"
SHIM
chmod +x "$TMP/bin/curl" "$TMP/bin/sleep"

pass=0 fail=0 n=0
check() { if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "  FAIL: $1 — expected '$2', got '$3'"; sed 's/^/    /' "$FIX/out"; fi; }
contains() { if grep -qF -- "$3" "$2"; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "  FAIL: $1 — '$3' not in $(basename "$2")"; sed 's/^/    /' "$2"; fi; }

# new_case: fresh fixtures dir $FIX; serve URL n's k-th download with: serve n k FILE|FAIL
new_case() { n=$((n + 1)); FIX="$TMP/case$n"; mkdir -p "$FIX/cache"; : > "$FIX/curl.log"; : > "$FIX/sleep.log"; }
serve() { echo "$3" > "$FIX/url-$1.$2"; }
# run SYSTEM_DIR [ARCH]: runs the script; sets o_rc, o_source, o_path, o_curls, o_sleeps
run() {
  local rc=0
  : > "$FIX/gh_output"; : > "$FIX/gh_path"
  export FIX
  CI_FFMPEG_CURL="$TMP/bin/curl" CI_FFMPEG_SLEEP="$TMP/bin/sleep" \
    CI_FFMPEG_SYSTEM_DIR="$1" CI_FFMPEG_ARCH="${2:-x86_64}" FFMPEG_CACHE_DIR="$FIX/cache" \
    FFMPEG_URLS="https://example.test/1.tar.xz https://example.test/2.tar.xz" \
    FFMPEG_ATTEMPTS=3 FFMPEG_BACKOFF_SECONDS=10 \
    GITHUB_OUTPUT="$FIX/gh_output" GITHUB_PATH="$FIX/gh_path" \
    bash "$SCRIPT" > "$FIX/out" 2>&1 || rc=$?
  o_rc=$rc
  o_source="$(sed -n 's/^source=//p' "$FIX/gh_output")"
  o_path="$(cat "$FIX/gh_path")"
  o_curls="$(wc -l < "$FIX/curl.log" | tr -d ' ')"
  o_sleeps="$(tr '\n' ' ' < "$FIX/sleep.log")"
}
CACHE_BIN() { echo "$FIX/cache/bin"; }

echo "== 1. preinstalled =="
new_case; run "$TMP/good"
check "working runner ffmpeg: used" "0 system  0" "$o_rc $o_source $o_path $o_curls"
new_case; serve 1 1 "$TMP/good-btbn.tar.xz"; run "$TMP/bad"
check "broken runner ffmpeg (half apt install): static build on PATH first" "0 download $(CACHE_BIN)" "$o_rc $o_source $o_path"
contains "broken runner ffmpeg: says why" "$FIX/out" "fails the smoke test"

echo "== 2. cache =="
new_case; mkdir -p "$FIX/cache/bin"; cp "$TMP"/good/* "$FIX/cache/bin/"; run "$TMP/none"
check "cache hit: no download" "0 cache $(CACHE_BIN) 0" "$o_rc $o_source $o_path $o_curls"
new_case; mkdir -p "$FIX/cache/bin"; cp "$TMP"/bad/* "$FIX/cache/bin/"; serve 1 1 "$TMP/good-btbn.tar.xz"; run "$TMP/none"
check "broken cache: re-downloaded" "0 download $(CACHE_BIN) 1" "$o_rc $o_source $o_path $o_curls"
check "broken cache: replaced by the good build" "ffmpeg version fake-good" "$("$FIX/cache/bin/ffmpeg" -version)"

echo "== 3. download =="
new_case; serve 1 1 "$TMP/good-btbn.tar.xz"; run "$TMP/none"
check "BtbN layout (dir/bin/ffmpeg)" "0 download $(CACHE_BIN) 1" "$o_rc $o_source $o_path $o_curls"
check "binaries are executable" "yes" "$([ -x "$FIX/cache/bin/ffmpeg" ] && [ -x "$FIX/cache/bin/ffprobe" ] && echo yes)"
new_case; serve 1 1 FAIL; serve 2 1 "$TMP/good-jvs.tar.xz"; run "$TMP/none"
check "first source down (3 tries, backoff 10 20) → second source (flat layout)" "0 download 4 10 20 " "$o_rc $o_source $o_curls $o_sleeps"
new_case; serve 1 1 FAIL; serve 1 2 FAIL; serve 1 3 "$TMP/good-btbn.tar.xz"; run "$TMP/none"
check "retry with backoff succeeds on the 3rd try" "0 download 3 10 20 " "$o_rc $o_source $o_curls $o_sleeps"
new_case; serve 1 1 "$TMP/garbage.tar.xz"; serve 1 2 "$TMP/good-btbn.tar.xz"; run "$TMP/none"
check "truncated archive is retried" "0 download 2 10 " "$o_rc $o_source $o_curls $o_sleeps"
new_case; serve 1 1 "$TMP/bad-btbn.tar.xz"; serve 2 1 "$TMP/good-jvs.tar.xz"; run "$TMP/none"
check "build failing the smoke test → next source, no retry" "0 download 2" "$o_rc $o_source $o_curls"
new_case; serve 1 1 "$TMP/empty-btbn.tar.xz"; serve 2 1 "$TMP/good-jvs.tar.xz"; run "$TMP/none"
check "archive without binaries → next source" "0 download 2" "$o_rc $o_source $o_curls"

echo "== 4. give up cleanly =="
new_case; serve 1 1 FAIL; serve 2 1 FAIL; run "$TMP/none"
check "everything down: exit 0, nothing on PATH, 6 tries" "0 none  6" "$o_rc $o_source $o_path $o_curls"
contains "everything down: warning says the tests skip" "$FIX/out" "::warning title=ffmpeg::"
check "everything down: no half-filled cache" "no" "$([ -e "$FIX/cache/bin" ] && echo yes || echo no)"
new_case; serve 1 1 "$TMP/bad-btbn.tar.xz"; serve 2 1 "$TMP/bad-btbn.tar.xz"; run "$TMP/none"
check "only broken builds: nothing on PATH, nothing cached" "0 none  no" "$o_rc $o_source $o_path $([ -e "$FIX/cache/bin" ] && echo yes || echo no)"
new_case; run "$TMP/none" aarch64
check "non-x86_64 runner: no download, skip" "0 none 0" "$o_rc $o_source $o_curls"

echo "== workflow wiring =="
WF="${HERE}/../../.github/workflows/deploy-production.yml"
FIX="$TMP"; echo "" > "$TMP/out"
check "the backend job runs this script" "1" "$(grep -c 'run: bash scripts/ci-install-ffmpeg.sh' "$WF")"
check "no apt-get ffmpeg left in the workflow" "0" "$(grep -cE 'apt-get .*ffmpeg' "$WF" || true)"

echo "ci-install-ffmpeg: ${pass} passed, ${fail} failed"
[ "$fail" -eq 0 ]
