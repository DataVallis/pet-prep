#!/usr/bin/env bash
# Deploy target for .github/workflows/deploy-production.yml (step of job "plan", D18).
#
# Manual production deploys (David 2026-10-14; a dev server is postponed):
#   push to main                         → none (CI only — a merge deploys NOTHING)
#   "Run workflow" on main, target production → production
#   "Run workflow" on main, target none  → none (full test run, no deploy; the default)
#   anything else (PR, other branch)     → none
# Re-runs keep the event and inputs of the run ("Re-run" of a production dispatch
# redeploys, guarded by scripts/ci-deploy-guard.sh).
#
# Env: EVENT_NAME, GIT_REF, INPUT_TARGET (dispatch input), GITHUB_OUTPUT.
# Output: target=production|none.
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
GIT_REF="${GIT_REF:?GIT_REF is required}"
INPUT_TARGET="${INPUT_TARGET:-}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"

target=none
if [ "$GIT_REF" != "refs/heads/main" ]; then
  reason="not main"
elif [ "$EVENT_NAME" = push ]; then
  reason="push to main: CI only, production is deployed by Run workflow → target production"
elif [ "$EVENT_NAME" = workflow_dispatch ]; then
  case "${INPUT_TARGET:-none}" in
    production) target=production; reason="Run workflow with target production" ;;
    none) reason="Run workflow with target none (tests only)" ;;
    *) echo "::error::deploy target must be 'none' or 'production' (got '${INPUT_TARGET}')"; exit 1 ;;
  esac
else
  reason="event ${EVENT_NAME}"
fi

echo "target=${target}" >> "$OUT"
echo "::notice title=deploy target::${target} — ${reason}"
