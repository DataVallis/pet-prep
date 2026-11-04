#!/usr/bin/env bash
# Plan of .github/workflows/mobile-ota.yml (D18, David 2026-10-14; D33 2026-11-04): publish an
# OTA? to which channel?
#
# Production deploys → production OTAs (never a plain merge, D18 / D24):
#   CI run of a push to main               → nothing (a merge publishes nothing)
#   CI "Run workflow", green, whose job "Deploy to Hetzner Production" succeeded (event
#   workflow_run — GitHub creates it only for runs started with a user token, i.e. David's
#   manual deploys)                        → after_deploy (below)
#   "Mobile OTA update" dispatched by CI & Deploy's job "Mobile OTA (after the production
#   deploy)" with deploy_run_id (D33 — after EVERY production deploy, also the daily one: a
#   run started with GITHUB_TOKEN creates no workflow_run event, so the daily deploy never
#   reached the path above)                → the run must be a deploy-production.yml
#                                            workflow_dispatch run on main of this repository,
#                                            HEAD must be its head_sha and its deploy job must
#                                            have succeeded (else red); a NEWER successful
#                                            production deploy → nothing (that deploy's own
#                                            OTA publishes) → after_deploy
#   after_deploy                           → channel production, if mobile/ differs from the
#                                            commit of the last production release
#                                            (docs/research/app-release.json, read from the
#                                            CURRENT origin/main — unknown → yes). Idempotent
#                                            (D33): HEAD already contained in the recorded
#                                            release (that commit or an ancestor of it) →
#                                            nothing, so the two OTA runs of a manual deploy
#                                            publish once.
#   other CI runs (target none, red, …)    → nothing
#   manual "Run workflow" of mobile-ota.yml (no deploy_run_id) → the explicit `channel` input:
#                                            preview    → only the current head of main
#                                            production → only when HEAD is the commit of
#                                                         the last successful production
#                                                         deploy (refused otherwise)
#                                            (dry runs excepted from both checks).
#
# Env: EVENT_NAME (workflow_run | workflow_dispatch), WR_EVENT, WR_CONCLUSION, WR_RUN_ID
# (the CI run), INPUT_CHANNEL, DEPLOY_RUN_ID (dispatch input, D33), DRY_RUN (true|false), REPO,
# GH_TOKEN, GITHUB_OUTPUT.
# Test hooks: OTA_PLAN_GH (gh binary), OTA_PLAN_REMOTE (default origin), OTA_PLAN_JOB_TRIES (5) /
# OTA_PLAN_JOB_WAIT (20 s): re-reads of the CI run's jobs before "without a production deploy".
# Output: mobile=true|false, channel=production|preview|"", sha=<HEAD> (the commit published and
# recorded).
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
DRY_RUN="${DRY_RUN:-false}"
DEPLOY_RUN_ID="${DEPLOY_RUN_ID:-}"
GH="${OTA_PLAN_GH:-gh}"
REMOTE="${OTA_PLAN_REMOTE:-origin}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"
PROD_JOB="Deploy to Hetzner Production"
DEPLOY_WORKFLOW=".github/workflows/deploy-production.yml"
RELEASE_FILE="docs/research/app-release.json"

mobile=false
channel=""
reason=""

# prod_jobs RUN_ID: number of successful "Deploy to Hetzner Production" jobs of the run.
# API error → ::error, exit 1 (never publish on a doubt).
prod_jobs() {
  local n
  if ! n="$("$GH" api "repos/${REPO}/actions/runs/$1/jobs?per_page=100" \
        --jq '[.jobs[] | select(.name == "'"${PROD_JOB}"'" and .conclusion == "success")] | length' 2>/dev/null)"; then
    echo "::error::cannot read the jobs of CI run $1 — not publishing to production" >&2
    exit 1
  fi
  echo "${n:-0}"
}

# deploy_job_ok RUN_ID: 0 when the run's production deploy job succeeded.
# 2026-10-23: right after the run completed, the jobs API once answered without the
# successful production deploy job (run 39913945780 → OTA skipped as "without a production
# deploy"; a re-read a few minutes later showed it). Re-read a few times before deciding.
deploy_job_ok() {
  local tries="${OTA_PLAN_JOB_TRIES:-5}" wait_s="${OTA_PLAN_JOB_WAIT:-20}" try n
  for ((try = 1; try <= tries; try++)); do
    n="$(prod_jobs "$1")" || exit 1
    [ "$n" -gt 0 ] && return 0
    if [ "$try" -lt "$tries" ]; then
      echo "Production deploy job of CI run $1 not seen as successful yet (try ${try}/${tries}) — re-reading in ${wait_s} s"
      sleep "$wait_s"
    fi
  done
  return 1
}

# after_deploy: HEAD is the commit a production deploy put live → channel production; publish
# when mobile/ changed since the last recorded release. The record is read from the CURRENT
# main (D33): the bot commits it to main after a publish, so the checkout of the deployed
# commit does not have it — and a manual deploy starts two OTA runs (workflow_run + the
# dispatch from CI & Deploy); the second must see the first one's record.
after_deploy() {
  local head last rel
  channel=production
  head="$(git rev-parse HEAD)"
  if git fetch -q "$REMOTE" main 2>/dev/null; then
    rel="$(git show "FETCH_HEAD:${RELEASE_FILE}" 2>/dev/null || true)"
  else
    echo "::warning::cannot fetch ${REMOTE} main — using ${RELEASE_FILE} of the checkout (a release recorded after this commit is not seen)"
    rel="$(cat "$RELEASE_FILE" 2>/dev/null || true)"
  fi
  last="$(printf '%s' "$rel" | jq -r '.commit // empty' 2>/dev/null || true)"
  if [ -z "$last" ] || ! git cat-file -e "${last}^{commit}" 2>/dev/null; then
    mobile=true; reason="production deploy; last release commit unknown → check"
  elif git merge-base --is-ancestor "$head" "$last"; then
    reason="production deploy; ${head:0:7} is already released (recorded release ${last:0:7} contains it) — published by another OTA run"
  elif ! git diff --quiet "$last" HEAD -- mobile/; then
    mobile=true; reason="production deploy; mobile/ changed since release ${last:0:7}"
  else
    reason="production deploy; mobile/ unchanged since release ${last:0:7}"
  fi
}

if [ "$EVENT_NAME" = workflow_dispatch ] && [ -n "$DEPLOY_RUN_ID" ]; then
  # D33: dispatched by CI & Deploy right after its production deploy job succeeded.
  REPO="${REPO:?REPO is required}"
  if [ "${INPUT_CHANNEL:-}" != production ]; then
    echo "::error::deploy_run_id needs channel production (got '${INPUT_CHANNEL:-}')"; exit 1
  fi
  if ! [[ "$DEPLOY_RUN_ID" =~ ^[0-9]+$ ]]; then
    echo "::error::deploy_run_id must be a run id (got '${DEPLOY_RUN_ID}')"; exit 1
  fi
  if ! run="$("$GH" api "repos/${REPO}/actions/runs/${DEPLOY_RUN_ID}" \
        --jq '"\(.path) \(.event) \(.head_branch) \(.head_repository.full_name) \(.head_sha) \(.created_at)"' 2>/dev/null)" \
     || [ -z "$run" ]; then
    echo "::error::cannot read CI run ${DEPLOY_RUN_ID} — not publishing to production"; exit 1
  fi
  read -r run_path run_event run_branch run_repo run_sha run_created <<< "$run"
  if [ "$run_path" != "$DEPLOY_WORKFLOW" ] || [ "$run_event" != workflow_dispatch ] \
     || [ "$run_branch" != main ] || [ "$run_repo" != "$REPO" ]; then
    echo "::error::run ${DEPLOY_RUN_ID} is not a CI & Deploy Run workflow on main of ${REPO} (${run_path} ${run_event} ${run_branch} ${run_repo}) — not publishing"
    exit 1
  fi
  if [ "$(git rev-parse HEAD)" != "$run_sha" ]; then
    echo "::error::checked out $(git rev-parse --short HEAD), but run ${DEPLOY_RUN_ID} deployed ${run_sha:0:7} — not publishing"
    exit 1
  fi
  if ! deploy_job_ok "$DEPLOY_RUN_ID"; then
    echo "::error::the production deploy job of run ${DEPLOY_RUN_ID} did not succeed — not publishing"
    exit 1
  fi
  # A newer production deploy replaced this commit on the server: never send its JS.
  if ! newer="$("$GH" api "repos/${REPO}/actions/workflows/deploy-production.yml/runs?branch=main&event=workflow_dispatch&per_page=30" \
        --jq '.workflow_runs[] | select(.event == "workflow_dispatch" and .id != '"${DEPLOY_RUN_ID}"' and .created_at > "'"${run_created}"'") | select(.head_repository.full_name == "'"${REPO}"'") | .id' 2>/dev/null)"; then
    echo "::error::cannot list the CI runs — not publishing to production"; exit 1
  fi
  newer_deploy=""
  while read -r id; do
    [ -n "$id" ] || continue
    n="$(prod_jobs "$id")" || exit 1
    if [ "$n" -gt 0 ]; then newer_deploy="$id"; break; fi
  done <<< "$newer"
  if [ -n "$newer_deploy" ]; then
    channel=production
    reason="a newer production deploy (run ${newer_deploy}) replaced ${run_sha:0:7} — its own OTA publishes"
  else
    after_deploy
    reason="after production deploy run ${DEPLOY_RUN_ID}: ${reason}"
  fi
elif [ "$EVENT_NAME" = workflow_dispatch ]; then
  case "${INPUT_CHANNEL:-}" in
    production|preview) channel="$INPUT_CHANNEL" ;;
    *) echo "::error::channel must be production or preview (got '${INPUT_CHANNEL:-}')"; exit 1 ;;
  esac
  mobile=true; reason="manual run (channel ${channel})"
  if [ "$channel" = production ] && [ "$DRY_RUN" != true ]; then
    # Production users may only get JS of the commit production runs: HEAD must be the
    # head_sha of the newest green "Run workflow" run whose production deploy succeeded
    # (same lookup as scripts/ci-plan.sh last_deployed_sha).
    REPO="${REPO:?REPO is required}"
    deployed=""
    if ! runs="$("$GH" api "repos/${REPO}/actions/workflows/deploy-production.yml/runs?branch=main&event=workflow_dispatch&status=success&per_page=50" \
          --jq '.workflow_runs[] | select(.event == "workflow_dispatch") | select(.head_repository.full_name == "'"${REPO}"'") | "\(.id) \(.head_sha)"' 2>/dev/null)"; then
      echo "::error::cannot list the CI runs — not publishing to production"; exit 1
    fi
    while read -r id sha; do
      [ -n "$id" ] || continue
      n="$(prod_jobs "$id")" || exit 1
      if [ "$n" -gt 0 ]; then deployed="$sha"; break; fi
    done <<< "$runs"
    if [ "$(git rev-parse HEAD)" != "$deployed" ]; then
      echo "::error::$(git rev-parse --short HEAD) is not the commit production runs (${deployed:0:7}${deployed:+ }from the last production deploy) — deploy production first (CI & Deploy → Run workflow → target production) or use channel preview."
      exit 1
    fi
    reason="manual run (channel production, = last production deploy ${deployed:0:7})"
  elif [ "$DRY_RUN" != true ]; then
    git fetch -q "$REMOTE" main   # full clone (blob:none) — no --depth, it would make it shallow
    if [ "$(git rev-parse HEAD)" != "$(git rev-parse FETCH_HEAD)" ]; then
      mobile=false; reason="$(git rev-parse --short HEAD) is not the head of main"
      echo "::notice title=Mobile OTA skipped::${reason}."
    fi
  fi
elif [ "${WR_CONCLUSION:-}" != success ]; then
  reason="CI run concluded '${WR_CONCLUSION:-?}'"
elif [ "${WR_EVENT:-}" = workflow_dispatch ]; then
  REPO="${REPO:?REPO is required}"
  WR_RUN_ID="${WR_RUN_ID:?WR_RUN_ID is required}"
  if deploy_job_ok "$WR_RUN_ID"; then
    after_deploy
  else
    reason="Run workflow without a production deploy"
  fi
else
  reason="CI ${WR_EVENT:-?} run — a merge publishes nothing (OTA follows a production deploy)"
fi

{
  echo "mobile=${mobile}"
  echo "channel=${channel}"
  echo "sha=$(git rev-parse HEAD)"
} >> "$OUT"
echo "::notice title=Mobile OTA plan::mobile=${mobile} channel=${channel:-none} — ${reason}"
