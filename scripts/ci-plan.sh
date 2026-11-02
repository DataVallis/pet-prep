#!/usr/bin/env bash
# CI plan for .github/workflows/deploy-production.yml (job "plan").
#
# Decides which suites run and whether the run may deploy (the deploy job additionally
# needs "Run workflow" with target production — scripts/ci-deploy-target.sh, D18). Every doubt resolves towards
# MORE testing: an unknown base, an API error or a missing marker means "run it".
#
#   1. Changed files (path filter)
#        pull_request       HEAD^1..HEAD of the PR merge commit = exactly what the PR
#                           changes against the current main.
#        push to main       base = head of the last successful "Run workflow" run of this
#                           workflow on main whose job "Deploy to Hetzner Production"
#                           SUCCEEDED = what production runs (since D18 only dispatch runs
#                           deploy, so only they are looked up). Not
#                           event.before and not merely the last green run: a queued run
#                           that GitHub cancels (concurrency), a failed deploy or a green
#                           run whose deploy was skipped (by design or by a bug — the
#                           2026-10-06 incident) never drops changes from the next diff.
#                           Base not found / not an ancestor (force push) / API error /
#                           diff error / empty diff between different trees → full run.
#        workflow_dispatch  full run (deploy=true on main; the job deploys only with
#                           target production — which then skips green suites, §4).
#        re-run (attempt>1) full run — "Re-run all jobs" of a production dispatch run repeats
#                           its deploy (only of the current main head: ci-deploy-guard.sh);
#                           a re-run of a push run deploys nothing.
#   2. Suites per path (see classify()):
#        backend + image    backend/**, deployment/**, scripts/** (except
#        + deploy (main)    generate-api-types.mjs), the workflow; backend/README.md and
#                           backend/CLAUDE.md ignored
#        backend + scripts  docs/research/dog-data/** (LifeStageDataTest reads data.json /
#                           sources.md; the seeder embeds the values, nothing ships → no
#                           image, no deploy; the breed registry export test reads them too)
#        backend only       docs/research/breed-facts/** (BreedFactImportTest: the shipped copy
#                           backend/database/data/breed-facts must match; no image, no deploy — M5-R09b)
#        mobile             mobile/**, scripts/generate-api-types.mjs, the workflow
#        scripts            scripts/**, deployment/**, the workflow,
#                           backend/config/breed_suitability.php, backend/config/breed_colours.php, mobile/src/i18n/locales/*/{pet,family}.json,
#                           docs/research/cat-data/** (also backend tests)
#                           docs/research/breed-portraits/**, docs/research/breeder-checklist/** and docs/research/breed-registry.json
#                           (inputs / output of scripts/export-breed-registry.mjs, M5-R11;
#                           breed-registry.json also runs the backend tests — BreedPortraitTest)
#        scripts only       scripts/export-breed-registry.mjs + its test (build-time export
#                           for the website; nothing ships → no image, no deploy)
#                           scripts/daily-deploy.sh + its test + .github/workflows/daily-deploy.yml
#                           (D21 daily deploy trigger; runs in Actions, nothing ships)
#        backend + scripts  scripts/ci-install-ffmpeg.sh + its test (D24: ffmpeg for the backend
#                           test job; runs in Actions, nothing ships → no image, no deploy);
#                           scripts/ci-pest-shard.mjs + its test (D28b shard plan, same)
#   3. Tested tree (push to main; production dispatch: §4): a needed suite is skipped when a pull_request
#      run of THIS workflow, from this repository (no forks), with conclusion success,
#      ran that suite on the identical git tree — marker artifact
#      "ci-green-<suite>-<tree>" uploaded by the suite job itself. PR runs test the
#      merge commit, so its tree equals main's tree after the merge unless main moved
#      in between (then the trees differ and everything needed runs again). Applies to
#      push runs in full mode too (a marker proves the exact tree, whatever the base).
#      pull_request runs never skip on markers.
#   3b. Merged PR proof (push to main, D28b): when main moved between the PR's green run and
#      the merge, the trees differ and no marker matches. A suite is still skipped when
#      the commit's merged PR (same repo) has a successful pull_request run on its final
#      head that uploaded the suite's marker, that marker records the PR merge commit's
#      first parent B ("base=" line), B is an ancestor of HEAD^1, and nothing between B and
#      HEAD^1 touches the suite's paths (classify) — then HEAD's files for that suite are
#      exactly the ones the PR run tested. The plan job uploads a marker for HEAD's tree
#      ("derived from run N", image digests copied) so the deploy finds it. Any doubt
#      (no PR, no base line, not an ancestor, API / git error) → the suite runs.
#   4. Production deploy reuses the tested tree (D28, David 2026-11-02: "deploy max 10 min"):
#      a "Run workflow" on main with DEPLOY_TARGET=production, attempt 1 and FORCE_TESTS not
#      "true" skips every suite already green on exactly this tree in a successful run of
#      this workflow from this repository — a pull_request, push or earlier dispatch run
#      (every suite job uploads its marker since D28). The daily deploy only dispatches
#      after the push CI of the main head is green, so it normally deploys without
#      re-testing. Like a push it diffs against the last production deploy (suites whose
#      paths did not change since then are not needed; the image always is — the deploy
#      needs its digests). The image run's id is output as image_run: its marker holds the pushed
#      image DIGESTS the deploy pulls (scripts/ci-image-refs.sh). No marker / API error →
#      the suite runs (fail closed). Target none
#      (the "test everything" button), FORCE_TESTS=true and re-runs still test everything.
#
# Env: EVENT_NAME, GIT_REF, RUN_ATTEMPT, REPO (owner/name), WORKFLOW_FILE (basename),
# DEPLOY_TARGET (none / production, from ci-deploy-target.sh), FORCE_TESTS (true / false),
# DEPLOY_JOB_NAME (default "Deploy to Hetzner Production"), GH_TOKEN (for gh),
# GITHUB_OUTPUT, GITHUB_STEP_SUMMARY (optional). Test hooks: CI_PLAN_GH (gh binary).
# Output (GITHUB_OUTPUT): tree, base, mode, backend, mobile, scripts, image, deploy, image_run
# (each suite/deploy "true"/"false"). The whole decision is also printed as a
# "::notice title=plan::" annotation (readable through the check-runs API).
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
GIT_REF="${GIT_REF:?GIT_REF is required}"
REPO="${REPO:?REPO is required}"
WORKFLOW_FILE="${WORKFLOW_FILE:-deploy-production.yml}"
GH="${CI_PLAN_GH:-gh}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
WORKFLOW_PATH=".github/workflows/${WORKFLOW_FILE}"
DEPLOY_JOB_NAME="${DEPLOY_JOB_NAME:-Deploy to Hetzner Production}"
DEPLOY_TARGET="${DEPLOY_TARGET:-none}"
FORCE_TESTS="${FORCE_TESTS:-false}"

log() { echo "ci-plan: $*" >&2; }

# last_deployed_sha: head sha of the newest successful "Run workflow" run on main (this
# repository) in which the deploy job succeeded — since D18 push runs never deploy, so
# only dispatch runs are listed (event filter in the API query + the jq select). Prints
# nothing when none of the last 50 green dispatch runs deployed; returns 1 on any API error.
last_deployed_sha() {
  local runs id sha deployed
  runs="$("$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?branch=main&event=workflow_dispatch&status=success&per_page=50" \
        --jq '.workflow_runs[] | select(.event == "workflow_dispatch") | select(.head_repository.full_name == "'"${REPO}"'") | "\(.id) \(.head_sha)"' \
        2>/dev/null)" || return 1
  while read -r id sha; do
    [ -n "$id" ] || continue
    deployed="$("$GH" api "repos/${REPO}/actions/runs/${id}/jobs?per_page=100" \
          --jq '[.jobs[] | select(.name == "'"${DEPLOY_JOB_NAME}"'" and .conclusion == "success")] | length' \
          2>/dev/null)" || return 1
    if [ "${deployed:-0}" -gt 0 ]; then
      echo "$sha"
      return 0
    fi
  done <<< "$runs"
}

tree="$(git rev-parse 'HEAD^{tree}')"
mode="full"
base=""
reason=""

case "$EVENT_NAME" in
  pull_request)
    if git rev-parse -q --verify 'HEAD^2' >/dev/null; then
      base="$(git rev-parse 'HEAD^1')"
      mode="diff"
    else
      reason="PR checkout is not a merge commit"
    fi
    ;;
  push)
    if [ "${RUN_ATTEMPT:-1}" != 1 ]; then
      # "Re-run all jobs": the last green run may be this very commit (empty diff), and a
      # re-run is how a deploy is repeated — test and deploy everything.
      reason="re-run (attempt ${RUN_ATTEMPT})"
    elif [ "$GIT_REF" = "refs/heads/main" ]; then
      if ! base="$(last_deployed_sha)"; then
        base=""
        reason="GitHub API error while looking up the last deployed main run"
      elif [ -z "$base" ]; then
        reason="no successful production deploy among the last 50 green Run workflow runs on main"
      elif git merge-base --is-ancestor "$base" HEAD 2>/dev/null; then
        mode="diff"
      else
        reason="last deployed main commit ${base} is not an ancestor of HEAD"
      fi
    else
      reason="push outside main"
    fi
    ;;
  workflow_dispatch)
    if [ "$GIT_REF" = "refs/heads/main" ] && [ "$DEPLOY_TARGET" = production ] \
       && [ "${RUN_ATTEMPT:-1}" = 1 ] && [ "$FORCE_TESTS" != true ]; then
      if ! base="$(last_deployed_sha)"; then
        base=""; reason="GitHub API error while looking up the last deployed main run"
      elif [ -z "$base" ]; then
        reason="no successful production deploy among the last 50 green Run workflow runs on main"
      elif git merge-base --is-ancestor "$base" HEAD 2>/dev/null; then
        mode="diff"
      else
        reason="last deployed main commit ${base} is not an ancestor of HEAD"
      fi
    else
      reason="event ${EVENT_NAME}"
    fi
    ;;
  *)
    reason="event ${EVENT_NAME}"
    ;;
esac

need_backend=false need_mobile=false need_scripts=false need_deploy=false

# classify FILE...: sets need_* from the changed paths.
classify() {
  local f
  for f in "$@"; do
    case "$f" in
      "$WORKFLOW_PATH") need_backend=true need_deploy=true need_mobile=true need_scripts=true ;;
      backend/README.md|backend/CLAUDE.md) ;; # docs next to the code
      backend/config/breed_suitability.php|backend/config/breed_colours.php) need_backend=true need_deploy=true need_scripts=true ;; # + breed registry export (suitability tags, portrait colour labels)
      backend/*) need_backend=true need_deploy=true ;;
      docs/research/dog-data/*) need_backend=true need_scripts=true ;; # test fixtures + export input, nothing ships
      docs/research/cat-data/*) need_backend=true need_scripts=true ;; # cat test fixtures + export input, nothing ships
      docs/research/breed-facts/*) need_backend=true ;; # canonical breed facts: BreedFactImportTest checks the shipped copy backend/database/data/breed-facts (M5-R09b)
      docs/research/breed-registry.json) need_backend=true need_scripts=true ;; # export output: its test + BreedPortraitTest (register breeds), nothing ships
      docs/research/care/*|docs/research/care-knowledge.json|scripts/export-care-knowledge.mjs|scripts/tests/export-care-knowledge.test.mjs) need_scripts=true ;; # care knowledge for the website (M6-K), nothing ships
      docs/research/breeder-checklist/*) need_scripts=true ;; # breeder checklist (export input, M5-R11-06), nothing ships
      docs/research/breed-portraits/*) need_scripts=true ;; # AI breed portraits + manifest (export input, M5-R11-04), nothing ships
      deployment/*) need_backend=true need_deploy=true need_scripts=true ;;
      scripts/generate-api-types.mjs) need_mobile=true ;;
      scripts/export-breed-registry.mjs|scripts/tests/export-breed-registry.test.mjs) need_scripts=true ;; # website export, nothing ships
      scripts/daily-deploy.sh|scripts/tests/daily-deploy.test.sh|.github/workflows/daily-deploy.yml) need_scripts=true ;; # D21 daily deploy trigger (Actions only), nothing ships
      scripts/ci-install-ffmpeg.sh|scripts/tests/ci-install-ffmpeg.test.sh) need_backend=true need_scripts=true ;;
      scripts/ci-pest-shard.mjs|scripts/tests/ci-pest-shard.test.mjs) need_backend=true need_scripts=true ;; # D28b Pest shard plan (Actions only), nothing ships # D24 ffmpeg for the backend test job (Actions only), nothing ships
      scripts/breeds-index.mjs|scripts/tests/breeds-index.test.mjs|docs/breeds/*) need_scripts=true ;; # docs/breeds index (one doc per breed), nothing ships
      scripts/*) need_backend=true need_deploy=true need_scripts=true ;;
      mobile/src/i18n/locales/*/pet.json|mobile/src/i18n/locales/*/family.json) need_mobile=true need_scripts=true ;; # + breed registry labels / names
      mobile/*) need_mobile=true ;;
    esac
  done
}

files=()
if [ "$mode" = diff ]; then
  # Fail closed: a failing diff (e.g. a partial clone that cannot fetch an object) or an
  # empty file list between two different trees means we don't know what changed.
  if ! changed="$(git diff --name-only --no-renames "$base" HEAD)"; then
    mode="full"; reason="git diff ${base} HEAD failed"
  elif [ -z "$changed" ] && [ "$(git rev-parse "${base}^{tree}")" != "$tree" ]; then
    mode="full"; reason="empty diff although ${base} has a different tree"
  else
    [ -n "$changed" ] && mapfile -t files <<< "$changed"
  fi
fi
if [ "$mode" = diff ]; then
  classify "${files[@]}"
  log "base ${base}, ${#files[@]} changed file(s)"
else
  need_backend=true need_deploy=true need_mobile=true need_scripts=true
  files=()
  log "full run: ${reason}"
fi
need_image="$need_deploy" # the image holds what ships (backend/), not research fixtures
# A production deploy always needs the image digests (D28b): verified by a marker or built.
if [ "$EVENT_NAME" = workflow_dispatch ]; then need_image=true; fi

deploy=false
if [ "$GIT_REF" = "refs/heads/main" ]; then
  case "$EVENT_NAME" in
    workflow_dispatch) deploy=true ;;
    push) deploy="$need_deploy" ;;
  esac
fi

# green_on_pr SUITE [EVENTS]: true when a successful same-repo run of this workflow with
# one of EVENTS (jq array, default only pull_request) ran SUITE on exactly this tree.
# Any API error → false (the suite runs).
green_on_pr() {
  local suite="$1" events="${2:-[\"pull_request\"]}" ids id ok
  ids="$("$GH" api "repos/${REPO}/actions/artifacts?name=ci-green-${suite}-${tree}&per_page=30" \
        --jq '.artifacts[] | select(.expired | not) | select(.workflow_run.head_repository_id == .workflow_run.repository_id) | .workflow_run.id' \
        2>/dev/null)" || return 1
  for id in $ids; do
    # shellcheck disable=SC2016 # $e is a jq variable, not a shell one
    ok="$("$GH" api "repos/${REPO}/actions/runs/${id}" \
          --jq 'select((.event as $e | '"${events}"' | index($e)) and .status == "completed" and .conclusion == "success" and ((.path // "") | split("@")[0]) == "'"${WORKFLOW_PATH}"'") | .id' \
          2>/dev/null)" || continue
    if [ "$ok" = "$id" ]; then
      log "${suite}: tree ${tree} already green in run ${id}"
      verified_by="${verified_by}${suite}=run ${id} "
      [ "$suite" = image ] && image_run="$id"
      return 0
    fi
  done
  return 1
}

# derive_from_pr: §3b. Sets derived_<suite>=<PR run id> for every planned suite it proves;
# writes the derived marker files to $DERIVED_DIR/<suite>/ci-green.txt. Returns 0 always.
DERIVED_DIR="${CI_PLAN_DERIVED_DIR:-${RUNNER_TEMP:-/tmp}/ci-derived}"
derived_backend="" derived_mobile="" derived_scripts="" derived_image=""
fetch_marker() { # fetch_marker URL DIR → DIR/ci-green.txt
  rm -rf "$2"; mkdir -p "$2"
  "$GH" api "$1" > "$2/m.zip" 2>/dev/null && unzip -q -o "$2/m.zip" -d "$2" 2>/dev/null && [ -f "$2/ci-green.txt" ]
}
derive_from_pr() {
  local head parent pr prhead runs id arts suite url pbase between f
  local b_need_backend b_need_mobile b_need_scripts b_need_deploy
  head="$(git rev-parse HEAD)"
  parent="$(git rev-parse -q --verify 'HEAD^1' 2>/dev/null)" || { log "derive: no parent"; return 0; }
  pr="$("$GH" api "repos/${REPO}/commits/${head}/pulls" \
        --jq '[.[] | select(.merged_at != null and .base.ref == "main" and .head.repo.full_name == "'"${REPO}"'")][0] | select(. != null) | "\(.number) \(.head.sha)"' \
        2>/dev/null)" || { log "derive: PR lookup failed"; return 0; }
  [ -n "$pr" ] || { log "derive: no merged same-repo PR for ${head}"; return 0; }
  prhead="${pr#* }"
  [[ "$prhead" =~ ^[0-9a-f]{40}$ ]] || return 0
  runs="$("$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?event=pull_request&head_sha=${prhead}&status=success&per_page=10" \
        --jq '.workflow_runs[] | select(.event == "pull_request" and .conclusion == "success" and .head_repository.full_name == "'"${REPO}"'") | .id' \
        2>/dev/null)" || { log "derive: PR runs lookup failed"; return 0; }
  for id in $runs; do
    arts="$("$GH" api "repos/${REPO}/actions/runs/${id}/artifacts?per_page=100" \
          --jq '.artifacts[] | select(.expired | not) | select(.name | startswith("ci-green-")) | "\(.name) \(.archive_download_url)"' \
          2>/dev/null)" || continue
    [ -n "$arts" ] || continue
    url="$(head -n1 <<< "$arts" | cut -d' ' -f2)"
    fetch_marker "$url" "${DERIVED_DIR}/probe" || continue
    pbase="$(sed -n 's/^base=//p' "${DERIVED_DIR}/probe/ci-green.txt" | head -n1)"
    if ! [[ "$pbase" =~ ^[0-9a-f]{40}$ ]]; then log "derive: run ${id} marker has no base line"; continue; fi
    if ! git merge-base --is-ancestor "$pbase" "$parent" 2>/dev/null; then log "derive: base ${pbase} not an ancestor of HEAD^1"; continue; fi
    if ! between="$(git diff --name-only --no-renames "$pbase" "$parent")"; then log "derive: git diff failed"; continue; fi
    # Classify what landed on main between the PR's test base and the merge (own flags).
    b_need_backend=$need_backend b_need_mobile=$need_mobile b_need_scripts=$need_scripts b_need_deploy=$need_deploy
    need_backend=false need_mobile=false need_scripts=false need_deploy=false
    if [ -n "$between" ]; then mapfile -t bfiles <<< "$between"; classify "${bfiles[@]}"; fi
    local m_backend=$need_backend m_mobile=$need_mobile m_scripts=$need_scripts m_image=$need_deploy
    need_backend=$b_need_backend need_mobile=$b_need_mobile need_scripts=$b_need_scripts need_deploy=$b_need_deploy
    log "derive: PR run ${id} base ${pbase}; between base and HEAD^1: backend=${m_backend} mobile=${m_mobile} scripts=${m_scripts} image=${m_image}"
    for suite in backend mobile scripts image; do
      local runvar="run_${suite}" mvar="m_${suite}"
      [ "${!runvar}" = true ] && [ "${!mvar}" = false ] || continue
      f="$(grep -m1 "^ci-green-${suite}-" <<< "$arts" | cut -d' ' -f2)" || true
      [ -n "$f" ] || continue
      fetch_marker "$f" "${DERIVED_DIR}/src-${suite}" || continue
      [ "$(sed -n 's/^base=//p' "${DERIVED_DIR}/src-${suite}/ci-green.txt" | head -n1)" = "$pbase" ] || continue
      mkdir -p "${DERIVED_DIR}/${suite}"
      { echo "${tree} ${GITHUB_RUN_ID:-0}"; echo "derived-from=${id} base=${pbase}"
        grep -E '^(app|web)=' "${DERIVED_DIR}/src-${suite}/ci-green.txt" || true
      } > "${DERIVED_DIR}/${suite}/ci-green.txt"
      printf -v "$runvar" false
      printf -v "derived_${suite}" '%s' "$id"
      verified_by="${verified_by}${suite}=PR run ${id} (main moved: paths untouched) "
      if [ "$suite" = image ]; then image_run="${GITHUB_RUN_ID:-}"; fi
    done
    return 0
  done
  log "derive: no usable green PR run for ${prhead}"
  return 0
}

verified_by=""
image_run=""
run_backend="$need_backend" run_mobile="$need_mobile" run_scripts="$need_scripts" run_image="$need_image"
if [ "$EVENT_NAME" = push ] && [ "$GIT_REF" = "refs/heads/main" ]; then
  if [ "$run_backend" = true ] && green_on_pr backend; then run_backend=false; fi
  if [ "$run_mobile" = true ] && green_on_pr mobile; then run_mobile=false; fi
  if [ "$run_scripts" = true ] && green_on_pr scripts; then run_scripts=false; fi
  if [ "$run_image" = true ] && green_on_pr image; then run_image=false; fi
  if [ "$run_backend$run_mobile$run_scripts$run_image" != falsefalsefalsefalse ]; then derive_from_pr; fi
elif [ "$EVENT_NAME" = workflow_dispatch ] && [ "$GIT_REF" = "refs/heads/main" ] \
     && [ "$DEPLOY_TARGET" = production ] && [ "${RUN_ATTEMPT:-1}" = 1 ] && [ "$FORCE_TESTS" != true ]; then
  any='["pull_request","push","workflow_dispatch"]'
  if [ "$run_backend" = true ] && green_on_pr backend "$any"; then run_backend=false; fi
  if [ "$run_mobile" = true ] && green_on_pr mobile "$any"; then run_mobile=false; fi
  if [ "$run_scripts" = true ] && green_on_pr scripts "$any"; then run_scripts=false; fi
  if [ "$run_image" = true ] && green_on_pr image "$any"; then run_image=false; fi
fi

{
  echo "tree=${tree}"
  echo "base=${base}"
  echo "mode=${mode}"
  echo "backend=${run_backend}"
  echo "mobile=${run_mobile}"
  echo "scripts=${run_scripts}"
  echo "image=${run_image}"
  echo "deploy=${deploy}"
  echo "image_run=${image_run}"
  echo "derived_backend=${derived_backend:+true}"
  echo "derived_mobile=${derived_mobile:+true}"
  echo "derived_scripts=${derived_scripts:+true}"
  echo "derived_image=${derived_image:+true}"
  echo "derived_dir=${DERIVED_DIR}"
} >> "$OUT"

{
  echo "### CI plan"
  echo ""
  echo "| | |"
  echo "|---|---|"
  echo "| event | \`${EVENT_NAME}\` on \`${GIT_REF}\` |"
  echo "| tree | \`${tree}\` |"
  if [ "$mode" = diff ]; then
    echo "| base | \`${base}\` (${#files[@]} changed files) |"
  else
    echo "| base | full run — ${reason} |"
  fi
  echo "| needed by paths | backend ${need_backend} · mobile ${need_mobile} · scripts ${need_scripts} · image ${need_image} |"
  echo "| runs here | backend ${run_backend} · mobile ${run_mobile} · scripts ${run_scripts} · image ${run_image} |"
  [ -n "$verified_by" ] && echo "| already green (same tree) | ${verified_by}|"
  echo "| deploy | ${deploy} |"
} >> "$SUMMARY"

# One-line decision as a workflow annotation (stdout; GitHub reads "::notice::"), so it
# can be read without the job log (check-runs annotations API). % / CR / LF escaped.
notice="event=${EVENT_NAME} ref=${GIT_REF} attempt=${RUN_ATTEMPT:-1} target=${DEPLOY_TARGET} force_tests=${FORCE_TESTS} mode=${mode} base=${base:-none} reason=${reason:-none} changed_files=${#files[@]} tree=${tree}"
notice="${notice} need: backend=${need_backend} mobile=${need_mobile} scripts=${need_scripts} image=${need_image} deploy_paths=${need_deploy}"
notice="${notice} run: backend=${run_backend} mobile=${run_mobile} scripts=${run_scripts} image=${run_image} green_on_pr=${verified_by:-none} deploy=${deploy}"
notice="${notice//'%'/'%25'}"; notice="${notice//$'\r'/'%0D'}"; notice="${notice//$'\n'/'%0A'}"
echo "::notice title=plan::${notice}"
