#!/usr/bin/env bash
# OTA or store build? (M5-F10, David 2026-10-12)
#
# For each platform: resolve the app's runtime version (expo-updates `fingerprint` policy:
# a hash of everything native — native modules, native app.json config, icons, splash,
# eas.json). If a finished EAS production build with that runtime version exists, the
# change is JS / assets only → publish an OTA update to the `production` channel.
# Otherwise the change needs a new store build → no update, a warning + job summary line.
# An old build never receives an update for another runtime version, so a wrong guess
# cannot crash the app.
#
# Usage: scripts/mobile-ota.sh <message> [--dry-run]
# Needs: EXPO_TOKEN (EAS robot token), `eas` on PATH, run from the repo root, mobile deps installed.
# The EXPO_PUBLIC_* values of the production build profile (eas.json) are exported so the
# OTA bundle talks to the same API / Reverb / RevenueCat as the store build. `eas update
# --environment production` adds the EAS server variables of that environment on top
# (they win for keys they define) — keep EXPO_PUBLIC_* there unset or identical.
set -euo pipefail

MESSAGE="${1:?usage: mobile-ota.sh <message> [--dry-run]}"
DRY_RUN="${2:-}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/null}"
EAS=(eas)

cd mobile

# The production store build resolves its config with EAS_BUILD_PROFILE=production
# (app.config.ts: APNs mode) — resolve the same config here, or the runtimes never match.
export EAS_BUILD_PROFILE=production

# Same public env as the production store build.
while IFS='=' read -r key value; do
  [[ "$key" == EXPO_PUBLIC_* ]] || continue
  export "$key=$value"
done < <(node -e "const e=require('./eas.json').build.production.env||{};for(const[k,v]of Object.entries(e))console.log(k+'='+v)")

needs_build=()
for platform in ios android; do
  runtime=$(npx expo-updates runtimeversion:resolve --platform "$platform" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s).runtimeVersion))')
  count=$("${EAS[@]}" build:list --platform "$platform" --status finished --channel production \
    --runtime-version "$runtime" --limit 1 --json --non-interactive | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>console.log(JSON.parse(s||"[]").length))')

  if [[ "$count" -gt 0 ]]; then
    echo "📱 $platform: runtime $runtime has a store build → OTA update"
    echo "- 📱 **$platform**: OTA update (runtime \`${runtime:0:12}\`)" >>"$SUMMARY"
    if [[ "$DRY_RUN" != "--dry-run" ]]; then
      "${EAS[@]}" update --channel production --environment production --platform "$platform" \
        --message "$MESSAGE" --non-interactive
    fi
  else
    needs_build+=("$platform")
    echo "::warning title=New store build needed::$platform — native code changed (runtime $runtime has no production build). No OTA update was sent."
    echo "- 🏪 **$platform**: new store build needed (runtime \`${runtime:0:12}\` has no production build) — no OTA sent" >>"$SUMMARY"
  fi
done

if [[ ${#needs_build[@]} -gt 0 ]]; then
  echo "Store build needed for: ${needs_build[*]}"
fi
