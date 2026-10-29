#!/usr/bin/env bash
# OTA or store build? (M5-F10, David 2026-10-12; channels per server D18, 2026-10-14)
#
# For each platform: resolve the app's runtime version (expo-updates `fingerprint` policy:
# a hash of everything native — native modules, native app.json config, icons, splash,
# eas.json). If a finished EAS build of the TARGET CHANNEL with that runtime version
# exists, the change is JS / assets only → publish an OTA update to that channel.
# Otherwise the change needs a new build → no update, a warning + job summary line.
# An old build never receives an update for another runtime version, so a wrong guess
# cannot crash the app.
#
# Channels = eas.json build profiles of the same name:
#   production  store builds, API https://api.petprep.si (default)
#   preview     internal builds of the eas.json `preview` profile (manual runs only)
#
# Usage: scripts/mobile-ota.sh <message> [--dry-run] [--channel production|preview]
# Needs: EXPO_TOKEN (EAS robot token), `eas` on PATH, run from the repo root, mobile deps installed.
# Exports EXPO_PUBLIC_GIT_COMMIT=<short HEAD> so the bundle knows its commit (app version line).
# The EXPO_PUBLIC_* values of the channel's build profile (eas.json) are exported so the
# OTA bundle talks to the same API / Reverb / RevenueCat as that build. `eas update
# --environment <channel>` adds the EAS server variables of that environment on top
# (they win for keys they define) — keep EXPO_PUBLIC_* there unset or identical.
# Output (GITHUB_OUTPUT): released=true when the PRODUCTION channel got the update on at
# least one platform (David 2026-10-25), plus platforms=<comma list, e.g. ios>; mobile-ota.yml
# then records it for the website register.
set -euo pipefail

MESSAGE="${1:?usage: mobile-ota.sh <message> [--dry-run] [--channel production|preview]}"
shift
DRY_RUN=""
CHANNEL="production"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN="--dry-run"; shift ;;
    --channel) CHANNEL="${2:-}"; shift 2 ;;
    --channel=*) CHANNEL="${1#--channel=}"; shift ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
case "$CHANNEL" in
  production|preview) ;;
  *) echo "channel must be production or preview (got '${CHANNEL}')" >&2; exit 2 ;;
esac
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
EAS=(eas)

cd mobile

# The store / internal build resolves its config with its profile env (EXPO_PUBLIC_*,
# PETPREP_APNS_MODE — app.config.ts) and EAS_BUILD_PROFILE=<profile> — resolve the same
# config here, or the runtimes never match.
export EAS_BUILD_PROFILE="$CHANNEL"
# Captured first: a failure inside `< <(…)` would be ignored by set -e.
profile_env="$(node -e "const p=require('./eas.json').build[process.argv[1]];if(!p||p.channel!==process.argv[1]){console.error('eas.json: no build profile with channel '+process.argv[1]);process.exit(1)}for(const[k,v]of Object.entries(p.env||{}))console.log(k+'='+v)" "$CHANNEL")" \
  || { echo "::error::eas.json has no build profile '${CHANNEL}' with channel '${CHANNEL}' — nothing published."; exit 1; }
while IFS='=' read -r key value; do
  [[ "$key" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
  export "$key=$value"
done <<< "$profile_env"

# The commit of this bundle (David 2026-10-29): `EXPO_PUBLIC_*` is inlined into the JS at
# export, so the parent's »Različica aplikacije« (mobile src/modules/appVersion) shows exactly
# the commit docs/research/app-release.json records for this OTA. JS only — it is not part of
# the native fingerprint (app.config.ts does not read it). Empty outside git → the app falls
# back to extra.gitSha.
EXPO_PUBLIC_GIT_COMMIT="$(git rev-parse --short=7 HEAD 2>/dev/null || true)"
export EXPO_PUBLIC_GIT_COMMIT

echo "- 📡 channel **${CHANNEL}** (API \`${EXPO_PUBLIC_API_URL:-default}\`, commit \`${EXPO_PUBLIC_GIT_COMMIT:-unknown}\`)" >>"$SUMMARY"
needs_build=()
published=()
for platform in ios android; do
  runtime=$(npx expo-updates runtimeversion:resolve --platform "$platform" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s).runtimeVersion))')
  count=$("${EAS[@]}" build:list --platform "$platform" --status finished --channel "$CHANNEL" \
    --runtime-version "$runtime" --limit 1 --json --non-interactive | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s||"[]").length))')

  if [[ "$count" -gt 0 ]]; then
    echo "📱 $platform: runtime $runtime has a $CHANNEL build → OTA update"
    echo "- 📱 **$platform**: OTA update to \`$CHANNEL\` (runtime \`${runtime:0:12}\`)" >>"$SUMMARY"
    if [[ "$DRY_RUN" != "--dry-run" ]]; then
      "${EAS[@]}" update --channel "$CHANNEL" --environment "$CHANNEL" --platform "$platform" \
        --message "$MESSAGE" --non-interactive
      published+=("$platform")
    fi
  else
    needs_build+=("$platform")
    echo "::warning title=New $CHANNEL build needed::$platform — native code changed (runtime $runtime has no $CHANNEL build). No OTA update was sent."
    echo "- 🏪 **$platform**: new \`$CHANNEL\` build needed (runtime \`${runtime:0:12}\` has no $CHANNEL build) — no OTA sent" >>"$SUMMARY"
  fi
done

if [[ ${#needs_build[@]} -gt 0 ]]; then
  echo "New $CHANNEL build needed for: ${needs_build[*]}"
fi

# At least one platform got this bundle on the production channel → mobile-ota.yml records it
# in docs/research/app-release.json, so the website register shows the new breeds as "in the
# app" (David 2026-10-13; one platform is enough since 2026-10-25 — Android had no OTA from
# 2026-10-21 and the site lagged iOS by ~40 breeds). A preview update is never a release.
if [[ "$CHANNEL" == production && ${#published[@]} -gt 0 ]]; then
  platforms="$(IFS=,; echo "${published[*]}")"
  { echo "released=true"; echo "platforms=${platforms}"; } >>"${GITHUB_OUTPUT:-/dev/null}"
fi
