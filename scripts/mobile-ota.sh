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
# The EXPO_PUBLIC_* values of the channel's build profile (eas.json) are exported so the
# OTA bundle talks to the same API / Reverb / RevenueCat as that build. `eas update
# --environment <channel>` adds the EAS server variables of that environment on top
# (they win for keys they define) — keep EXPO_PUBLIC_* there unset or identical.
# Output (GITHUB_OUTPUT): released=true only when the PRODUCTION channel got the update
# on both platforms (mobile-ota.yml then records it for the website register).
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
while IFS='=' read -r key value; do
  [[ "$key" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
  export "$key=$value"
done < <(node -e "const p=require('./eas.json').build[process.argv[1]];if(!p||p.channel!==process.argv[1]){console.error('eas.json: no build profile with channel '+process.argv[1]);process.exit(1)}for(const[k,v]of Object.entries(p.env||{}))console.log(k+'='+v)" "$CHANNEL")

echo "- 📡 channel **${CHANNEL}** (API \`${EXPO_PUBLIC_API_URL:-default}\`)" >>"$SUMMARY"
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

# Both platforms got this bundle on the production channel → mobile-ota.yml records it in
# docs/research/app-release.json, so the website register shows the new breeds as "in the
# app" (David 2026-10-13). A preview update is never a release.
if [[ "$CHANNEL" == production && ${#published[@]} -eq 2 ]]; then
  echo "released=true" >>"${GITHUB_OUTPUT:-/dev/null}"
fi
