#!/usr/bin/env bash
# Image digests for the production deploy (D28) — step "Image digests" of the job
# "Deploy to Hetzner Production" in .github/workflows/deploy-production.yml.
#
# The deploy pulls images by DIGEST, never by tag, and only digests that a successful run
# pushed after the image checks (Caddyfile validation + smoke test) passed:
#   1. this run's production-image job ran → its outputs (RUN_APP / RUN_WEB);
#   2. else plan skipped it because run MARKER_RUN already passed the image suite on this
#      tree → the digests recorded in that run's marker artifact ci-green-image-<TREE>.
# Anything missing / malformed / an API error → empty outputs: the server builds the images
# itself (the pre-D28 path). Never fails the job.
#
# Env: REPO, TREE, RUN_APP, RUN_WEB, MARKER_RUN, GH_TOKEN; GITHUB_OUTPUT.
# Test hook: CI_IMAGE_REFS_GH (gh binary). Output: app=<ref@sha256:…>, web=<ref@sha256:…>.
set -Euo pipefail

GH="${CI_IMAGE_REFS_GH:-gh}"
OUT="${GITHUB_OUTPUT:-/dev/stdout}"
REF_RE='^ghcr\.io/[a-z0-9-]+/petprep-(app|web)@sha256:[0-9a-f]{64}$'

log() { echo "ci-image-refs: $*" >&2; }

valid() { # valid APP WEB → 0 when both are digest refs of the right image
  [[ "$1" =~ $REF_RE ]] && [[ "$1" == */petprep-app@* ]] \
    && [[ "$2" =~ $REF_RE ]] && [[ "$2" == */petprep-web@* ]]
}

app="" web="" source=""
if [ -n "${RUN_APP:-}" ] || [ -n "${RUN_WEB:-}" ]; then
  app="${RUN_APP:-}" web="${RUN_WEB:-}" source="this run"
elif [ -n "${MARKER_RUN:-}" ] && [[ "${MARKER_RUN}" =~ ^[0-9]+$ ]] && [[ "${TREE:-}" =~ ^[0-9a-f]{40}$ ]]; then
  dir="$(mktemp -d)"
  trap 'rm -rf "$dir"' EXIT
  if url="$("$GH" api "repos/${REPO}/actions/runs/${MARKER_RUN}/artifacts?name=ci-green-image-${TREE}" \
          --jq '[.artifacts[] | select(.expired | not)][0].archive_download_url // empty' 2>/dev/null)" \
     && [ -n "$url" ] \
     && "$GH" api "$url" > "${dir}/m.zip" 2>/dev/null \
     && unzip -q -o "${dir}/m.zip" -d "$dir" 2>/dev/null \
     && [ -f "${dir}/ci-green.txt" ]; then
    app="$(sed -n 's/^app=//p' "${dir}/ci-green.txt" | head -n1)"
    web="$(sed -n 's/^web=//p' "${dir}/ci-green.txt" | head -n1)"
    source="marker of run ${MARKER_RUN}"
  else
    log "could not read the image marker of run ${MARKER_RUN}"
  fi
fi

if [ -n "$source" ] && valid "$app" "$web"; then
  log "using the images pushed by ${source}: ${app} ${web}"
else
  [ -n "$source" ] && log "no valid digests from ${source} (fork PR / pre-D28 marker?)"
  log "no CI images — the server builds them (slower)."
  app="" web=""
fi
{
  echo "app=${app}"
  echo "web=${web}"
} >> "$OUT"
exit 0
