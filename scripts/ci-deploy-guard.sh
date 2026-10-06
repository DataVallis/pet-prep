#!/usr/bin/env bash
# Deploy guard for .github/workflows/deploy-production.yml (first step of job "deploy").
#
# Refuses (exit 1 — the deploy job turns red, it is NOT skipped) to deploy a commit
# that is no longer the head of main on origin:
#   push              always: a newer merge exists → its own run deploys it; deploying
#                     this older commit afterwards would silently roll production back.
#   any re-run        (attempt > 1) same — "Re-run" of an old run is NOT a rollback tool;
#                     re-running the run of the current main head still redeploys it.
#   workflow_dispatch (attempt 1) not checked: "Run workflow" on main starts from the
#                     main head at click time.
# Rollbacks: a revert commit (normal PR + merge) or "Run workflow" on main after main is
# where it should be. A failure to read origin also refuses.
#
# Env: EVENT_NAME, RUN_ATTEMPT, COMMIT_SHA; optional DEPLOY_GUARD_REMOTE (default origin).
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
COMMIT_SHA="${COMMIT_SHA:?COMMIT_SHA is required}"
RUN_ATTEMPT="${RUN_ATTEMPT:-1}"
REMOTE="${DEPLOY_GUARD_REMOTE:-origin}"

if [ "$EVENT_NAME" = workflow_dispatch ] && [ "$RUN_ATTEMPT" = 1 ]; then
  echo "deploy-guard: workflow_dispatch (attempt 1) — deploying ${COMMIT_SHA}"
  exit 0
fi

if ! head="$(git ls-remote --exit-code "$REMOTE" refs/heads/main | cut -f1)" || [ -z "$head" ]; then
  echo "::error::deploy-guard: cannot read refs/heads/main from ${REMOTE} — refusing to deploy"
  exit 1
fi

if [ "$head" != "$COMMIT_SHA" ]; then
  echo "::error::deploy-guard: this run is for ${COMMIT_SHA}, but main is now ${head} — refusing to deploy an older commit (event ${EVENT_NAME}, attempt ${RUN_ATTEMPT}). Roll back with a revert commit or 'Run workflow' on main."
  exit 1
fi
echo "deploy-guard: ${COMMIT_SHA} is the head of main (event ${EVENT_NAME}, attempt ${RUN_ATTEMPT})"
