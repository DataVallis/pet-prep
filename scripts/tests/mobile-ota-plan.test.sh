#!/usr/bin/env bash
# Harness for scripts/mobile-ota-plan.sh — the production paths (no GitHub, no network):
#   1. workflow_run after a CI "Run workflow" (D18): did the CI run's "Deploy to Hetzner
#      Production" job succeed? (re-read a few times since 2026-10-23 — one transient answer
#      skipped a production OTA)
#   2. workflow_dispatch from CI & Deploy's job "Mobile OTA (after the production deploy)"
#      with deploy_run_id (D33, 2026-11-04 — the daily deploy, started with GITHUB_TOKEN,
#      never created a workflow_run event, so its OTA never ran): run identity, HEAD = the
#      deployed commit, deploy job green, no newer production deploy;
#   3. idempotency (D33): the release recorded on the CURRENT main (docs/research/app-release.json)
#      already contains HEAD → nothing, so a manual deploy's two OTA runs publish once.
#
# A `gh` shim runs the script's REAL jq filters on fixtures:
#   …/actions/runs/<id>/jobs  → $FIX/jobs-<id>.json if present, else $FIX/jobs.<k>.json
#                               (k = call number, the highest existing file repeats)
#   …/actions/runs/<id>       → $FIX/run.json
#   …/actions/workflows/…/runs → $FIX/runs.json
# "origin" is a bare repository per scenario (OTA_PLAN_REMOTE) whose main carries the release
# record the way the OTA bot commits it.
#
# Run: bash scripts/tests/mobile-ota-plan.test.sh   (needs bash, jq, git)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../mobile-ota-plan.sh"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
REPO=DataVallis/pet-prep

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
  */actions/runs/*/jobs*)
    id="${path#*/actions/runs/}"; id="${id%%/*}"
    if [ -f "$FIX/jobs-$id.json" ]; then jq -r "$filter" "$FIX/jobs-$id.json"; exit 0; fi
    n=$(( $(cat "$FIX/calls" 2>/dev/null || echo 0) + 1 ))
    echo "$n" > "$FIX/calls"
    k=$n
    while [ "$k" -gt 1 ] && [ ! -f "$FIX/jobs.$k.json" ]; do k=$((k - 1)); done
    jq -r "$filter" "$FIX/jobs.$k.json" ;;
  */actions/workflows/*/runs*) jq -r "$filter" "$FIX/runs.json" ;;
  */actions/runs/*) jq -r "$filter" "$FIX/run.json" ;;
  *) echo "gh shim: unexpected path $path" >&2; exit 64 ;;
esac
SHIM
chmod +x "$TMP/bin/gh"

# --- git: the checkout (HEAD = C2, the deployed commit) and one bare "origin" per scenario ---
REPO_DIR="$TMP/repo"
g() { git -C "$REPO_DIR" -c user.name=t -c user.email=t@t "$@"; }
git init -q -b main "$REPO_DIR"
g commit -q --allow-empty -m init
C1="$(g rev-parse HEAD)"
mkdir -p "$REPO_DIR/mobile"; echo one > "$REPO_DIR/mobile/app.js"
g add mobile; g commit -q -m "feat(mobile): C2"
C2="$(g rev-parse HEAD)"

# record_on BRANCH COMMIT: the OTA bot's "chore(release): record OTA …" commit on BRANCH.
record_on() {
  g checkout -q "$1"
  mkdir -p "$REPO_DIR/docs/research"
  printf '{"kind":"ota","commit":"%s"}\n' "$2" > "$REPO_DIR/docs/research/app-release.json"
  g add docs; g commit -q -m "chore(release): record OTA ${2:0:7} [skip ci]"
}
# remote NAME REF: bare repository whose main is REF.
remote() {
  git init -q --bare "$TMP/$1.git"
  g -c push.negotiate=false push -q "$TMP/$1.git" "$2:refs/heads/main"
}

remote none "$C2"                                     # main = C2, no release recorded yet
g checkout -q -b same "$C2";  record_on same "$C2";   remote same same     # C2 already published
g checkout -q -b older "$C2"; record_on older "$C1";  remote older older   # last release C1, mobile/ changed since
g checkout -q -b newer "$C2"; echo two > "$REPO_DIR/mobile/app.js"; g commit -q -am "feat(mobile): C3"
C3="$(g rev-parse HEAD)"; record_on newer "$C3"; remote newer newer        # a later deploy C3 is released
g checkout -q --detach "$C2"
[ "$(g rev-parse HEAD)" = "$C2" ] || { echo "harness setup: HEAD is not C2" >&2; exit 2; }

jobs_json() { # $1 = deploy job conclusion or "absent"
  if [ "$1" = absent ]; then
    echo '{"jobs":[{"name":"CI OK","conclusion":"success"}]}'
  else
    echo '{"jobs":[{"name":"CI OK","conclusion":"success"},{"name":"Deploy to Hetzner Production","conclusion":"'"$1"'"}]}'
  fi
}
run_json() { # path event branch repo sha
  printf '{"id":77,"path":"%s","event":"%s","head_branch":"%s","head_repository":{"full_name":"%s"},"head_sha":"%s","created_at":"2026-11-04T09:28:52Z"}\n' "$@"
}
runs_json() { # "id created_at" … (newest first, like the API)
  local items="" id at
  for r in "$@"; do
    read -r id at <<< "$r"
    items="${items:+$items,}{\"id\":$id,\"event\":\"workflow_dispatch\",\"created_at\":\"$at\",\"head_repository\":{\"full_name\":\"$REPO\"}}"
  done
  echo "{\"workflow_runs\":[${items}]}"
}

fails=0
check() { # name, want "mobile channel", out file, [want_calls, calls file]
  local got
  got="$(grep '^mobile=' "$3" | cut -d= -f2) $(grep '^channel=' "$3" | cut -d= -f2)"
  if [ "$got" != "$2" ]; then
    echo "FAIL $1: got '$got', want '$2'"; fails=$((fails + 1)); return
  fi
  if [ "$(grep '^sha=' "$3" | cut -d= -f2)" != "$C2" ]; then
    echo "FAIL $1: sha output is not the checked-out commit"; fails=$((fails + 1)); return
  fi
  if [ -n "${4:-}" ] && [ "$(cat "$5")" != "$4" ]; then
    echo "FAIL $1: $(cat "$5") jobs reads, want $4"; fails=$((fails + 1)); return
  fi
  echo "ok   $1"
}

# 1. workflow_run path ---------------------------------------------------------------------
run_case() { # name, expected "mobile channel", expected calls, remote, then fixture states for call 1..n
  local name="$1" want="$2" want_calls="$3" remote="$4"; shift 4
  local fix="$TMP/fix-$name"; mkdir -p "$fix"
  local i=1
  for state in "$@"; do jobs_json "$state" > "$fix/jobs.$i.json"; i=$((i + 1)); done
  (cd "$REPO_DIR" && FIX="$fix" PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_run WR_EVENT=workflow_dispatch \
    WR_CONCLUSION=success WR_RUN_ID=42 REPO="$REPO" OTA_PLAN_JOB_WAIT=0 OTA_PLAN_JOB_TRIES=3 \
    OTA_PLAN_REMOTE="$TMP/$remote.git" GITHUB_OUTPUT="$fix/out" bash "$SCRIPT" >/dev/null)
  check "$name" "$want" "$fix/out" "$want_calls" "$fix/calls"
}

run_case deployed-first-read   "true production"  1 none  success
run_case deployed-third-read   "true production"  3 none  absent absent success
run_case never-deployed        "false "           3 none  absent
run_case deploy-job-failed     "false "           3 none  failure
run_case wr-already-released   "false production" 1 same  success   # D33 dedupe
run_case wr-release-older      "true production"  1 older success
run_case wr-release-newer      "false production" 1 newer success

# A failing jobs API call never publishes and fails the plan.
fix="$TMP/fix-api-error"; mkdir -p "$fix"
if (cd "$REPO_DIR" && FIX="$fix" GH_FAIL=1 PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_run WR_EVENT=workflow_dispatch \
    WR_CONCLUSION=success WR_RUN_ID=42 REPO="$REPO" OTA_PLAN_JOB_WAIT=0 OTA_PLAN_REMOTE="$TMP/none.git" \
    GITHUB_OUTPUT="$fix/out" bash "$SCRIPT" >/dev/null 2>&1); then
  echo "FAIL api-error: plan succeeded"; fails=$((fails + 1))
else
  echo "ok   api-error"
fi

# 2. dispatch from CI & Deploy (D33) ----------------------------------------------------------
# dispatch_case name want remote channel run_json_args(5) deploy_state runs… — want "fail" = must exit ≠ 0.
SELF="77 2026-11-04T09:28:52Z"
dispatch_case() {
  local name="$1" want="$2" remote="$3" channel="$4"
  local path="$5" event="$6" branch="$7" repo="$8" sha="$9" state="${10}"; shift 10
  local fix="$TMP/fix-$name"; mkdir -p "$fix"
  run_json "$path" "$event" "$branch" "$repo" "$sha" > "$fix/run.json"
  jobs_json "$state" > "$fix/jobs-77.json"
  runs_json "$@" > "$fix/runs.json"
  for f in "$TMP"/jobs-extra-*.json; do [ -f "$f" ] && cp "$f" "$fix/${f##*/jobs-extra-}"; done
  local rc=0
  (cd "$REPO_DIR" && FIX="$fix" PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_dispatch INPUT_CHANNEL="$channel" \
    DEPLOY_RUN_ID=77 DRY_RUN=false REPO="$REPO" OTA_PLAN_JOB_WAIT=0 OTA_PLAN_JOB_TRIES=2 \
    OTA_PLAN_REMOTE="$TMP/$remote.git" GITHUB_OUTPUT="$fix/out" bash "$SCRIPT" >"$fix/log" 2>&1) || rc=$?
  if [ "$want" = fail ]; then
    if [ "$rc" -ne 0 ] && ! grep -q '^mobile=true' "$fix/out" 2>/dev/null; then
      echo "ok   $name"
    else
      echo "FAIL $name: plan exited $rc, want a refusal"; fails=$((fails + 1))
    fi
    return
  fi
  if [ "$rc" -ne 0 ]; then
    echo "FAIL $name: plan exited $rc: $(grep '::error' "$fix/log" || true)"; fails=$((fails + 1)); return
  fi
  check "$name" "$want" "$fix/out"
}
WF=.github/workflows/deploy-production.yml
ok_run=("$WF" workflow_dispatch main "$REPO")

# Extra jobs fixtures for the other deploy runs (copied into every case's fixture dir).
jobs_json success > "$TMP/jobs-extra-jobs-99.json"   # newer run 99 deployed production
jobs_json absent  > "$TMP/jobs-extra-jobs-98.json"   # newer run 98 was target none
jobs_json success > "$TMP/jobs-extra-jobs-55.json"   # older run 55 deployed production

dispatch_case dispatch-publish          "true production"  none  production "${ok_run[@]}" "$C2" success "$SELF" "55 2026-11-03T08:00:00Z"
dispatch_case dispatch-already-released "false production" same  production "${ok_run[@]}" "$C2" success "$SELF"
dispatch_case dispatch-release-older    "true production"  older production "${ok_run[@]}" "$C2" success "$SELF"
dispatch_case dispatch-release-newer    "false production" newer production "${ok_run[@]}" "$C2" success "$SELF"
dispatch_case dispatch-newer-deploy     "false production" none  production "${ok_run[@]}" "$C2" success "99 2026-11-04T10:00:00Z" "$SELF"
dispatch_case dispatch-newer-no-deploy  "true production"  none  production "${ok_run[@]}" "$C2" success "98 2026-11-04T10:00:00Z" "$SELF"
dispatch_case dispatch-sha-mismatch     fail none production "${ok_run[@]}" "$C1" success "$SELF"
dispatch_case dispatch-wrong-workflow   fail none production .github/workflows/mobile-ota.yml workflow_dispatch main "$REPO" "$C2" success "$SELF"
dispatch_case dispatch-push-run         fail none production "$WF" push main "$REPO" "$C2" success "$SELF"
dispatch_case dispatch-other-branch     fail none production "$WF" workflow_dispatch feature "$REPO" "$C2" success "$SELF"
dispatch_case dispatch-fork             fail none production "$WF" workflow_dispatch main someone/pet-prep "$C2" success "$SELF"
dispatch_case dispatch-channel-preview  fail none preview    "${ok_run[@]}" "$C2" success "$SELF"
dispatch_case dispatch-deploy-failed    fail none production "${ok_run[@]}" "$C2" failure "$SELF"

# A failing API call on the dispatch path never publishes.
fix="$TMP/fix-dispatch-api-error"; mkdir -p "$fix"
if (cd "$REPO_DIR" && FIX="$fix" GH_FAIL=1 PATH="$TMP/bin:$PATH" EVENT_NAME=workflow_dispatch INPUT_CHANNEL=production \
    DEPLOY_RUN_ID=77 REPO="$REPO" OTA_PLAN_JOB_WAIT=0 OTA_PLAN_REMOTE="$TMP/none.git" \
    GITHUB_OUTPUT="$fix/out" bash "$SCRIPT" >/dev/null 2>&1); then
  echo "FAIL dispatch-api-error: plan succeeded"; fails=$((fails + 1))
else
  echo "ok   dispatch-api-error"
fi

if [ "$fails" -ne 0 ]; then echo "$fails case(s) failed"; exit 1; fi
echo "all mobile-ota-plan cases passed"
