#!/usr/bin/env bash
# Harness for scripts/daily-deploy.sh (D21 daily 06:00 production deploy) — no GitHub, no network.
#
# A `gh` shim answers `gh api <path> --jq <filter>` by running the REAL jq filter of the
# script over a JSON fixture chosen by the path, and records `gh workflow run …` instead
# of dispatching:
#   …/branches/main                                → line k of $FIX/heads (k = call number, last line repeats)
#   …/runs?…event=workflow_dispatch&status=success → $FIX/deployed.json (+ …/runs/<id>/jobs → $FIX/jobs-<id>.json)
#   …/runs?…event=push&head_sha=<sha>…             → $FIX/push-<sha>.<k>.json (k = call number for that sha,
#                                                     highest existing repeats; none → no runs)
#   …/runs?event=workflow_dispatch&per_page=30     → $FIX/active.json (none → no runs)
#   …/runs?event=workflow_dispatch&branch=main&…   → $FIX/dispatched.json (none → no runs)
# GH_FAIL_MATCH (ERE over the path) makes matching calls fail. `sleep` is a recording shim.
#
# Run: bash scripts/tests/daily-deploy.test.sh   (needs bash, jq, GNU date)
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../daily-deploy.sh"
WORKFLOW="${HERE}/../../.github/workflows/daily-deploy.yml"
command -v jq >/dev/null || { echo "jq is required" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
REPO_NAME="DataVallis/pet-prep"
OLD="1111111111111111111111111111111111111111"
NEW="2222222222222222222222222222222222222222"
NEWER="3333333333333333333333333333333333333333"

mkdir -p "$TMP/bin"
cat > "$TMP/bin/gh" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
if [ "$1" = workflow ]; then echo "$*" >> "$FIX/dispatch.log"; exit 0; fi
[ "$1" = api ] || { echo "gh shim: unexpected $*" >&2; exit 64; }
path="$2"; shift 2
filter="."
while [ $# -gt 0 ]; do
  case "$1" in --jq) filter="$2"; shift 2 ;; *) shift ;; esac
done
echo "$path" >> "$FIX/gh.log"
if [ -n "${GH_FAIL_MATCH:-}" ] && [[ "$path" =~ $GH_FAIL_MATCH ]]; then echo "gh shim: forced failure" >&2; exit 1; fi
empty='{"workflow_runs":[]}'
count() { local c; c=$(( $(cat "$FIX/count-$1" 2>/dev/null || echo 0) + 1 )); echo "$c" > "$FIX/count-$1"; echo "$c"; }
case "$path" in
  */branches/main)
    k="$(count heads)"; sed -n "${k}p" "$FIX/heads" | grep . || tail -n1 "$FIX/heads"; exit 0 ;;
  */runs\?*status=success*) file="$FIX/deployed.json" ;;
  */runs\?*event=push*)
    sha="${path#*head_sha=}"; sha="${sha%%&*}"; k="$(count "push-$sha")"
    while [ "$k" -gt 0 ] && [ ! -f "$FIX/push-$sha.$k.json" ]; do k=$((k - 1)); done
    file="$FIX/push-$sha.$k.json"; [ -f "$file" ] || { echo "$empty" > "$file"; } ;;
  */runs\?*per_page=30*) file="$FIX/active.json"; [ -f "$file" ] || echo "$empty" > "$file" ;;
  */runs\?*per_page=10*) file="$FIX/dispatched.json"; [ -f "$file" ] || echo "$empty" > "$file" ;;
  */actions/runs/*/jobs\?*) id="${path%/jobs*}"; id="${id##*/}"; file="$FIX/jobs-${id}.json"
    [ -f "$file" ] || { echo '{"message":"Not Found"}' >&2; exit 1; } ;;
  *) echo "gh shim: unknown path $path" >&2; exit 64 ;;
esac
jq -r "$filter" "$file"
SHIM
cat > "$TMP/bin/sleep" <<'SHIM'
#!/usr/bin/env bash
echo "$1" >> "$FIX/sleep.log"
SHIM
chmod +x "$TMP/bin/gh" "$TMP/bin/sleep"

pass=0 fail=0 n=0
check() { # check DESC EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "  FAIL: $1 — expected '$2', got '$3'"; fi
}
contains() { # contains DESC FILE NEEDLE
  if grep -qF -- "$3" "$2"; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "  FAIL: $1 — '$3' not in $(basename "$2"):"; sed 's/^/    /' "$2"; fi
}

# new_case HEAD [DEPLOYED_SHA]: fresh fixtures — main head HEAD; production last deployed
# DEPLOYED_SHA by Run workflow run 7 (an older green dispatch run 6 whose deploy was skipped
# comes first, so the lookup must look at the jobs). Sets $FIX.
new_case() {
  n=$((n + 1)); FIX="$TMP/case$n"; mkdir -p "$FIX"
  echo "$1" > "$FIX/heads"
  jq -n --arg s "${2:-$OLD}" --arg r "$REPO_NAME" '{workflow_runs:[
    {id:6,event:"workflow_dispatch",head_sha:"9999999999999999999999999999999999999999",head_repository:{full_name:$r}},
    {id:7,event:"workflow_dispatch",head_sha:$s,head_repository:{full_name:$r}}]}' > "$FIX/deployed.json"
  jobs 6 skipped; jobs 7 success
}
jobs() { # jobs RUN_ID DEPLOY_CONCLUSION
  jq -n --arg c "$2" '{jobs:[{name:"CI OK",conclusion:"success"},{name:"Deploy to Hetzner Production",conclusion:$c}]}' > "$FIX/jobs-$1.json"
}
push_run() { # push_run SHA K STATUS CONCLUSION [REPO]: the push CI as seen by the K-th poll
  jq -n --arg s "$1" --arg st "$3" --arg c "$4" --arg r "${5:-$REPO_NAME}" '{workflow_runs:[
    {id:50,event:"push",head_sha:$s,status:"completed",conclusion:"failure",created_at:"2026-10-14T10:00:00Z",html_url:"https://ci/50",head_repository:{full_name:$r}},
    {id:51,event:"push",head_sha:$s,status:$st,conclusion:(if $c == "null" then null else $c end),created_at:"2026-10-14T11:00:00Z",html_url:"https://ci/51",head_repository:{full_name:$r}}]}' \
    > "$FIX/push-$1.$2.json"
}
# run EVENT [SCHEDULE] [NOW] [DRY_RUN]: runs the script, sets o_rc, o_out (stdout+stderr), o_dispatch.
run() {
  local rc=0 fix="$FIX"
  : > "$FIX/dispatch.log"
  FIX="$fix" PATH="$TMP/bin:$PATH" EVENT_NAME="$1" SCHEDULE="${2:-}" DAILY_DEPLOY_NOW="${3:-2026-07-01T04:00:00Z}" \
    DRY_RUN="${4:-false}" REPO="$REPO_NAME" GITHUB_STEP_SUMMARY="$fix/summary" \
    WAIT_SECONDS=300 POLL_SECONDS=60 MISSING_GRACE_SECONDS=120 \
    bash "$SCRIPT" >"$FIX/out" 2>&1 || rc=$?
  o_rc=$rc
  o_dispatch="$(cat "$FIX/dispatch.log")"
}
DISPATCH="workflow run deploy-production.yml --repo ${REPO_NAME} --ref main -f target=production"

echo "== a. 06:00 Europe/Ljubljana (schedule) =="
# Summer (CEST, UTC+2): 04 UTC = 06 local → proceeds; 05 UTC = 07 → skips.
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 4 * * *' 2026-07-01T04:02:00Z
check "CEST 04 UTC: exit" 0 "$o_rc"; check "CEST 04 UTC: dispatched" "$DISPATCH" "$o_dispatch"
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 5 * * *' 2026-07-01T05:01:00Z
check "CEST 05 UTC: exit" 0 "$o_rc"; check "CEST 05 UTC: nothing dispatched" "" "$o_dispatch"
contains "CEST 05 UTC: says why" "$FIX/out" "is 07:00 in Europe/Ljubljana"
check "CEST 05 UTC: no API call" "" "$(cat "$FIX/gh.log" 2>/dev/null)"
# Winter (CET, UTC+1): 05 UTC = 06 local → proceeds; 04 UTC = 05 → skips.
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 5 * * *' 2026-12-01T05:03:00Z
check "CET 05 UTC: dispatched" "$DISPATCH" "$o_dispatch"
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 4 * * *' 2026-12-01T04:03:00Z
check "CET 04 UTC: nothing dispatched" "0 " "$o_rc $o_dispatch"
# DST end day (Sun 2026-10-25, 01:00 UTC → CET): 05 UTC deploys, 04 UTC does not.
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 5 * * *' 2026-10-25T05:00:00Z
check "DST-end day 05 UTC: dispatched" "$DISPATCH" "$o_dispatch"
new_case "$NEW"; run schedule '0 4 * * *' 2026-10-25T04:00:00Z
check "DST-end day 04 UTC: skipped" "0 " "$o_rc $o_dispatch"
# GitHub started the 04 UTC run 70 min late (05:10 UTC, 07:10 CEST): still the 06:00 cron.
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '0 4 * * *' 2026-07-01T05:10:00Z
check "late scheduled run still deploys" "$DISPATCH" "$o_dispatch"
# No cron string: the clock decides.
new_case "$NEW"; push_run "$NEW" 1 completed success
run schedule '' 2026-07-01T04:20:00Z
check "no cron, 06:20 local: dispatched" "$DISPATCH" "$o_dispatch"
new_case "$NEW"; run schedule '' 2026-07-01T05:20:00Z
check "no cron, 07:20 local: skipped" "0 " "$o_rc $o_dispatch"
# workflow_dispatch ignores the hour.
new_case "$NEW"; push_run "$NEW" 1 completed success
run workflow_dispatch '' 2026-07-01T13:00:00Z
check "manual run at 15:00: dispatched" "$DISPATCH" "$o_dispatch"

echo "== b. nothing new =="
new_case "$OLD" "$OLD"; run workflow_dispatch
check "head = last deploy: exit 0, nothing dispatched" "0 " "$o_rc $o_dispatch"
contains "nothing new: says so" "$FIX/out" "nothing new"
if grep -q "event=push" "$FIX/gh.log"; then r=1; else r=0; fi
check "nothing new: push CI not even read" 0 "$r"
# The newer green dispatch run 6 skipped its deploy job → it is not "what production runs".
new_case "9999999999999999999999999999999999999999" "$OLD"; push_run 9999999999999999999999999999999999999999 1 completed success
run workflow_dispatch
check "dispatch run with skipped deploy is not a deploy" "$DISPATCH" "$o_dispatch"
# No deploy found at all → deploy (when green).
new_case "$NEW"; echo '{"workflow_runs":[]}' > "$FIX/deployed.json"; push_run "$NEW" 1 completed success
run workflow_dispatch
check "no previous deploy: dispatched" "$DISPATCH" "$o_dispatch"

echo "== c. push CI of the main head must be green =="
new_case "$NEW"; push_run "$NEW" 1 completed failure; run workflow_dispatch
check "red CI: exit 1, nothing dispatched" "1 " "$o_rc $o_dispatch"
contains "red CI: ::error with the run" "$FIX/out" "::error title=Daily deploy::push CI of main head 2222222 concluded 'failure' — run https://ci/51"
contains "red CI: job summary" "$FIX/summary" "No deploy — CI on main is not green"
new_case "$NEW"; push_run "$NEW" 1 completed cancelled; run workflow_dispatch
check "cancelled CI: refused" "1 " "$o_rc $o_dispatch"
new_case "$NEW"; run workflow_dispatch
check "missing CI: refused after the grace" "1 " "$o_rc $o_dispatch"
contains "missing CI: says why" "$FIX/out" "no push run of CI & Deploy for main head 2222222"
check "missing CI: polled through the grace only" "60 60" "$(paste -sd' ' "$FIX/sleep.log")"
# The newest run counts, not an older red one (run 50 failed, run 51 green).
new_case "$NEW"; push_run "$NEW" 1 completed success; run workflow_dispatch
check "newest push run decides" "$DISPATCH" "$o_dispatch"
# A push run from a fork is ignored.
new_case "$NEW"; push_run "$NEW" 1 completed success "someone/fork"; run workflow_dispatch
check "fork push run ignored → missing" "1 " "$o_rc $o_dispatch"
# Running → waits, then deploys when green.
new_case "$NEW"; push_run "$NEW" 1 in_progress null; push_run "$NEW" 3 completed success
run workflow_dispatch
check "running CI: waited, then dispatched" "0 $DISPATCH" "$o_rc $o_dispatch"
check "running CI: two polls" "60 60" "$(grep -x 60 "$FIX/sleep.log" | paste -sd' ')"
# Running → turns red.
new_case "$NEW"; push_run "$NEW" 1 queued null; push_run "$NEW" 2 completed failure
run workflow_dispatch
check "queued CI turns red: refused" "1 " "$o_rc $o_dispatch"
# Still running after WAIT_SECONDS (300) → refused.
new_case "$NEW"; push_run "$NEW" 1 in_progress null; run workflow_dispatch
check "CI still running at the deadline: refused" "1 " "$o_rc $o_dispatch"
contains "deadline: says it gave up" "$FIX/out" "gave up after 300s"
# Main moves while we wait: the new head's CI decides.
new_case "$NEW"; printf '%s\n%s\n%s\n' "$NEW" "$NEW" "$NEWER" > "$FIX/heads"
push_run "$NEW" 1 in_progress null; push_run "$NEW" 2 completed success
push_run "$NEWER" 1 completed failure
run workflow_dispatch
check "main moved to a red head: refused" "1 " "$o_rc $o_dispatch"
contains "main moved: says so" "$FIX/out" "main moved to 3333333"
new_case "$NEW"; printf '%s\n%s\n' "$NEW" "$NEWER" > "$FIX/heads"
push_run "$NEW" 1 completed success; push_run "$NEWER" 1 completed success
run workflow_dispatch
check "main moved to a green head: dispatched" "0 $DISPATCH" "$o_rc $o_dispatch"
contains "main moved: summary names the new head" "$FIX/summary" "main head \`${NEWER}\`"

echo "== d. a Run workflow run is already queued / running =="
new_case "$NEW"; push_run "$NEW" 1 completed success
jq -n '{workflow_runs:[{event:"workflow_dispatch",status:"completed",html_url:"https://ci/80"},
  {event:"workflow_dispatch",status:"queued",html_url:"https://ci/81"}]}' > "$FIX/active.json"
run workflow_dispatch
check "queued dispatch run: exit 0, nothing dispatched" "0 " "$o_rc $o_dispatch"
contains "queued dispatch run: named" "$FIX/out" "https://ci/81"

echo "== e / f. dispatch, dry run =="
new_case "$NEW"; push_run "$NEW" 1 completed success
jq -n '{workflow_runs:[{event:"workflow_dispatch",created_at:"2999-01-01T00:00:00Z",html_url:"https://ci/90"}]}' > "$FIX/dispatched.json"
run workflow_dispatch
contains "dispatched run in the summary" "$FIX/summary" "**Dispatched:** production deploy of \`2222222\` — https://ci/90"
new_case "$NEW"; push_run "$NEW" 1 completed success; run workflow_dispatch
contains "run not listed yet: still reported" "$FIX/summary" "run not listed yet"
new_case "$NEW"; push_run "$NEW" 1 completed success; run workflow_dispatch '' '' true
check "dry run: exit 0, nothing dispatched" "0 " "$o_rc $o_dispatch"
contains "dry run: prints the decision" "$FIX/out" "dry run — would deploy 2222222"
new_case "$NEW"; push_run "$NEW" 1 completed failure; run workflow_dispatch '' '' true
check "dry run with red CI: refused like a real run" "1 " "$o_rc $o_dispatch"

echo "== API errors never deploy =="
for m in 'branches/main' 'status=success' 'event=push' 'per_page=30' '/jobs'; do
  new_case "$NEW"; push_run "$NEW" 1 completed success
  GH_FAIL_MATCH="$m" run workflow_dispatch
  check "API error on ${m}: exit 1, nothing dispatched" "1 " "$o_rc $o_dispatch"
done

echo "== workflow .github/workflows/daily-deploy.yml =="
wf="$(cat "$WORKFLOW")"
for want in "- cron: '0 4 * * *'" "- cron: '0 5 * * *'" "workflow_dispatch:" "dry_run:" "type: boolean" \
            "contents: read" "actions: write" "GH_TOKEN: \${{ github.token }}" \
            "SCHEDULE: \${{ github.event.schedule }}" "bash scripts/daily-deploy.sh"; do
  case "$wf" in *"$want"*) r=0 ;; *) r=1 ;; esac
  check "workflow has: $want" 0 "$r"
done
case "$wf" in *"secrets."*) r=1 ;; *) r=0 ;; esac
check "workflow reads no secrets" 0 "$r"

echo
echo "daily-deploy harness: ${pass} passed, ${fail} failed (${n} cases)"
[ "$fail" -eq 0 ]
