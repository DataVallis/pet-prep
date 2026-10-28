#!/usr/bin/env bash
# Daily production deploy (.github/workflows/daily-deploy.yml, DEPLOYMENT.md D21, David 2026-10-15;
# moved from 06:00 to 03:17 by D24, David 2026-10-26).
#
# Every day at 03:17 Europe/Ljubljana (GitHub may start a scheduled run hours late — at
# 04:00 / 05:00 UTC they started 4.5–6 h late in October 2026): when main has commits that production does not run
# yet AND the push CI of the main head is green, start exactly what David starts by hand —
# "CI & Deploy" → Run workflow on main with target production. The dispatched run is an
# ordinary workflow_dispatch run (GITHUB_TOKEN may start workflow_dispatch runs): full
# tests, the deploy guard, the deploy job and the mobile-ota workflow_run chain behave as
# for a manual run. Manual "Run workflow" is unchanged.
#
#   a. schedule: GitHub cron is UTC only; the workflow fires at 01:17 and 02:17 UTC. Only the
#      cron whose hour is DEPLOY_LOCAL_HOUR (03) in Europe/Ljubljana today proceeds (01 UTC in
#      CEST, 02 UTC in CET),
#      the other exits 0. Judged by the cron (SCHEDULE), not the clock, so a scheduled run
#      that GitHub starts late still counts; without SCHEDULE the current local hour must be 03.
#   b. main head == head_sha of the last successful Run workflow run whose job "Deploy to
#      Hetzner Production" succeeded (same lookup as ci-plan.sh last_deployed_sha) → exit 0.
#   c. the newest push run of deploy-production.yml for the main head must have concluded
#      success. Queued / running / not created yet → wait (up to WAIT_SECONDS, re-reading
#      the main head every poll; a run not created within MISSING_GRACE_SECONDS counts as
#      missing). Red, missing or still unfinished → no deploy, ::error + summary, exit 1.
#   d. a workflow_dispatch run of deploy-production.yml is already queued / running → exit 0.
#   e. gh workflow run deploy-production.yml --ref main -f target=production; the new run
#      goes to the job summary. (Residual race: a merge between the last head check and the
#      dispatch makes the run deploy the newer head — that run still tests everything first.)
#   f. DRY_RUN=true: decide and print, dispatch nothing.
#
# Env: EVENT_NAME (schedule | workflow_dispatch), SCHEDULE (github.event.schedule), DRY_RUN,
# REPO (owner/name), GH_TOKEN, GITHUB_STEP_SUMMARY (optional), WORKFLOW_FILE (default
# deploy-production.yml), DEPLOY_JOB_NAME, WAIT_SECONDS (1800), POLL_SECONDS (60),
# MISSING_GRACE_SECONDS (300).
# Test hooks: DAILY_DEPLOY_GH (gh binary), DAILY_DEPLOY_NOW (date -d string, default now),
# DAILY_DEPLOY_SLEEP (sleep binary).
set -Eeuo pipefail

EVENT_NAME="${EVENT_NAME:?EVENT_NAME is required}"
REPO="${REPO:?REPO is required}"
DRY_RUN="${DRY_RUN:-false}"
SCHEDULE="${SCHEDULE:-}"
WORKFLOW_FILE="${WORKFLOW_FILE:-deploy-production.yml}"
DEPLOY_JOB_NAME="${DEPLOY_JOB_NAME:-Deploy to Hetzner Production}"
WAIT_SECONDS="${WAIT_SECONDS:-1800}"
POLL_SECONDS="${POLL_SECONDS:-60}"
MISSING_GRACE_SECONDS="${MISSING_GRACE_SECONDS:-300}"
GH="${DAILY_DEPLOY_GH:-gh}"
SLEEP="${DAILY_DEPLOY_SLEEP:-sleep}"
NOW="${DAILY_DEPLOY_NOW:-now}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
LOCAL_TZ="Europe/Ljubljana"
DEPLOY_LOCAL_HOUR="03" # D24 (David 2026-10-26); was 06 (D21). Keep in sync with the crons in daily-deploy.yml.

log() { echo "daily-deploy: $*"; }
summary() { echo "$*" >> "$SUMMARY"; }
# done_ok MSG: nothing to do — notice + summary, exit 0.
done_ok() {
  echo "::notice title=Daily deploy::$1"
  summary "**No deploy:** $1"
  exit 0
}
# refuse MSG: CI is not green — error + summary, exit 1 (the run turns red so it is noticed).
refuse() {
  echo "::error title=Daily deploy::$1"
  summary "**No deploy — CI on main is not green:** $1"
  exit 1
}
# api_fail WHAT: GitHub API error — never deploy on a doubt.
api_fail() {
  echo "::error title=Daily deploy::GitHub API error while reading $1 — not deploying"
  summary "**No deploy:** GitHub API error while reading $1."
  exit 1
}

summary "### Daily production deploy"
summary ""
summary "event \`${EVENT_NAME}\` · dry run \`${DRY_RUN}\`${SCHEDULE:+ · cron \`${SCHEDULE}\`}"
summary ""

# --- a. 03:xx Europe/Ljubljana --------------------------------------------------------
if [ "$EVENT_NAME" = schedule ]; then
  if [ -n "$SCHEDULE" ]; then
    read -r cron_min cron_hour _ <<< "$SCHEDULE"
    if ! [[ "$cron_min" =~ ^[0-9]+$ && "$cron_hour" =~ ^[0-9]+$ ]]; then
      echo "::error::unexpected cron '${SCHEDULE}'"; exit 1
    fi
    today_utc="$(date -u -d "$NOW" +%F)"
    local_hour="$(TZ="$LOCAL_TZ" date -d "${today_utc} $(printf '%02d:%02d' "$cron_hour" "$cron_min") UTC" +%H)"
    what="cron '${SCHEDULE}' (UTC) is ${local_hour}:$(printf '%02d' "$cron_min") in ${LOCAL_TZ} today"
  else
    local_hour="$(TZ="$LOCAL_TZ" date -d "$NOW" +%H)"
    what="it is ${local_hour}:xx in ${LOCAL_TZ}"
  fi
  if [ "$local_hour" != "$DEPLOY_LOCAL_HOUR" ]; then
    done_ok "${what}, not ${DEPLOY_LOCAL_HOUR} — the other daily cron deploys (CET / CEST)."
  fi
  log "$what"
fi

# --- helpers --------------------------------------------------------------------------
main_head() {
  "$GH" api "repos/${REPO}/branches/main" --jq '.commit.sha' 2>/dev/null
}

# last_deployed_sha: as scripts/ci-plan.sh — newest successful same-repo Run workflow run on
# main whose deploy job succeeded. Empty when none of the last 50; returns 1 on API error.
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

# push_ci SHA: "<id> <status> <conclusion> <url>" of the newest push run of the workflow for
# SHA on main (same repository), empty when there is none; returns 1 on API error.
push_ci() {
  "$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?branch=main&event=push&head_sha=$1&per_page=20" \
    --jq '[.workflow_runs[] | select(.event == "push" and .head_sha == "'"$1"'") | select(.head_repository.full_name == "'"${REPO}"'")]
          | sort_by(.created_at) | reverse | .[0] // empty
          | "\(.id) \(.status) \(.conclusion // "none") \(.html_url)"' 2>/dev/null
}

# ci_target SHA: the commit whose push CI decides (fix 2026-10-28). The OTA workflow
# (mobile-ota.yml) pushes "… [skip ci]" bot commits that only touch docs/ (app-release.json,
# breed-registry.json) — they never get a push run. Walk back over such commits along the
# first parent (at most 20) to the newest commit that has its own CI; any other commit stops
# the walk. Returns 1 on API error.
ci_target() {
  local sha="$1" verdict
  for _ in $(seq 1 20); do
    verdict="$("$GH" api "repos/${REPO}/commits/${sha}" \
          --jq 'if ((.commit.message | split("\n")[0] | test("\\[skip ci\\]"))
                   and ((.files // []) | length > 0)
                   and ((.parents // []) | length == 1)
                   and all(.files[]; .filename | startswith("docs/")))
                then "skip \(.parents[0].sha)" else "keep" end' 2>/dev/null)" || return 1
    case "$verdict" in
      "skip "*) sha="${verdict#skip }" ;;
      *) break ;;
    esac
  done
  echo "$sha"
}

# --- b + c: something new, and its push CI green --------------------------------------
waited=0
while :; do
  if ! head="$(main_head)" || [ -z "$head" ]; then api_fail "the head of main"; fi
  deployed="$(last_deployed_sha)" || api_fail "the last production deploy"
  if [ "$head" = "$deployed" ]; then
    done_ok "nothing new — production already runs the main head ${head:0:7}."
  fi
  target="$(ci_target "$head")" || api_fail "the commit ${head:0:7}"
  if [ "$target" = "$deployed" ]; then
    done_ok "nothing new — after production ${deployed:0:7} main has only [skip ci] docs commits (head ${head:0:7})."
  fi
  log "main head ${head:0:7}, production runs ${deployed:-unknown (no deploy among the last 50 Run workflow runs)}"
  [ "$target" = "$head" ] || log "main head ${head:0:7} is a [skip ci] docs commit — its CI is the one of ${target:0:7}"

  ci="$(push_ci "$target")" || api_fail "the push CI runs of ${target:0:7}"
  read -r _ ci_status ci_conclusion ci_url <<< "${ci:-}"
  if [ -z "$ci" ]; then
    state="no push run of CI & Deploy for main head ${target:0:7}"
    [ "$waited" -lt "$MISSING_GRACE_SECONDS" ] || refuse "${state} (waited ${waited}s)."
  elif [ "$ci_status" = completed ]; then
    [ "$ci_conclusion" = success ] || \
      refuse "push CI of main head ${target:0:7} concluded '${ci_conclusion}' — run ${ci_url}"
    # Green. Re-read the head: a merge during the wait means a new head with its own CI.
    if ! now_head="$(main_head)" || [ -z "$now_head" ]; then api_fail "the head of main"; fi
    if [ "$now_head" = "$head" ]; then
      break
    fi
    log "main moved to ${now_head:0:7} — checking its CI"
    continue
  else
    state="push CI of main head ${target:0:7} is ${ci_status} — run ${ci_url}"
  fi
  if [ "$waited" -ge "$WAIT_SECONDS" ]; then
    refuse "${state}; gave up after ${waited}s."
  fi
  log "${state}; waiting ${POLL_SECONDS}s (${waited}s / ${WAIT_SECONDS}s)"
  "$SLEEP" "$POLL_SECONDS"
  waited=$((waited + POLL_SECONDS))
done
log "push CI green: ${ci_url}"
summary "- main head \`${head}\` · production runs \`${deployed:-unknown}\`"
summary "- push CI green: ${ci_url}"

# --- d. no second deploy while one is queued / running --------------------------------
active="$("$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?event=workflow_dispatch&per_page=30" \
      --jq '[.workflow_runs[] | select(.event == "workflow_dispatch" and .status != "completed")] | .[0] // empty | .html_url' \
      2>/dev/null)" || api_fail "the running Run workflow runs"
if [ -n "$active" ]; then
  done_ok "a Run workflow run of CI & Deploy is already queued or running (${active})."
fi

# --- e / f. dispatch -----------------------------------------------------------------
if [ "$DRY_RUN" = true ]; then
  msg="dry run — would deploy ${head:0:7} (Run workflow on main, target production)."
  echo "::notice title=Daily deploy::${msg}"
  summary "**Decision:** ${msg}"
  exit 0
fi

started="$(date -u -d "-30 seconds" +%Y-%m-%dT%H:%M:%SZ)" # tolerate clock skew
"$GH" workflow run "$WORKFLOW_FILE" --repo "$REPO" --ref main -f target=production
log "dispatched ${WORKFLOW_FILE} on main (target production) for ${head:0:7}"

# The dispatch API returns no run id here: find the new run (created after `started`).
run_url=""
for _ in 1 2 3 4 5 6; do
  run_url="$("$GH" api "repos/${REPO}/actions/workflows/${WORKFLOW_FILE}/runs?event=workflow_dispatch&branch=main&per_page=10" \
        --jq '[.workflow_runs[] | select(.event == "workflow_dispatch" and .created_at >= "'"${started}"'")] | .[0] // empty | .html_url' \
        2>/dev/null)" || run_url=""
  [ -n "$run_url" ] && break
  "$SLEEP" 5
done
echo "::notice title=Daily deploy::dispatched the production deploy of ${head:0:7}: ${run_url:-run not listed yet — see Actions → CI & Deploy}"
summary "**Dispatched:** production deploy of \`${head:0:7}\` — ${run_url:-run not listed yet, see Actions → CI & Deploy}"
