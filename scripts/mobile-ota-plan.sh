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
#   manual "Run workflow" of mobile-ota.yml → the explicit `channel` input (production |
#                                            preview); only the current head of main is
#                                            published (dry runs excepted).
#
# Env: EVENT_NAME (workflow_run | workflow_dispatch), WR_EVENT, WR_CONCLUSION, WR_RUN_ID
# (the CI run), INPUT_CHANNEL, DRY_RUN (true|false), REPO, GH_TOKEN, GITHUB_OUTPUT.
# Test hooks: OTA_PLAN_GH (gh binary), OTA_PLAN_REMOTE (default origin).
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
  if [ "$DRY_RUN" != true ]; then
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
  if ! prod="$("$GH" api "repos/${REPO}/actions/runs/${WR_RUN_ID}/jobs?per_page=100" \
        --jq '[.jobs[] | select(.name == "'"${PROD_JOB}"'" and .conclusion == "success")] | length' 2>/dev/null)"; then
    echo "::error::cannot read the jobs of CI run ${WR_RUN_ID} — not publishing to production"
    exit 1
  fi
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
