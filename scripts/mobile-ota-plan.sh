#!/usr/bin/env bash
# Plan of .github/workflows/mobile-ota.yml (D18, David 2026-10-14): publish an OTA? to which channel?
#
# Manual production deploys → manual production OTAs:
#   CI run of a push to main               → nothing (a merge publishes nothing)
#   CI "Run workflow", green, whose job "Deploy to Hetzner Production" succeeded
#                                          → channel production, if mobile/ differs from the
#                                            commit of the last production release
#                                            (docs/research/app-release.json; unknown → yes).
#                                            Publishes exactly the deployed commit.
#   other CI runs (target none, red, …)    → nothing
#   manual "Run workflow" of mobile-ota.yml → the explicit `channel` input:
#                                            preview    → only the current head of main
#                                            production → only when HEAD is the commit of
#                                                         the last successful production
#                                                         deploy (refused otherwise)
#                                            (dry runs excepted from both checks).
#
# Env: EVENT_NAME (workflow_run | workflow_dispatch), WR_EVENT, WR_CONCLUSION, WR_RUN_ID
# (the CI run), INPUT_CHANNEL, DRY_RUN (true|false), REPO, GH_TOKEN, GITHUB_OUTPUT.
# Test hooks: OTA_PLAN_GH (gh binary), OTA_PLAN_REMOTE (default origin), OTA_PLAN_JOB_TRIES (5) /
# OTA_PLAN_JOB_WAIT (20 s): re-reads of the CI run's jobs before "without a production deploy".
# Output: mobile=true|false, channel=production|preview|"".
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
DRY_RUN="${DRY_RUN:-false}"
GH="${OTA_PLAN_GH:-gh}"
REMOTE="${OTA_PLAN_REMOTE:-origin}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"
PROD_JOB="Deploy to Hetzner Production"
RELEASE_FILE="docs/research/app-release.json"

mobile=false
channel=""
reason=""

if [ "$EVENT_NAME" = workflow_dispatch ]; then
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
      if ! n="$("$GH" api "repos/${REPO}/actions/runs/${id}/jobs?per_page=100" \
            --jq '[.jobs[] | select(.name == "'"${PROD_JOB}"'" and .conclusion == "success")] | length' 2>/dev/null)"; then
        echo "::error::cannot read the jobs of CI run ${id} — not publishing to production"; exit 1
      fi
      if [ "${n:-0}" -gt 0 ]; then deployed="$sha"; break; fi
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
  # 2026-10-23: right after the run completed, the jobs API once answered without the
  # successful production deploy job (run 39913945780 → OTA skipped as "without a production
  # deploy"; a re-read a few minutes later showed it). Re-read a few times before deciding.
  tries="${OTA_PLAN_JOB_TRIES:-5}"
  wait_s="${OTA_PLAN_JOB_WAIT:-20}"
  prod=0
  for ((try = 1; try <= tries; try++)); do
    if ! prod="$("$GH" api "repos/${REPO}/actions/runs/${WR_RUN_ID}/jobs?per_page=100" \
          --jq '[.jobs[] | select(.name == "'"${PROD_JOB}"'" and .conclusion == "success")] | length' 2>/dev/null)"; then
      echo "::error::cannot read the jobs of CI run ${WR_RUN_ID} — not publishing to production"
      exit 1
    fi
    [ "${prod:-0}" -gt 0 ] && break
    if [ "$try" -lt "$tries" ]; then
      echo "Production deploy job of CI run ${WR_RUN_ID} not seen as successful yet (try ${try}/${tries}) — re-reading in ${wait_s} s"
      sleep "$wait_s"
    fi
  done
  if [ "${prod:-0}" -gt 0 ]; then
    channel=production
    last="$(node -e 'try{process.stdout.write(require(process.argv[1]).commit||"")}catch{}' "$PWD/${RELEASE_FILE}" 2>/dev/null || true)"
    if [ -z "$last" ] || ! git cat-file -e "${last}^{commit}" 2>/dev/null; then
      mobile=true; reason="production deploy; last release commit unknown → check"
    elif ! git diff --quiet "$last" HEAD -- mobile/; then
      mobile=true; reason="production deploy; mobile/ changed since release ${last:0:7}"
    else
      reason="production deploy; mobile/ unchanged since release ${last:0:7}"
    fi
  else
    reason="Run workflow without a production deploy"
  fi
else
  reason="CI ${WR_EVENT:-?} run — a merge publishes nothing (OTA follows a production deploy)"
fi

{
  echo "mobile=${mobile}"
  echo "channel=${channel}"
} >> "$OUT"
echo "::notice title=Mobile OTA plan::mobile=${mobile} channel=${channel:-none} — ${reason}"
