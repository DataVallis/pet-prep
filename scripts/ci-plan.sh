#!/usr/bin/env bash
# CI plan for .github/workflows/deploy-production.yml (job "plan").
#
# Decides which suites run and whether main deploys. Every doubt resolves towards
# MORE testing: an unknown base, an API error or a missing marker means "run it".
#
#   1. Changed files (path filter)
#        pull_request       HEAD^1..HEAD of the PR merge commit = exactly what the PR
#                           changes against the current main.
#        push to main       base = head of the last SUCCESSFUL run of this workflow on
#                           main (push / workflow_dispatch). Not event.before: a queued
#                           run that GitHub cancels (concurrency) or a failed deploy
#                           would otherwise drop its changes from the next diff. Base
#                           not found / not an ancestor (force push, rollback re-run)
#                           → full run.
#        workflow_dispatch  full run (+ deploy on main).
#        re-run (attempt>1) full run (+ deploy on main) — "Re-run all jobs" repeats a deploy.
#   2. Suites per path (see classify()):
#        backend + image    backend/**, deployment/**, scripts/** (except
#                           generate-api-types.mjs), the workflow; *.md under backend/ ignored
#        mobile             mobile/**, scripts/generate-api-types.mjs, the workflow
#        scripts            scripts/**, deployment/**, the workflow
#        deploy (main)      same paths as backend
#   3. Tested tree (push to main only): a needed suite is skipped when a pull_request
#      run of THIS workflow, from this repository (no forks), with conclusion success,
#      ran that suite on the identical git tree — marker artifact
#      "ci-green-<suite>-<tree>" uploaded by the suite job itself. PR runs test the
#      merge commit, so its tree equals main's tree after the merge unless main moved
#      in between (then the trees differ and everything needed runs again). Applies to
#      push runs in full mode too (a marker proves the exact tree, whatever the base).
#      pull_request and workflow_dispatch runs never skip on markers (dispatch = the
#      manual "test everything + deploy" button).
#
# Env: EVENT_NAME, GIT_REF, RUN_ATTEMPT, REPO (owner/name), WORKFLOW_FILE (basename), GH_TOKEN (for
# gh), GITHUB_OUTPUT, GITHUB_STEP_SUMMARY (optional). Test hooks: CI_PLAN_GH (gh binary).
# Output (GITHUB_OUTPUT): tree, base, mode, backend, mobile, scripts, image, deploy
# (each suite/deploy "true"/"false").
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
GIT_REF="${GIT_REF:?GIT_REF is required}"
REPO="${REPO:?REPO is required}"
WORKFLOW_FILE="${WORKFLOW_FILE:-deploy-production.yml}"
GH="${CI_PLAN_GH:-gh}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
WORKFLOW_PATH=".github/workflows/${WORKFLOW_FILE}"

log() { echo "ci-plan: $*" >&2; }

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
      if base="$("$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?branch=main&status=success&per_page=50" \
            --jq '.workflow_runs[] | select(.event == "push" or .event == "workflow_dispatch") | select(.head_repository.full_name == "'"${REPO}"'") | .head_sha' \
            2>/dev/null | head -n 1)" && [ -n "$base" ]; then
        if git merge-base --is-ancestor "$base" HEAD 2>/dev/null; then
          mode="diff"
        else
          reason="last green main commit ${base} is not an ancestor of HEAD"
        fi
      else
        base=""
        reason="no successful main run found (or API error)"
      fi
    else
      reason="push outside main"
    fi
    ;;
  *)
    reason="event ${EVENT_NAME}"
    ;;
esac

need_backend=false need_mobile=false need_scripts=false

# classify FILE...: sets need_* from the changed paths.
classify() {
  local f
  for f in "$@"; do
    case "$f" in
      "$WORKFLOW_PATH") need_backend=true need_mobile=true need_scripts=true ;;
      backend/*.md) ;; # docs next to the code (CLAUDE.md, README.md); `*` spans "/" here
      backend/*) need_backend=true ;;
      deployment/*) need_backend=true need_scripts=true ;;
      scripts/generate-api-types.mjs) need_mobile=true ;;
      scripts/*) need_backend=true need_scripts=true ;;
      mobile/*) need_mobile=true ;;
    esac
  done
}

if [ "$mode" = diff ]; then
  mapfile -t files < <(git diff --name-only --no-renames "$base" HEAD)
  classify "${files[@]}"
  log "base ${base}, ${#files[@]} changed file(s)"
else
  need_backend=true need_mobile=true need_scripts=true
  files=()
  log "full run: ${reason}"
fi
need_image="$need_backend"

deploy=false
if [ "$GIT_REF" = "refs/heads/main" ]; then
  case "$EVENT_NAME" in
    workflow_dispatch) deploy=true ;;
    push) deploy="$need_backend" ;;
  esac
fi

# green_on_pr SUITE: true when a successful same-repo PR run of this workflow ran SUITE
# on exactly this tree. Any API error → false (the suite runs).
green_on_pr() {
  local suite="$1" ids id ok
  ids="$("$GH" api "repos/${REPO}/actions/artifacts?name=ci-green-${suite}-${tree}&per_page=30" \
        --jq '.artifacts[] | select(.workflow_run.head_repository_id == .workflow_run.repository_id) | .workflow_run.id' \
        2>/dev/null)" || return 1
  for id in $ids; do
    ok="$("$GH" api "repos/${REPO}/actions/runs/${id}" \
          --jq 'select(.event == "pull_request" and .status == "completed" and .conclusion == "success" and ((.path // "") | split("@")[0]) == "'"${WORKFLOW_PATH}"'") | .id' \
          2>/dev/null)" || continue
    if [ "$ok" = "$id" ]; then
      log "${suite}: tree ${tree} already green in PR run ${id}"
      verified_by="${verified_by}${suite}=run ${id} "
      return 0
    fi
  done
  return 1
}

verified_by=""
run_backend="$need_backend" run_mobile="$need_mobile" run_scripts="$need_scripts" run_image="$need_image"
if [ "$EVENT_NAME" = push ] && [ "$GIT_REF" = "refs/heads/main" ]; then
  if [ "$run_backend" = true ] && green_on_pr backend; then run_backend=false; fi
  if [ "$run_mobile" = true ] && green_on_pr mobile; then run_mobile=false; fi
  if [ "$run_scripts" = true ] && green_on_pr scripts; then run_scripts=false; fi
  if [ "$run_image" = true ] && green_on_pr image; then run_image=false; fi
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
  [ -n "$verified_by" ] && echo "| already green on the PR (same tree) | ${verified_by}|"
  echo "| deploy | ${deploy} |"
} >> "$SUMMARY"
