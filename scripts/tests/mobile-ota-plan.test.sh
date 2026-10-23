#!/usr/bin/env bash
# Harness for scripts/mobile-ota-plan.sh — the workflow_run path after a CI "Run workflow"
# (D18): did the CI run's "Deploy to Hetzner Production" job succeed? No GitHub, no network.
#
# A `gh` shim answers `gh api …/runs/<id>/jobs` with $FIX/jobs.<k>.json (k = call number,
# the highest existing file repeats) by running the script's REAL jq filter.
# Since 2026-10-23 the plan re-reads the jobs a few times before deciding "without a
# production deploy" (one transient answer skipped a production OTA).
#
# Run: bash scripts/tests/mobile-ota-plan.test.sh   (needs bash, jq, git)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../mobile-ota-plan.sh"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/bin"
cat > "$TMP/bin/gh" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
[ "$1" = api ] || { echo "gh shim: unexpected $*" >&2; exit 64; }
path="$2"; shift 2
filter="."
while [ $# -gt 0 ]; do
  case "$1" in --jq) filter="$2"; shift 2 ;; *) shift ;; esac
done
[ -n "${GH_FAIL:-}" ] && exit 1
case "$path" in
  */jobs*) ;;
  *) echo "gh shim: unexpected path $path" >&2; exit 64 ;;
esac
n=$(( $(cat "$FIX/calls" 2>/dev/null || echo 0) + 1 ))
echo "$n" > "$FIX/calls"
k=$n
while [ "$k" -gt 1 ] && [ ! -f "$FIX/jobs.$k.json" ]; do k=$((k - 1)); done
jq -r "$filter" "$FIX/jobs.$k.json"
SHIM
chmod +x "$TMP/bin/gh"

# The plan diffs mobile/ in a git checkout; a fresh repo without app-release.json = "unknown → check".
REPO_DIR="$TMP/repo"
git init -q "$REPO_DIR"
git -C "$REPO_DIR" -c user.name=t -c user.email=t@t commit -q --allow-empty -m init

jobs_json() { # $1 = deploy job conclusion or "absent"
  if [ "$1" = absent ]; then
    echo '{"jobs":[{"name":"CI OK","conclusion":"success"}]}'
  else
    echo '{"jobs":[{"name":"CI OK","conclusion":"success"},{"name":"Deploy to Hetzner Production","conclusion":"'"$1"'"}]}'
  fi
}

fails=0
run_case() { # name, expected "mobile channel", expected calls, then fixture states for call 1..n
  local name="$1" want="$2" want_calls="$3"; shift 3
  local fix="$TMP/fix-$name"; mkdir -p "$fix"
  local i=1
  for state in "$@"; do jobs_json "$state" > "$fix/jobs.$i.json"; i=$((i + 1)); done
  local out="$fix/out"
  (cd "$REPO_DIR" && FIX="$fix" PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_run WR_EVENT=workflow_dispatch \
    WR_CONCLUSION=success WR_RUN_ID=42 REPO=DataVallis/pet-prep OTA_PLAN_JOB_WAIT=0 OTA_PLAN_JOB_TRIES=3 \
    GITHUB_OUTPUT="$out" bash "$SCRIPT" >/dev/null)
  local got calls
  got="$(grep '^mobile=' "$out" | cut -d= -f2) $(grep '^channel=' "$out" | cut -d= -f2)"
  calls="$(cat "$fix/calls")"
  if [ "$got" = "$want" ] && [ "$calls" = "$want_calls" ]; then
    echo "ok   $name"
  else
    echo "FAIL $name: got '$got' after $calls calls, want '$want' after $want_calls"; fails=$((fails + 1))
  fi
}

run_case deployed-first-read   "true production" 1 success
run_case deployed-third-read   "true production" 3 absent absent success
run_case never-deployed        "false "          3 absent
run_case deploy-job-failed     "false "          3 failure

# A failing jobs API call never publishes and fails the plan.
fix="$TMP/fix-api-error"; mkdir -p "$fix"
if (cd "$REPO_DIR" && FIX="$fix" GH_FAIL=1 PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_run WR_EVENT=workflow_dispatch \
    WR_CONCLUSION=success WR_RUN_ID=42 REPO=DataVallis/pet-prep OTA_PLAN_JOB_WAIT=0 \
    GITHUB_OUTPUT="$fix/out" bash "$SCRIPT" >/dev/null 2>&1); then
  echo "FAIL api-error: plan succeeded"; fails=$((fails + 1))
else
  echo "ok   api-error"
fi

[ "$fails" -eq 0 ] && echo "all mobile-ota-plan cases passed" || { echo "$fails case(s) failed"; exit 1; }
