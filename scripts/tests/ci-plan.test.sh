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
# Also covers scripts/ci-deploy-guard.sh against a local bare "origin".
#
# Run: bash scripts/tests/ci-plan.test.sh   (needs bash, git, jq)
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
green_runs() { # green_runs SHA [EVENT] [REPO_FULL]: one successful main run that deployed
  jq -n --arg s "$1" --arg e "${2:-push}" --arg r "${3:-$REPO_NAME}" \
    '{workflow_runs:[{id:1,event:$e,head_sha:$s,head_repository:{full_name:$r}}]}' > "$FIX/runs.json"
  jobs_json 1 success
}
jobs_json() { # jobs_json RUN_ID DEPLOY_CONCLUSION (success / skipped / failure)
  jq -n --arg c "$2" '{jobs:[{name:"Plan (changed paths, tested tree)",conclusion:"success"},
    {name:"CI OK",conclusion:"success"},{name:"Deploy to Hetzner Production",conclusion:$c}]}' > "$FIX/jobs-$1.json"
}
runs_list() { # runs_list "ID SHA" ...: successful main push runs, newest first
  local r
  for r in "$@"; do echo "$r"; done | jq -R 'split(" ") | {id:(.[0]|tonumber),event:"push",head_sha:.[1],head_repository:{full_name:"'"$REPO_NAME"'"}}' \
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
  ( cd "$W" && PATH="$TMP/bin:$PATH" FIX="$fix" EVENT_NAME="$1" GIT_REF="$2" RUN_ATTEMPT="${3:-1}" \
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
S_BTESTS="backend=true mobile=false scripts=false image=false "
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
pr_case "breed registry output: scripts tests only" "$S_SCRIPTS" docs/research/breed-registry.json
pr_case "breed suitability config: backend + scripts" "$S_BSCRIPTS" backend/config/breed_suitability.php
pr_case "app pet strings: mobile + scripts" "backend=false mobile=true scripts=true image=false " mobile/src/i18n/locales/sl/pet.json
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
deploy_if="$(awk '/^  deploy:/{d=1} d&&/^    if:/{f=1} f{print} f&&/^    [a-z-]+:/&&!/^    if:/{exit}' "$WFFILE" | tr -s ' \n' ' ')"
for want in "!cancelled()" "needs.plan.result == 'success'" "needs.ci-ok.result == 'success'" "needs.plan.outputs.deploy == 'true'"; do
  case "$deploy_if" in *"$want"*) r=0 ;; *) r=1 ;; esac
  check "deploy if: has $want" 0 "$r"
done

echo "== push to main: a failed / cancelled run in between is not lost =="
new_repo; base="$(sha)"; commit backend/app/A.php; commit docs/X.md; green_runs "$base"
plan push refs/heads/main
check "backend change from the red run is in the diff" "$S_BACKEND" "$o_suites"; check "deploy" true "$o_deploy"

echo "== push to main: green run filter (event / repo) =="
new_repo; base="$(sha)"; commit backend/app/A.php; green_runs "$base" workflow_dispatch
plan push refs/heads/main
check "workflow_dispatch run counts as green base" diff "$o_mode"
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
if grep -q "already green on the PR.*run 77" "$FIX/summary"; then r=0; else r=1; fi
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
