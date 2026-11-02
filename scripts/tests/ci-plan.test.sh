#!/usr/bin/env bash
# Harness for scripts/ci-plan.sh — no GitHub, no network.
#
# Every case builds a throw-away git repo (main history + a PR merge commit where
# needed) and puts a `gh` shim in front: `gh api <path> --jq <filter>` runs the REAL
# jq filter from ci-plan.sh over a JSON fixture picked by the path:
#   …/actions/workflows/<file>/runs?…   → $FIX/runs.json
#   …/actions/artifacts?name=<name>&…   → $FIX/artifacts-<name>.json (none → empty list)
#   …/actions/runs/<id>/jobs?…          → $FIX/jobs-<id>.json
#   …/actions/runs/<id>                 → $FIX/run-<id>.json
# GH_FAIL=1 makes every call fail; GH_FAIL_MATCH (ERE over the path) fails matching ones.
# A `git` shim passes through to the real git, except `git diff` when GIT_DIFF_FAIL=1
# (exit 128) or GIT_DIFF_EMPTY=1 (prints nothing, exit 0).
#
# Also covers scripts/ci-deploy-guard.sh against a local bare "origin", and (D18) the
# manual production routing: scripts/ci-deploy-target.sh, the deploy job's `if:` in the
# workflow, scripts/mobile-ota-plan.sh (same gh shim + local origin) and the remote
# deploy step scripts/ci-remote-deploy.sh (ssh / rsync / ssh-keyscan shims).
#
# Run: bash scripts/tests/ci-plan.test.sh   (needs bash, git, jq, node)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../ci-plan.sh"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
REPO_NAME="DataVallis/pet-prep"
REPO_ID=100
FORK_ID=200
WF=".github/workflows/deploy-production.yml"

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
echo "$path" >> "$FIX/gh.log"
[ "${GH_FAIL:-0}" = 1 ] && { echo "gh shim: forced failure" >&2; exit 1; }
if [ -n "${GH_FAIL_MATCH:-}" ] && [[ "$path" =~ $GH_FAIL_MATCH ]]; then echo "gh shim: forced failure" >&2; exit 1; fi
case "$path" in
  https://zip/*) cat "$FIX/zip/${path##*/}.zip"; exit 0 ;; # D28b marker archives (raw)
  */commits/*/pulls) file="$FIX/pulls.json"; [ -f "$file" ] || echo '[]' > "$file" ;;
  */actions/workflows/*/runs\?event=pull_request*) file="$FIX/prruns.json"; [ -f "$file" ] || echo '{"workflow_runs":[]}' > "$file" ;;
  */actions/runs/*/artifacts\?*) id="${path%/artifacts*}"; id="${id##*/}"; file="$FIX/runarts-${id}.json"
    [ -f "$file" ] || echo '{"artifacts":[]}' > "$file" ;;
  */actions/workflows/*/runs\?*) file="$FIX/runs.json" ;;
  */actions/artifacts\?name=*)
    name="${path#*name=}"; name="${name%%&*}"
    file="$FIX/artifacts-${name}.json"
    [ -f "$file" ] || { echo '{"total_count":0,"artifacts":[]}' > "$file"; } ;;
  */actions/runs/*/jobs\?*) id="${path%/jobs*}"; id="${id##*/}"; file="$FIX/jobs-${id}.json"
    [ -f "$file" ] || { echo '{"message":"Not Found"}' >&2; exit 1; } ;;
  */actions/runs/*) file="$FIX/run-${path##*/}.json"; [ -f "$file" ] || { echo '{"message":"Not Found"}' >&2; exit 1; } ;;
  *) echo "gh shim: unknown path $path" >&2; exit 64 ;;
esac
jq -r "$filter" "$file"
SHIM
chmod +x "$TMP/bin/gh"
REAL_GIT="$(command -v git)"
cat > "$TMP/bin/git" <<SHIM
#!/usr/bin/env bash
for a in "\$@"; do
  if [ "\$a" = diff ]; then
    [ "\${GIT_DIFF_FAIL:-0}" = 1 ] && { echo "fatal: could not fetch object (shim)" >&2; exit 128; }
    [ "\${GIT_DIFF_EMPTY:-0}" = 1 ] && exit 0
    break
  fi
done
exec "$REAL_GIT" "\$@"
SHIM
chmod +x "$TMP/bin/git"

pass=0 fail=0 n=0
check() { # check DESC EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "  FAIL: $1 — expected '$2', got '$3'"; fi
}

# new_repo: fresh repo in $W with one commit on main; prints nothing. Sets $W, $FIX.
new_repo() {
  n=$((n + 1))
  W="$TMP/case$n/repo"; FIX="$TMP/case$n/fix"
  mkdir -p "$W" "$FIX"
  git -C "$W" init -q -b main
  git -C "$W" config user.email t@t; git -C "$W" config user.name t
  mkdir -p "$W/backend/app" "$W/mobile/src" "$W/scripts/tests" "$W/deployment" "$W/docs" "$W/.github/workflows"
  for f in backend/app/A.php backend/CLAUDE.md mobile/src/a.ts scripts/deploy-production.sh \
           scripts/generate-api-types.mjs scripts/tests/x.test.sh deployment/Caddyfile docs/X.md "$WF" README.md; do
    echo v1 > "$W/$f"
  done
  git -C "$W" add -A; git -C "$W" commit -qm base
  echo '{"workflow_runs":[]}' > "$FIX/runs.json"
}
commit() { # commit FILE... (changes each file)
  local f; for f in "$@"; do mkdir -p "$(dirname "$W/$f")"; echo "$RANDOM$RANDOM" >> "$W/$f"; done
  git -C "$W" add -A; git -C "$W" commit -qm "change $*"
}
sha() { git -C "$W" rev-parse "${1:-HEAD}"; }
tree() { git -C "$W" rev-parse "${1:-HEAD}^{tree}"; }
# pr_merge FILE...: branch off main, change FILEs, check out a detached merge commit
# (like refs/pull/N/merge). main stays where it was.
pr_merge() {
  git -C "$W" checkout -q -b feature main
  commit "$@"
  git -C "$W" checkout -q --detach main
  git -C "$W" merge -q --no-ff --no-edit feature
}
green_runs() { # green_runs SHA [EVENT] [REPO_FULL]: one successful main run that deployed (D18: dispatch)
  jq -n --arg s "$1" --arg e "${2:-workflow_dispatch}" --arg r "${3:-$REPO_NAME}" \
    '{workflow_runs:[{id:1,event:$e,head_sha:$s,head_repository:{full_name:$r}}]}' > "$FIX/runs.json"
  jobs_json 1 success
}
jobs_json() { # jobs_json RUN_ID DEPLOY_CONCLUSION (success / skipped / failure)
  jq -n --arg c "$2" '{jobs:[{name:"Plan (changed paths, tested tree)",conclusion:"success"},
    {name:"CI OK",conclusion:"success"},{name:"Deploy to Hetzner Production",conclusion:$c}]}' > "$FIX/jobs-$1.json"
}
runs_list() { # runs_list "ID SHA" ...: successful main Run workflow runs, newest first (D18)
  local r
  for r in "$@"; do echo "$r"; done | jq -R 'split(" ") | {id:(.[0]|tonumber),event:"workflow_dispatch",head_sha:.[1],head_repository:{full_name:"'"$REPO_NAME"'"}}' \
    | jq -s '{workflow_runs:.}' > "$FIX/runs.json"
}
marker() { # marker SUITE TREE RUN_ID [HEAD_REPO_ID] [EXPIRED]
  jq -n --argjson id "$3" --argjson rid "$REPO_ID" --argjson hid "${4:-$REPO_ID}" --argjson exp "${5:-false}" \
    '{total_count:1,artifacts:[{name:"x",expired:$exp,workflow_run:{id:$id,repository_id:$rid,head_repository_id:$hid}}]}' \
    > "$FIX/artifacts-ci-green-$1-$2.json"
}
run_json() { # run_json ID EVENT CONCLUSION [PATH]
  jq -n --argjson id "$1" --arg e "$2" --arg c "$3" --arg p "${4:-$WF}" \
    '{id:$id,event:$e,status:"completed",conclusion:$c,path:$p}' > "$FIX/run-$1.json"
}
# plan EVENT REF [ATTEMPT]: runs ci-plan.sh in $W, loads outputs into o_* vars.
plan() {
  local out="$FIX/out" rc=0 fix="$FIX"
  : > "$out"
  rm -rf "$fix/derived"
  ( cd "$W" && PATH="$TMP/bin:$PATH" FIX="$fix" EVENT_NAME="$1" GIT_REF="$2" RUN_ATTEMPT="${3:-1}" \
      CI_PLAN_DERIVED_DIR="$fix/derived" GITHUB_RUN_ID=900 \
      DEPLOY_TARGET="${P_TARGET:-none}" FORCE_TESTS="${P_FORCE:-false}" \
      REPO="$REPO_NAME" WORKFLOW_FILE=deploy-production.yml GITHUB_OUTPUT="$out" \
      GITHUB_STEP_SUMMARY="$fix/summary" bash "$SCRIPT" >"$fix/stdout" 2>"$fix/stderr" ) || rc=$?
  o_rc=$rc
  o_mode="$(sed -n 's/^mode=//p' "$out")"; o_base="$(sed -n 's/^base=//p' "$out")"
  o_tree="$(sed -n 's/^tree=//p' "$out")"
  o_suites="$(for k in backend mobile scripts image; do printf '%s=%s ' "$k" "$(sed -n "s/^$k=//p" "$out")"; done)"
  o_deploy="$(sed -n 's/^deploy=//p' "$out")"
}
S_NONE="backend=false mobile=false scripts=false image=false "
S_ALL="backend=true mobile=true scripts=true image=true "
S_BACKEND="backend=true mobile=false scripts=false image=true "
S_MOBILE="backend=false mobile=true scripts=false image=false "
S_BSCRIPTS="backend=true mobile=false scripts=true image=true "
S_BTSCRIPTS="backend=true mobile=false scripts=true image=false "
S_SCRIPTS="backend=false mobile=false scripts=true image=false "

echo "== pull_request: path filter =="
pr_case() { # pr_case DESC EXPECTED_SUITES FILE...
  local desc="$1" exp="$2"; shift 2
  new_repo; pr_merge "$@"; plan pull_request refs/pull/1/merge
  check "$desc: exit" 0 "$o_rc"
  check "$desc: mode" diff "$o_mode"
  check "$desc: base is main" "$(sha main)" "$o_base"
  check "$desc: suites" "$exp" "$o_suites"
  check "$desc: no deploy on PR" false "$o_deploy"
}
pr_case "docs only" "$S_NONE" docs/X.md README.md
pr_case "backend/CLAUDE.md only" "$S_NONE" backend/CLAUDE.md
pr_case "backend/README.md only" "$S_NONE" backend/README.md
pr_case "other .md under backend counts" "$S_BACKEND" backend/resources/views/mail/x.md
pr_case "dog research data: backend + scripts tests only" "$S_BTSCRIPTS" docs/research/dog-data/data.json
pr_case "dog research sources: backend + scripts tests only" "$S_BTSCRIPTS" docs/research/dog-data/sources.md
pr_case "breed registry export: scripts tests only" "$S_SCRIPTS" scripts/export-breed-registry.mjs scripts/tests/export-breed-registry.test.mjs
pr_case "daily deploy trigger: scripts tests only" "$S_SCRIPTS" scripts/daily-deploy.sh scripts/tests/daily-deploy.test.sh .github/workflows/daily-deploy.yml
pr_case "CI ffmpeg installer: backend + scripts tests only" "$S_BTSCRIPTS" scripts/ci-install-ffmpeg.sh scripts/tests/ci-install-ffmpeg.test.sh
pr_case "breed docs index: scripts tests only" "$S_SCRIPTS" scripts/breeds-index.mjs scripts/tests/breeds-index.test.mjs docs/breeds/hungarian-vizsla.md
pr_case "breed registry output: backend + scripts tests only" "$S_BTSCRIPTS" docs/research/breed-registry.json
pr_case "breeder checklist: scripts tests only" "$S_SCRIPTS" docs/research/breeder-checklist/data.json docs/research/breeder-checklist/sources.md
pr_case "breed portraits: scripts tests only" "$S_SCRIPTS" docs/research/breed-portraits/manifest.json docs/research/breed-portraits/dog/border-collie.webp
pr_case "breed suitability config: backend + scripts" "$S_BSCRIPTS" backend/config/breed_suitability.php
pr_case "breed colours config: backend + scripts" "$S_BSCRIPTS" backend/config/breed_colours.php
pr_case "app pet strings: mobile + scripts" "backend=false mobile=true scripts=true image=false " mobile/src/i18n/locales/sl/pet.json
pr_case "app breed names: mobile + scripts" "backend=false mobile=true scripts=true image=false " mobile/src/i18n/locales/en/family.json
pr_case "cat research data: backend + scripts tests only" "$S_BTSCRIPTS" docs/research/cat-data/data.json
pr_case "breed facts: backend tests only" "backend=true mobile=false scripts=false image=false " docs/research/breed-facts/border_collie.json
pr_case "other research docs" "$S_NONE" docs/research/DOG_DATA_SOURCES.md
pr_case "backend code" "$S_BACKEND" backend/app/A.php
pr_case "new backend file" "$S_BACKEND" backend/database/migrations/x.php
pr_case "mobile only" "$S_MOBILE" mobile/src/a.ts
pr_case "generate-api-types only" "$S_MOBILE" scripts/generate-api-types.mjs
pr_case "deploy script" "$S_BSCRIPTS" scripts/deploy-production.sh
pr_case "script harness" "$S_BSCRIPTS" scripts/tests/x.test.sh
pr_case "Caddyfile" "$S_BSCRIPTS" deployment/Caddyfile
pr_case "workflow" "$S_ALL" "$WF"
pr_case "backend + mobile" "backend=true mobile=true scripts=false image=true " backend/app/A.php mobile/src/a.ts
check "PR run never calls the API" "" "$(cat "$FIX/gh.log" 2>/dev/null || true)"

echo "== pull_request: not a merge commit → full =="
new_repo; commit docs/X.md; plan pull_request refs/pull/1/merge
check "full mode" full "$o_mode"; check "all suites" "$S_ALL" "$o_suites"; check "no deploy" false "$o_deploy"

echo "== pull_request: deleted file counts =="
new_repo; git -C "$W" checkout -q -b feature; git -C "$W" rm -q backend/app/A.php; git -C "$W" commit -qm rm
git -C "$W" checkout -q --detach main; git -C "$W" merge -q --no-ff --no-edit feature
plan pull_request refs/pull/1/merge
check "deletion → backend" "$S_BACKEND" "$o_suites"

echo "== push to main: diff against the last green main run =="
new_repo; base="$(sha)"; commit backend/app/A.php; green_runs "$base"
plan push refs/heads/main
check "mode" diff "$o_mode"; check "base" "$base" "$o_base"
check "backend runs (no marker)" "$S_BACKEND" "$o_suites"; check "deploy" true "$o_deploy"
check "tree output" "$(tree)" "$o_tree"

new_repo; base="$(sha)"; commit docs/X.md backend/README.md; green_runs "$base"
plan push refs/heads/main
check "docs-only merge: nothing runs" "$S_NONE" "$o_suites"; check "docs-only merge: no deploy" false "$o_deploy"

new_repo; base="$(sha)"; commit mobile/src/a.ts; green_runs "$base"
plan push refs/heads/main
check "mobile-only merge: mobile runs" "$S_MOBILE" "$o_suites"; check "mobile-only merge: no deploy" false "$o_deploy"

new_repo; base="$(sha)"; commit scripts/generate-api-types.mjs; green_runs "$base"
plan push refs/heads/main
check "api-types merge: no deploy" false "$o_deploy"

new_repo; base="$(sha)"; commit deployment/Caddyfile; green_runs "$base"
plan push refs/heads/main
check "Caddyfile merge: deploy" true "$o_deploy"; check "Caddyfile merge: suites" "$S_BSCRIPTS" "$o_suites"

new_repo; base="$(sha)"; commit docs/research/dog-data/data.json; green_runs "$base"
plan push refs/heads/main
check "dog-data merge: backend + scripts tests" "$S_BTSCRIPTS" "$o_suites"; check "dog-data merge: no deploy" false "$o_deploy"

new_repo; base="$(sha)"; commit scripts/export-breed-registry.mjs; green_runs "$base"
plan push refs/heads/main
check "registry export merge: scripts tests" "$S_SCRIPTS" "$o_suites"; check "registry export merge: no deploy" false "$o_deploy"

new_repo; base="$(sha)"; commit docs/research/dog-data/data.json backend/app/A.php; green_runs "$base"
plan push refs/heads/main
check "dog-data + backend merge: deploy" true "$o_deploy"; check "dog-data + backend merge: suites" "$S_BSCRIPTS" "$o_suites"

echo "== push to main: base = last run that really deployed (2026-10-06 incident) =="
# A (deployed) → B (workflow + scripts merged; green, deploy SKIPPED) → C (docs only).
new_repo; a="$(sha)"; commit "$WF" scripts/ci-plan.sh; b="$(sha)"; commit docs/X.md
runs_list "2 $b" "1 $a"; jobs_json 2 skipped; jobs_json 1 success
plan push refs/heads/main
check "skipped-deploy run is not a base" "$a" "$o_base"
check "B's undeployed changes still deploy" true "$o_deploy"; check "→ all suites" "$S_ALL" "$o_suites"
new_repo; a="$(sha)"; commit backend/app/A.php; b="$(sha)"; commit docs/X.md
runs_list "2 $b" "1 $a"; jobs_json 2 failure; jobs_json 1 success
plan push refs/heads/main
check "failed-deploy run is not a base" "$a" "$o_base"; check "→ deploy" true "$o_deploy"
new_repo; a="$(sha)"; commit backend/app/A.php; b="$(sha)"; commit docs/X.md
runs_list "2 $b" "1 $a"; jobs_json 2 success; jobs_json 1 success
plan push refs/heads/main
check "newest deployed run is the base" "$b" "$o_base"; check "docs-only since → no deploy" false "$o_deploy"
new_repo; a="$(sha)"; commit docs/X.md; runs_list "1 $a"; jobs_json 1 skipped
plan push refs/heads/main
check "no deployed run → full" full "$o_mode"; check "no deployed run → deploy" true "$o_deploy"
new_repo; a="$(sha)"; commit docs/X.md; green_runs "$a"; GH_FAIL_MATCH='/jobs' plan push refs/heads/main
check "jobs API error → full" full "$o_mode"; check "jobs API error → deploy" true "$o_deploy"
check "jobs API error: exit 0" 0 "$o_rc"

echo "== push to main: fail closed on a broken diff =="
new_repo; a="$(sha)"; commit docs/X.md; green_runs "$a"; GIT_DIFF_FAIL=1 plan push refs/heads/main
check "diff fails → exit 0" 0 "$o_rc"; check "diff fails → full" full "$o_mode"; check "diff fails → deploy" true "$o_deploy"
check "diff fails → all suites" "$S_ALL" "$o_suites"
new_repo; a="$(sha)"; commit docs/X.md; green_runs "$a"; GIT_DIFF_EMPTY=1 plan push refs/heads/main
check "empty diff, different trees → full" full "$o_mode"; check "empty diff, different trees → deploy" true "$o_deploy"
new_repo; green_runs "$(sha)"; plan push refs/heads/main
check "base == HEAD (already deployed): diff" diff "$o_mode"; check "base == HEAD: no deploy" false "$o_deploy"
new_repo; pr_merge docs/X.md; GIT_DIFF_FAIL=1 plan pull_request refs/pull/1/merge
check "PR diff fails → full" "full $S_ALL" "$o_mode $o_suites"

echo "== plan annotation =="
new_repo; a="$(sha)"; commit backend/app/A.php; green_runs "$a"; plan push refs/heads/main
note="$(grep '^::notice title=plan::' "$FIX/stdout" || true)"
check "one notice line" 1 "$(grep -c '^::notice title=plan::' "$FIX/stdout" || true)"
for want in "mode=diff" "base=$a" "changed_files=1" "deploy_paths=true" "need: backend=true" "deploy=true"; do
  case "$note" in *"$want"*) r=0 ;; *) r=1 ;; esac
  check "notice has $want" 0 "$r"
done
new_repo; GH_FAIL=1 plan push refs/heads/main
case "$(cat "$FIX/stdout")" in *"reason=GitHub API error"*"deploy=true"*) r=0 ;; *) r=1 ;; esac
check "notice carries the full-run reason" 0 "$r"

echo "== workflow: deploy must not inherit skipped suites =="
# GitHub's implicit success() on a job looks at ALL upstream jobs (transitively): with
# skipped suites behind ci-ok, `needs: [plan, ci-ok]` without a status function skips
# deploy even when ci-ok succeeded. The deploy condition must therefore be explicit.
WFFILE="${HERE}/../../${WF}"
job_if() { # job_if JOB: the job's `if:` block, one line
  awk -v job="^  $1:" '$0 ~ job {d=1; next} d&&/^  [a-z-]+:/{exit} d&&/^    if:/{f=1} f&&/^    [a-z-]+:/&&!/^    if:/{exit} f{print}' "$WFFILE" | tr -s ' \n' ' '
}
deploy_if="$(job_if deploy)"
for want in "!cancelled()" "needs.plan.result == 'success'" "needs.ci-ok.result == 'success'" "needs.plan.outputs.deploy == 'true'" \
            "needs.plan.outputs.target == 'production'" "github.event_name == 'workflow_dispatch'"; do
  case "$deploy_if" in *"$want"*) r=0 ;; *) r=1 ;; esac
  check "deploy if: has $want" 0 "$r"
done
case "$deploy_if" in *"'push'"*) r=1 ;; *) r=0 ;; esac
check "deploy if: a push can never deploy (D18)" 0 "$r"
r=1; grep -q '^    name: Deploy to Hetzner Production$' "$WFFILE" && r=0
check "production job keeps its name (ci-plan.sh base lookup)" 0 "$r"
r=0; grep -qE '^  deploy-dev:|vars\.DEV_HOST' "$WFFILE" && r=1
check "no dev deploy path left in the workflow" 0 "$r"
r=1; grep -q 'EXPECT_DOMAIN: api.petprep.si' "$WFFILE" && r=0
check "production job names the expected server domain" 0 "$r"

echo "== deploy target (scripts/ci-deploy-target.sh, D18) =="
TARGET_SH="${HERE}/../ci-deploy-target.sh"
target() { # target EVENT REF INPUT → "target|rc"
  local out="$TMP/target.out" rc=0
  : > "$out"
  EVENT_NAME="$1" GIT_REF="$2" INPUT_TARGET="$3" GITHUB_OUTPUT="$out" \
    bash "$TARGET_SH" > "$TMP/target.stdout" 2>&1 || rc=$?
  echo "$(sed -n 's/^target=//p' "$out")|$rc"
}
check "push to main → none (CI only)" "none|0" "$(target push refs/heads/main "")"
check "push to main ignores an input → none" "none|0" "$(target push refs/heads/main production)"
check "Run workflow (default) → none" "none|0" "$(target workflow_dispatch refs/heads/main "")"
check "Run workflow target none → none" "none|0" "$(target workflow_dispatch refs/heads/main none)"
check "Run workflow target production → production" "production|0" "$(target workflow_dispatch refs/heads/main production)"
check "Run workflow on another branch → none" "none|0" "$(target workflow_dispatch refs/heads/feature production)"
check "pull_request → none" "none|0" "$(target pull_request refs/pull/1/merge "")"
check "unknown input target → error" "|1" "$(target workflow_dispatch refs/heads/main dev)"
target push refs/heads/main "" > /dev/null
r=1; grep -q '^::notice title=deploy target::none — push to main: CI only' "$TMP/target.stdout" && r=0
check "target printed as a notice" 0 "$r"

echo "== mobile OTA plan (scripts/mobile-ota-plan.sh, D18) =="
OTA_PLAN="${HERE}/../mobile-ota-plan.sh"
# ota_repo: repo with an origin; main = HEAD. Sets $W, $FIX, $OTA_ORIGIN.
ota_repo() {
  new_repo
  mkdir -p "$W/docs/research"; echo '{"commit":""}' > "$W/docs/research/app-release.json"
  git -C "$W" add -A; git -C "$W" commit -qm release-file
  OTA_ORIGIN="$TMP/ota-origin$n.git"
  git init -q --bare "$OTA_ORIGIN"
}
ota_push() { git -C "$W" -c push.negotiate=false push -q -f "$OTA_ORIGIN" HEAD:refs/heads/main; }
release_at() { # release_at SHA: app-release.json says the last production release was SHA
  echo "{\"commit\":\"$1\"}" > "$W/docs/research/app-release.json"
  git -C "$W" add -A; git -C "$W" commit -qm "record release"
}
ota() { # ota EVENT WR_EVENT WR_CONCLUSION [INPUT_CHANNEL] [DRY_RUN] → "mobile channel rc"
  local out="$FIX/ota.out" rc=0
  : > "$out"
  ( cd "$W" && PATH="$TMP/bin:$PATH" FIX="$FIX" EVENT_NAME="$1" WR_EVENT="$2" WR_CONCLUSION="$3" WR_RUN_ID=9 \
      INPUT_CHANNEL="${4:-}" DRY_RUN="${5:-false}" REPO="$REPO_NAME" OTA_PLAN_REMOTE="$OTA_ORIGIN" \
      GITHUB_OUTPUT="$out" bash "$OTA_PLAN" > "$FIX/ota.stdout" 2>&1 ) || rc=$?
  echo "$(sed -n 's/^mobile=//p' "$out") $(sed -n 's/^channel=//p' "$out") $rc"
}
prod_jobs() { # prod_jobs CONCLUSION: CI run 9's production deploy job
  jq -n --arg c "$1" '{jobs:[{name:"CI OK",conclusion:"success"},{name:"Deploy to Hetzner Production",conclusion:$c}]}' > "$FIX/jobs-9.json"
}
ota_repo; commit mobile/src/a.ts; ota_push
check "push with mobile/ change → nothing (a merge publishes nothing)" "false  0" "$(ota workflow_run push success)"
check "red CI run → nothing" "false  0" "$(ota workflow_run push failure)"
# Production after a production deploy (Run workflow, target production).
ota_repo; rel="$(sha)"; commit mobile/src/a.ts; release_at "$rel"; commit backend/app/A.php; ota_push
prod_jobs success
check "production deploy, mobile/ changed since the release → production" "true production 0" "$(ota workflow_run workflow_dispatch success)"
commit backend/app/A.php; ota_push; git -C "$W" checkout -q HEAD~1
check "… publishes the deployed commit even when main moved on" "true production 0" "$(ota workflow_run workflow_dispatch success)"
ota_repo; commit mobile/src/a.ts; release_at "$(sha)"; commit backend/app/A.php; ota_push; prod_jobs success
check "production deploy, mobile/ unchanged since the release → nothing" "false production 0" "$(ota workflow_run workflow_dispatch success)"
ota_repo; commit mobile/src/a.ts; ota_push; prod_jobs success
check "production deploy, release commit unknown → check" "true production 0" "$(ota workflow_run workflow_dispatch success)"
prod_jobs skipped
check "Run workflow with target none (deploy skipped) → nothing" "false  0" "$(ota workflow_run workflow_dispatch success)"
prod_jobs failure
check "failed production deploy → nothing" "false  0" "$(ota workflow_run workflow_dispatch success)"
rm -f "$FIX/jobs-9.json"
check "jobs API error → fail (never guess production)" "  1" "$(ota workflow_run workflow_dispatch success)"
# Manual OTA runs.
ota_repo; ota_push; green_runs "$(sha)"   # the last production deploy ran this commit
check "manual production of the deployed commit → production" "true production 0" "$(ota workflow_dispatch "" "" production)"
check "manual preview → preview" "true preview 0" "$(ota workflow_dispatch "" "" preview)"
check "manual without channel → error" "  1" "$(ota workflow_dispatch "" "" "")"
check "manual unknown channel → error" "  1" "$(ota workflow_dispatch "" "" staging)"
commit mobile/src/a.ts; ota_push
check "manual production of a commit production does NOT run → refused" "  1" "$(ota workflow_dispatch "" "" production)"
r=1; grep -q 'is not the commit production runs' "$FIX/ota.stdout" && r=0
check "… and says why" 0 "$r"
check "… preview of that commit is fine" "true preview 0" "$(ota workflow_dispatch "" "" preview)"
check "… dry run is not refused" "true production 0" "$(ota workflow_dispatch "" "" production true)"
echo '{"workflow_runs":[]}' > "$FIX/runs.json"
check "manual production, no production deploy at all → refused" "  1" "$(ota workflow_dispatch "" "" production)"
green_runs "$(sha)"
check "manual production, jobs API error → refused" "  1" "$(GH_FAIL_MATCH='/jobs' ota workflow_dispatch "" "" production)"
commit docs/X.md; ota_push; git -C "$W" checkout -q HEAD~1
check "manual preview off the head of main → nothing" "false preview 0" "$(ota workflow_dispatch "" "" preview)"
r=1; grep -q 'is not the head of main' "$FIX/ota.stdout" && r=0
check "… and says why" 0 "$r"

echo "== mobile-ota.sh: channel profile from eas.json (stubbed npx / eas) =="
OTA_SH="${HERE}/../mobile-ota.sh"
mkdir -p "$TMP/obin"
# npx expo-updates runtimeversion:resolve → fixed runtime; eas build:list → one build; eas update → log.
cat > "$TMP/obin/npx" <<'SHIM'
#!/usr/bin/env bash
echo '{"runtimeVersion":"rt-1"}'
SHIM
cat > "$TMP/obin/eas" <<'SHIM'
#!/usr/bin/env bash
echo "eas $* | API=${EXPO_PUBLIC_API_URL:-unset}" >> "$OTA_LOG"
case "$1" in build:list)
  # NO_BUILD_FOR=android → that platform has no build of the channel (native code changed).
  if [[ -n "${NO_BUILD_FOR:-}" && "$*" == *"--platform ${NO_BUILD_FOR}"* ]]; then echo '[]'; else echo '[{"id":"b1"}]'; fi ;;
esac
SHIM
chmod +x "$TMP/obin"/*
ota_sh() { # ota_sh EAS_JSON ARGS... → rc; log $TMP/ota-sh.log, output $TMP/ota-sh.out
  local d="$TMP/otash$RANDOM" rc=0
  mkdir -p "$d/mobile"; printf '%s' "$1" > "$d/mobile/eas.json"; shift
  : > "$TMP/ota-sh.log"
  ( cd "$d" && PATH="$TMP/obin:$PATH" OTA_LOG="$TMP/ota-sh.log" GITHUB_OUTPUT="$TMP/ota-sh.gho" \
      bash "$OTA_SH" "msg" "$@" > "$TMP/ota-sh.out" 2>&1 ) || rc=$?
  echo "$rc"
}
EAS_OK='{"build":{"production":{"channel":"production","env":{"EXPO_PUBLIC_API_URL":"https://api.petprep.si"}},"preview":{"channel":"preview","env":{}}}}'
: > "$TMP/ota-sh.gho"
check "production channel publishes with the profile env" 0 "$(ota_sh "$EAS_OK" --channel production)"
r=1; grep -q 'eas update --channel production --environment production --platform ios .*API=https://api.petprep.si' "$TMP/ota-sh.log" && r=0
check "… EXPO_PUBLIC_* of the profile exported" 0 "$r"
r=1; grep -q '^released=true$' "$TMP/ota-sh.gho" && r=0
check "… both platforms → released=true" 0 "$r"
r=1; grep -q '^platforms=ios,android$' "$TMP/ota-sh.gho" && r=0
check "… platforms=ios,android" 0 "$r"
# David 2026-10-25: one platform on production is enough for "in the app" on the website.
: > "$TMP/ota-sh.gho"
check "only iOS has a production build → still publishes iOS" 0 "$(NO_BUILD_FOR=android ota_sh "$EAS_OK" --channel production)"
r=1; grep -q 'eas update --channel production .*--platform ios' "$TMP/ota-sh.log" && ! grep -q 'eas update .*--platform android' "$TMP/ota-sh.log" && r=0
check "… iOS updated, Android not" 0 "$r"
r=1; grep -q '^released=true$' "$TMP/ota-sh.gho" && grep -q '^platforms=ios$' "$TMP/ota-sh.gho" && r=0
check "… one platform → released=true, platforms=ios" 0 "$r"
: > "$TMP/ota-sh.gho"
check "dry run (iOS build only) → exit 0" 0 "$(NO_BUILD_FOR=android ota_sh "$EAS_OK" --channel production --dry-run)"
r=0; grep -q 'released' "$TMP/ota-sh.gho" && r=1
check "… dry run is never a release" 0 "$r"
check "missing profile → exit 1" 1 "$(ota_sh '{"build":{"production":{"channel":"production"}}}' --channel preview)"
r=0; [ -s "$TMP/ota-sh.log" ] && r=1
check "… nothing published (no eas call)" 0 "$r"
r=1; grep -q "has no build profile 'preview'" "$TMP/ota-sh.out" && r=0
check "… says why" 0 "$r"
check "profile with another channel → exit 1" 1 "$(ota_sh '{"build":{"preview":{"channel":"production"}}}' --channel preview)"
: > "$TMP/ota-sh.gho"
check "preview publishes, never a release" 0 "$(ota_sh "$EAS_OK" --channel preview)"
r=0; grep -q 'released' "$TMP/ota-sh.gho" && r=1
check "… no released=true" 0 "$r"

echo "== remote deploy step (scripts/ci-remote-deploy.sh) =="
REMOTE_SH="${HERE}/../ci-remote-deploy.sh"
mkdir -p "$TMP/rbin"
for tool in ssh rsync ssh-keyscan; do
  # shellcheck disable=SC2016  # $* / $REMOTE_LOG expand inside the shim
  printf '#!/usr/bin/env bash\necho "%s $*" >> "$REMOTE_LOG"\n' "$tool" > "$TMP/rbin/$tool"
done
chmod +x "$TMP/rbin"/*
remote() { # remote KEY HOST [SHA] → rc; log in $TMP/remote.log
  local rc=0
  : > "$TMP/remote.log"
  ( cd "$TMP" && PATH="$TMP/rbin:$PATH" REMOTE_LOG="$TMP/remote.log" CI_REMOTE_SSH_DIR="$TMP/sshdir" \
      SSH_PRIVATE_KEY="$1" SSH_HOST="$2" SSH_USER=deploy COMMIT_SHA="${3:-abc1234}" EXPECT_DOMAIN=api.petprep.si \
      TARGET=production KEY_SECRET=PRODUCTION_SSH_PRIVATE_KEY bash "$REMOTE_SH" > "$TMP/remote.out" 2>&1 ) || rc=$?
  echo "$rc"
}
check "uploads + deploys" 0 "$(remote "fake-key" 203.0.113.9)"
r=1; grep -q 'rsync -az --delete .*--exclude backend/storage .* deploy@203.0.113.9:/opt/petprep/incoming/' "$TMP/remote.log" && r=0
check "rsync to incoming/, storage excluded" 0 "$r"
r=1; grep -q 'ssh .*deploy@203.0.113.9 /opt/petprep/incoming/scripts/deploy-production.sh --from /opt/petprep/incoming --expect-domain api.petprep.si abc1234' "$TMP/remote.log" && r=0
check "deploy script called with --expect-domain" 0 "$r"
check "key file mode 600" 600 "$(stat -c %a "$TMP/sshdir/deploy_key")"
check "missing key → fail" 1 "$(remote "" 203.0.113.9)"
r=1; grep -q 'PRODUCTION_SSH_PRIVATE_KEY secret is not set' "$TMP/remote.out" && r=0
check "… names the secret" 0 "$r"
check "missing host → fail, nothing uploaded" "1 0" "$(remote "fake-key" "") $(wc -l < "$TMP/remote.log" | tr -d ' ')"
check "SHA with shell characters → fail, nothing uploaded" "1 0" "$(remote "fake-key" 203.0.113.9 'abc1234;id') $(wc -l < "$TMP/remote.log" | tr -d ' ')"
# D28 prebuilt images: digests passed through, login over stdin, logout.
remote_img() { # remote_img APP WEB → rc
  local rc=0
  : > "$TMP/remote.log"
  ( cd "$TMP" && PATH="$TMP/rbin:$PATH" REMOTE_LOG="$TMP/remote.log" CI_REMOTE_SSH_DIR="$TMP/sshdir" \
      SSH_PRIVATE_KEY=k SSH_HOST=203.0.113.9 SSH_USER=deploy COMMIT_SHA=abc1234 EXPECT_DOMAIN=api.petprep.si \
      APP_IMAGE="$1" WEB_IMAGE="$2" REGISTRY_USER='github-actions[bot]' REGISTRY_TOKEN=tok-sentinel-123 \
      TARGET=production KEY_SECRET=PRODUCTION_SSH_PRIVATE_KEY bash "$REMOTE_SH" > "$TMP/remote.out" 2>&1 ) || rc=$?
  echo "$rc"
}
check "prebuilt: deploys" 0 "$(remote_img ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)"
r=1; grep -q -- "--expect-domain api.petprep.si --app-image ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --web-image ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb abc1234" "$TMP/remote.log" && r=0
check "prebuilt: digests passed to the deploy script" 0 "$r"
r=1; grep -q -- "docker login ghcr.io -u 'github-actions\\[bot\\]' --password-stdin" "$TMP/remote.log" && grep -q "docker logout ghcr.io" "$TMP/remote.log" && r=0
check "prebuilt: login via stdin, logout after" 0 "$r"
r=0; grep -q "tok-sentinel-123" "$TMP/remote.log" "$TMP/remote.out" && r=1
check "prebuilt: token never on a command line / in the output" 0 "$r"
check "prebuilt: no digests → plain deploy" 0 "$(remote_img "" "")"
r=0; grep -qE -- "--app-image|docker login" "$TMP/remote.log" && r=1
check "… without --app-image / login" 0 "$r"
check "prebuilt: a tag instead of a digest → fail" 1 "$(remote_img ghcr.io/datavallis/petprep-app:be-1 ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)"
check "prebuilt: shell characters → fail" 1 "$(remote_img 'ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa;id' ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb)"

echo "== image digests for the deploy (scripts/ci-image-refs.sh, D28) =="
REFS_SH="${HERE}/../ci-image-refs.sh"
RT="$TMP/refs"; mkdir -p "$RT/bin"
cat > "$RT/bin/gh" <<'GHEOF'
#!/usr/bin/env bash
echo "gh $*" >> "$RT/gh.log"
[ -n "${REFS_GH_FAIL:-}" ] && exit 1
case "$2" in
  *"/artifacts?name="*) echo "${REFS_URL-https://api.github.com/zip/1}" ;;
  https://*) cat "$RT/marker.zip" ;;
esac
GHEOF
chmod +x "$RT/bin/gh"
TREE40="$(printf 'c%.0s' $(seq 40))"
refs() { # refs RUN_APP RUN_WEB MARKER_RUN → "app|web"
  : > "$RT/out"
  export RT
  CI_IMAGE_REFS_GH="$RT/bin/gh" REPO=o/r TREE="$TREE40" RUN_APP="$1" RUN_WEB="$2" MARKER_RUN="$3" \
    GITHUB_OUTPUT="$RT/out" bash "$REFS_SH" 2>"$RT/err"
  echo "$(sed -n 's/^app=//p' "$RT/out")|$(sed -n 's/^web=//p' "$RT/out")"
}
mkzip() { # mkzip CONTENT
  rm -rf "$RT/z" "$RT/marker.zip"; mkdir -p "$RT/z"; printf '%s' "$1" > "$RT/z/ci-green.txt"
  ( cd "$RT/z" && python3 -c "import zipfile;z=zipfile.ZipFile('../marker.zip','w');z.write('ci-green.txt');z.close()" )
}
check "this run's image job → its digests" "ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa|ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$(refs ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb "")"
mkzip "$TREE40 77
app=ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
web=ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
"
check "skipped image job → digests from the marker run" "ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa|ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$(refs "" "" 77)"
r=1; grep -q "runs/77/artifacts?name=ci-green-image-$TREE40" "$RT/gh.log" && r=0
check "… reads ci-green-image-<tree> of that run" 0 "$r"
mkzip "$TREE40 77
"
check "pre-D28 / fork marker without digests → empty (server builds)" "|" "$(refs "" "" 77)"
mkzip "$TREE40 77
app=ghcr.io/datavallis/petprep-app:be-1
web=ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
"
check "a tag in the marker → empty" "|" "$(refs "" "" 77)"
check "app and web swapped → empty" "|" "$(refs ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa "")"
check "only one digest → empty" "|" "$(refs ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa "" "")"
check "API error → empty, exit 0" "|" "$(REFS_GH_FAIL=1 refs "" "" 77)"
check "no artifact → empty" "|" "$(REFS_URL='' refs "" "" 77)"
check "no marker run, no job outputs → empty" "|" "$(refs "" "" "")"
check "non-numeric marker run → not looked up" "|" "$(: > "$RT/gh.log"; refs "" "" '77;id')"
check "… no API call" 0 "$(wc -l < "$RT/gh.log" | tr -d ' ')"

echo "== push to main: a failed / cancelled run in between is not lost =="
new_repo; base="$(sha)"; commit backend/app/A.php; commit docs/X.md; green_runs "$base"
plan push refs/heads/main
check "backend change from the red run is in the diff" "$S_BACKEND" "$o_suites"; check "deploy" true "$o_deploy"

echo "== push to main: green run filter (event / repo) =="
new_repo; base="$(sha)"; commit backend/app/A.php; green_runs "$base" workflow_dispatch
plan push refs/heads/main
check "workflow_dispatch run counts as green base" diff "$o_mode"
new_repo; base="$(sha)"; commit docs/X.md; green_runs "$base" push
plan push refs/heads/main
check "push run is not a deploy base since D18 → full" full "$o_mode"
r=1; grep -q 'runs?branch=main&event=workflow_dispatch&status=success' "$FIX/gh.log" && r=0
check "API query lists only Run workflow runs" 0 "$r"
new_repo; base="$(sha)"; commit docs/X.md; green_runs "$base" pull_request
plan push refs/heads/main
check "pull_request run is not a main base → full" full "$o_mode"; check "→ deploy" true "$o_deploy"
new_repo; base="$(sha)"; commit docs/X.md; green_runs "$base" push "evil/pet-prep"
plan push refs/heads/main
check "run from another repo is not a base → full" full "$o_mode"

echo "== push to main: no base / API error / not an ancestor / re-run → full + deploy =="
new_repo; commit docs/X.md; plan push refs/heads/main
check "no green run: full" full "$o_mode"; check "no green run: all" "$S_ALL" "$o_suites"; check "no green run: deploy" true "$o_deploy"
new_repo; base="$(sha)"; commit docs/X.md; green_runs "$base"; GH_FAIL=1 plan push refs/heads/main
check "API error: exit 0" 0 "$o_rc"; check "API error: full" full "$o_mode"; check "API error: deploy" true "$o_deploy"
check "API error: all suites" "$S_ALL" "$o_suites"
new_repo; git -C "$W" checkout -q -b other; commit docs/X.md; other="$(sha)"; git -C "$W" checkout -q main
commit docs/X.md; green_runs "$other"; plan push refs/heads/main
check "base not an ancestor: full" full "$o_mode"; check "base not an ancestor: deploy" true "$o_deploy"
new_repo; base="$(sha)"; commit docs/X.md; green_runs "$(sha)"; plan push refs/heads/main 2
check "re-run: full" full "$o_mode"; check "re-run: deploy" true "$o_deploy"
check "re-run: last green run not looked up" "" "$(grep 'workflows/' "$FIX/gh.log" || true)"
check "re-run: markers still consulted (tested tree may skip tests)" 4 "$(grep -c 'artifacts?name=ci-green-' "$FIX/gh.log")"
new_repo; base="$(sha)"; commit backend/app/A.php; green_runs "$base"; plan push refs/heads/feature
check "push outside main: full, no deploy" "full false" "$o_mode $o_deploy"

echo "== push to main: tree already green on a PR =="
setup_green() { # backend change merged; PR run 77 green for backend + image + scripts on this tree
  new_repo; base="$(sha)"; commit backend/app/A.php deployment/Caddyfile; green_runs "$base"
  t="$(tree)"
  marker backend "$t" 77; marker image "$t" 77; marker scripts "$t" 77
}
setup_green; run_json 77 pull_request success; plan push refs/heads/main
check "verified: tests skipped" "$S_NONE" "$o_suites"; check "verified: still deploys" true "$o_deploy"
if grep -q "already green (same tree).*run 77" "$FIX/summary"; then r=0; else r=1; fi
check "summary names the PR run" 0 "$r"

setup_green; marker backend "$t" 78; marker image "$t" 78; marker scripts "$t" 78
run_json 78 pull_request success; plan push refs/heads/main
check "verified via any listed run" "$S_NONE" "$o_suites"

setup_green; rm "$FIX/artifacts-ci-green-image-$t.json"; run_json 77 pull_request success; plan push refs/heads/main
check "only verified suites skipped" "backend=false mobile=false scripts=false image=true " "$o_suites"

setup_green; run_json 77 pull_request failure; plan push refs/heads/main
check "PR run failed → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 pull_request cancelled; plan push refs/heads/main
check "PR run cancelled → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 pull_request success
jq '.status = "in_progress" | .conclusion = null' "$FIX/run-77.json" > "$FIX/r" && mv "$FIX/r" "$FIX/run-77.json"
plan push refs/heads/main
check "PR run still running → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 push success; plan push refs/heads/main
check "marker from a push run → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 pull_request success ".github/workflows/other.yml"; plan push refs/heads/main
check "marker from another workflow → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 pull_request success "$WF@refs/pull/3/merge"; plan push refs/heads/main
check "path with @ref suffix accepted" "$S_NONE" "$o_suites"
setup_green; marker backend "$t" 77 "$FORK_ID"; marker image "$t" 77 "$FORK_ID"; marker scripts "$t" 77 "$FORK_ID"
run_json 77 pull_request success; plan push refs/heads/main
check "marker from a fork PR → run" "$S_BSCRIPTS" "$o_suites"
setup_green; rm "$FIX"/artifacts-ci-green-*; marker backend "$(tree HEAD~1)" 77; run_json 77 pull_request success
plan push refs/heads/main
check "marker for another tree → run" "$S_BSCRIPTS" "$o_suites"
setup_green; run_json 77 pull_request success; GH_FAIL_MATCH='artifacts' plan push refs/heads/main
check "artifact API error → run" "$S_BSCRIPTS" "$o_suites"; check "artifact API error: exit 0" 0 "$o_rc"
setup_green; GH_FAIL_MATCH='runs/77' plan push refs/heads/main
check "run API error → run" "$S_BSCRIPTS" "$o_suites"

setup_green; marker backend "$t" 77 "$REPO_ID" true; marker image "$t" 77 "$REPO_ID" true; marker scripts "$t" 77 "$REPO_ID" true
run_json 77 pull_request success; plan push refs/heads/main
check "expired marker → run" "$S_BSCRIPTS" "$o_suites"

echo "== pull_request never skips on markers =="
new_repo; pr_merge backend/app/A.php; t="$(tree)"; marker backend "$t" 77; run_json 77 pull_request success
plan pull_request refs/pull/1/merge
check "PR runs its tests even when a marker exists" "$S_BACKEND" "$o_suites"

echo "== workflow_dispatch =="
new_repo; plan workflow_dispatch refs/heads/main
check "dispatch on main: full + deploy" "full true" "$o_mode $o_deploy"; check "dispatch: all suites" "$S_ALL" "$o_suites"
new_repo; plan workflow_dispatch refs/heads/feature
check "dispatch elsewhere: full, no deploy" "full false" "$o_mode $o_deploy"

echo "== production dispatch reuses the tested tree (D28) =="
setup_green; marker mobile "$t" 77; run_json 77 push success; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: green push run on the tree → no tests" "$S_NONE" "$o_suites"; check "production: deploys" true "$o_deploy"
check "production: image_run = the run that verified the image" 77 "$(sed -n 's/^image_run=//p' "$FIX/out")"
setup_green; marker mobile "$t" 77; run_json 77 pull_request success; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: green PR run on the tree → no tests" "$S_NONE" "$o_suites"
setup_green; marker mobile "$t" 77; run_json 77 workflow_dispatch success; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: earlier green dispatch on the tree → no tests" "$S_NONE" "$o_suites"
setup_green; run_json 77 push success; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: mobile unchanged since the deploy → not needed (D28b diff mode)" "$S_NONE" "$o_suites"
setup_green; marker mobile "$t" 77; run_json 77 push failure; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: failed run's markers ignored" "$S_BSCRIPTS" "$o_suites"
setup_green; marker mobile "$t" 77; run_json 77 push success; P_TARGET=production P_FORCE=true plan workflow_dispatch refs/heads/main
check "production + force_tests → everything" "$S_ALL" "$o_suites"
check "… image_run empty when the image job runs here" "" "$(sed -n 's/^image_run=//p' "$FIX/out")"
setup_green; marker mobile "$t" 77; run_json 77 push success; plan workflow_dispatch refs/heads/main
check "target none (test button) → everything" "$S_ALL" "$o_suites"
setup_green; marker mobile "$t" 77; run_json 77 push success; P_TARGET=production plan workflow_dispatch refs/heads/main 2
check "production re-run → everything" "$S_ALL" "$o_suites"
setup_green; marker mobile "$t" 77; run_json 77 push success; GH_FAIL_MATCH='artifacts' P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: artifact API error → every needed suite" "$S_BSCRIPTS" "$o_suites"; check "production: API error exit 0" 0 "$o_rc"
setup_green; marker mobile "$t" 77; run_json 77 push success ".github/workflows/other.yml"; P_TARGET=production plan workflow_dispatch refs/heads/main
check "production: marker from another workflow → every needed suite" "$S_BSCRIPTS" "$o_suites"

echo "== push to main: merged PR proof when main moved (D28b) =="
zipmk() { # zipmk NAME CONTENT → $FIX/zip/NAME.zip with ci-green.txt
  mkdir -p "$FIX/zip/src-$1"; printf '%s' "$2" > "$FIX/zip/src-$1/ci-green.txt"
  python3 - "$FIX/zip/$1.zip" "$FIX/zip/src-$1/ci-green.txt" <<'PY'
import sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w') as z: z.write(sys.argv[2], 'ci-green.txt')
PY
}
# derive_setup BETWEEN_FILE [MARKER_BASE]: deployed base0; PR (backend/app/A.php) tested on B;
# main moves by BETWEEN_FILE; squash commit H. PR run 77 green with backend/image/scripts markers.
derive_setup() {
  new_repo; local base0; base0="$(sha)"; green_runs "$base0"
  B="$(sha)"
  git -C "$W" checkout -q -b feature; commit backend/app/A.php; PRHEAD="$(sha)"; git -C "$W" checkout -q main
  commit "$1"
  git -C "$W" checkout -q feature -- backend/app/A.php; git -C "$W" commit -qm squash
  t="$(tree)"
  jq -n --arg h "$PRHEAD" --arg r "$REPO_NAME" '[{number:5,merged_at:"2026-11-02T20:00:00Z",base:{ref:"main"},head:{sha:$h,repo:{full_name:$r}}}]' > "$FIX/pulls.json"
  jq -n --arg r "$REPO_NAME" '{workflow_runs:[{id:77,event:"pull_request",conclusion:"success",head_repository:{full_name:$r}}]}' > "$FIX/prruns.json"
  jq -n '{artifacts:[{name:"ci-green-backend-x",expired:false,archive_download_url:"https://zip/backend"},
    {name:"ci-green-image-x",expired:false,archive_download_url:"https://zip/image"},
    {name:"ci-green-scripts-x",expired:false,archive_download_url:"https://zip/scripts"}]}' > "$FIX/runarts-77.json"
  local mb="${2:-$B}"
  zipmk backend "x 77
base=$mb
"
  zipmk scripts "x 77
base=$mb
"
  zipmk image "x 77
base=$mb
app=ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
web=ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
"
}
out_of() { sed -n "s/^$1=//p" "$FIX/out"; }
derive_setup docs/X.md; plan push refs/heads/main
check "main moved by docs only → backend + image proven by the PR run" "$S_NONE" "$o_suites"
check "… derived markers for backend + image" "true true" "$(out_of derived_backend) $(out_of derived_image)"
check "… derived backend marker names this tree + the PR run" "$t 900|derived-from=77 base=$B" "$(sed -n 1,2p "$FIX/derived/backend/ci-green.txt" | paste -sd'|')"
check "… image digests copied" "app=ghcr.io/datavallis/petprep-app@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa|web=ghcr.io/datavallis/petprep-web@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" "$(grep -E '^(app|web)=' "$FIX/derived/image/ci-green.txt" | paste -sd'|')"
check "… image_run = this push run (its derived marker)" 900 "$(out_of image_run)"
derive_setup backend/app/B.php; plan push refs/heads/main
check "main moved by another backend change → backend + image run" "$S_BACKEND" "$o_suites"
check "… nothing derived" "" "$(out_of derived_backend)"
derive_setup mobile/src/a.ts; plan push refs/heads/main
check "main moved by mobile only → backend proven, mobile runs (needed since deploy)" "backend=false mobile=true scripts=false image=false " "$o_suites"
derive_setup docs/X.md "$(printf 'f%.0s' $(seq 40))"; plan push refs/heads/main
check "marker base not an ancestor → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; zipmk backend "x 77
"; zipmk image "x 77
"; zipmk scripts "x 77
"; plan push refs/heads/main
check "pre-D28b marker without base line → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; echo '[]' > "$FIX/pulls.json"; plan push refs/heads/main
check "no merged PR (direct push) → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; jq '.[0].head.repo.full_name = "someone/fork"' "$FIX/pulls.json" > "$FIX/p" && mv "$FIX/p" "$FIX/pulls.json"; plan push refs/heads/main
check "fork PR → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; jq '.workflow_runs[0].conclusion = "failure"' "$FIX/prruns.json" > "$FIX/p" && mv "$FIX/p" "$FIX/prruns.json"; plan push refs/heads/main
check "PR run not successful → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; GH_FAIL_MATCH='/pulls$' plan push refs/heads/main
check "PR lookup API error → run, exit 0" "$S_BACKEND 0" "$o_suites $o_rc"
derive_setup docs/X.md; GH_FAIL_MATCH='^https://zip/' plan push refs/heads/main
check "marker download error → run" "$S_BACKEND" "$o_suites"
derive_setup docs/X.md; jq '.artifacts |= map(select(.name | startswith("ci-green-image") | not))' "$FIX/runarts-77.json" > "$FIX/p" && mv "$FIX/p" "$FIX/runarts-77.json"
plan push refs/heads/main
check "PR run without image marker → only backend derived" "backend=false mobile=false scripts=false image=true " "$o_suites"
derive_setup docs/X.md; plan pull_request refs/pull/1/merge
check "pull_request never derives" "" "$(out_of derived_backend)"

echo "== production dispatch: diff against the last deploy, image always (D28b) =="
new_repo; base0="$(sha)"; commit docs/X.md; green_runs "$base0"
P_TARGET=production plan workflow_dispatch refs/heads/main
check "docs-only since the deploy → only the image (digests)" "diff backend=false mobile=false scripts=false image=true " "$o_mode $o_suites"
check "… still deploys" true "$o_deploy"
new_repo; base0="$(sha)"; commit mobile/src/a.ts; green_runs "$base0"
P_TARGET=production plan workflow_dispatch refs/heads/main
check "mobile change since the deploy → mobile + image" "backend=false mobile=true scripts=false image=true " "$o_suites"
new_repo; commit docs/X.md; P_TARGET=production plan workflow_dispatch refs/heads/main
check "no earlier deploy → full" "full $S_ALL" "$o_mode $o_suites"
new_repo; base0="$(sha)"; commit docs/X.md; green_runs "$base0"; P_TARGET=production P_FORCE=true plan workflow_dispatch refs/heads/main
check "force_tests → full" "full $S_ALL" "$o_mode $o_suites"
new_repo; base0="$(sha)"; commit docs/X.md; green_runs "$base0"; plan workflow_dispatch refs/heads/main
check "target none → full" "full $S_ALL" "$o_mode $o_suites"

echo "== deploy guard (scripts/ci-deploy-guard.sh) =="
GUARD="${HERE}/../ci-deploy-guard.sh"
new_repo; old="$(sha)"; commit backend/app/A.php; new="$(sha)"
git init -q --bare "$TMP/origin$n.git"; git -C "$W" -c push.negotiate=false push -q "$TMP/origin$n.git" main
git -C "$W" remote add origin "$TMP/origin$n.git"
guard() { # guard EVENT ATTEMPT SHA [REMOTE] → prints the exit code
  local rc=0
  ( cd "$W" && EVENT_NAME="$1" RUN_ATTEMPT="$2" COMMIT_SHA="$3" DEPLOY_GUARD_REMOTE="${4:-origin}" \
      bash "$GUARD" >"$FIX/guard.out" 2>&1 ) || rc=$?
  echo "$rc"
}
check "push of main head → deploy" 0 "$(guard push 1 "$new")"
check "push of an older commit → refuse" 1 "$(guard push 1 "$old")"
if grep -q "refusing to deploy an older commit" "$FIX/guard.out"; then r=0; else r=1; fi
check "refusal says why" 0 "$r"
check "re-run of main head → deploy" 0 "$(guard push 2 "$new")"
check "re-run of an old run → refuse" 1 "$(guard push 3 "$old")"
check "dispatch (attempt 1) not checked" 0 "$(guard workflow_dispatch 1 "$old")"
check "dispatch re-run of an old commit → refuse" 1 "$(guard workflow_dispatch 2 "$old")"
check "unreadable remote → refuse" 1 "$(guard push 1 "$new" "$TMP/nope.git")"

echo
echo "ci-plan harness: ${pass} passed, ${fail} failed (${n} repos)"
[ "$fail" -eq 0 ]
