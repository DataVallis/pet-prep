#!/usr/bin/env bash
# ffmpeg + ffprobe for the backend test job (.github/workflows/deploy-production.yml,
# step "Install ffmpeg"; DEPLOYMENT.md D24, David 2026-10-26).
#
# MediaQualityCheckTest renders tiny H.264 clips with ffmpeg and measures them with ffprobe
# (it skips those tests when either binary is missing). apt on the runner was the weak spot:
# `apt-get update` took 25–28 min on 2026-10-23, and on 2026-10-26 (deploy run 40311299855)
# the apt install hit the step's 5-minute timeout and was killed half-way — Pest then failed
# and nothing was deployed that day. So: no apt at all.
#
#   1. ffmpeg + ffprobe already on PATH and they pass the smoke test → use them.
#   2. $CACHE_DIR/bin (restored by actions/cache) passes the smoke test → put it on PATH.
#   3. Download a static x86_64 build (FFMPEG_URLS in order; each with ATTEMPTS tries and
#      linear backoff, every curl bounded by the remaining DEADLINE_SECONDS), extract
#      ffmpeg + ffprobe into a temp dir, smoke-test them, and only then move them to
#      $CACHE_DIR/bin (the workflow saves it to the cache) and put that on PATH.
#   4. Nothing works → ::warning, exit 0 — the ffmpeg tests skip with a reason, nothing on
#      PATH is half-installed (the production-image job still proves ffmpeg / ffprobe are in
#      the app image).
# The smoke test = what the tests do: encode 1 s of testsrc2 with libx264 and read its
# duration with ffprobe. Binaries that fail it are never put on PATH.
#
# Output (GITHUB_OUTPUT): source=system|cache|download|none. Appends to GITHUB_PATH.
# Env: FFMPEG_CACHE_DIR (default ~/.cache/petprep-ffmpeg), FFMPEG_URLS (space-separated),
# FFMPEG_ATTEMPTS (3), FFMPEG_BACKOFF_SECONDS (10), FFMPEG_MAX_TIME (180 s per download),
# FFMPEG_DEADLINE_SECONDS (360 — below the step's 8-minute timeout, so the script always
# ends itself). Test hooks: CI_FFMPEG_CURL, CI_FFMPEG_SLEEP, CI_FFMPEG_SYSTEM_DIR (look for
# the preinstalled binaries there instead of PATH), CI_FFMPEG_ARCH.
set -Eeuo pipefail

CACHE_DIR="${FFMPEG_CACHE_DIR:-${HOME}/.cache/petprep-ffmpeg}"
URLS="${FFMPEG_URLS:-https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-linux64-gpl.tar.xz https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz}"
ATTEMPTS="${FFMPEG_ATTEMPTS:-3}"
BACKOFF="${FFMPEG_BACKOFF_SECONDS:-10}"
MAX_TIME="${FFMPEG_MAX_TIME:-180}"
DEADLINE="${FFMPEG_DEADLINE_SECONDS:-360}"
CURL="${CI_FFMPEG_CURL:-curl}"
SLEEP="${CI_FFMPEG_SLEEP:-sleep}"
ARCH="${CI_FFMPEG_ARCH:-$(uname -m)}"
OUT="${GITHUB_OUTPUT:-/dev/null}"
GH_PATH="${GITHUB_PATH:-/dev/null}"

log() { echo "ci-install-ffmpeg: $*"; }
finish() { # finish SOURCE [DIR]: record the source, put DIR on PATH, exit 0
  echo "source=$1" >> "$OUT"
  if [ -n "${2:-}" ]; then echo "$2" >> "$GH_PATH"; fi
  exit 0
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# works DIR: ffmpeg + ffprobe in DIR encode a 1 s H.264 clip and probe its duration.
works() {
  local ff="$1/ffmpeg" fp="$1/ffprobe" clip="$WORK/smoke-$RANDOM.mp4" dur
  [ -x "$ff" ] && [ -x "$fp" ] || return 1
  timeout 60 "$ff" -v error -y -f lavfi -i testsrc2=size=64x64:rate=5:duration=1 \
      -pix_fmt yuv420p -c:v libx264 -preset ultrafast "$clip" < /dev/null > /dev/null 2>&1 || return 1
  dur="$(timeout 30 "$fp" -v error -show_entries format=duration -of csv=p=0 "$clip" 2> /dev/null)" || return 1
  rm -f "$clip"
  [[ "$dur" =~ ^[0-9]+(\.[0-9]+)?$ ]]
}
version() { "$1/ffmpeg" -hide_banner -version 2> /dev/null | head -n1 || true; }

# --- 1. preinstalled ---------------------------------------------------------------
if [ -n "${CI_FFMPEG_SYSTEM_DIR+set}" ]; then
  system_dir="$CI_FFMPEG_SYSTEM_DIR"
else
  system_dir=""
  if ff_path="$(command -v ffmpeg)" && fp_path="$(command -v ffprobe)" && [ "$(dirname "$ff_path")" = "$(dirname "$fp_path")" ]; then
    system_dir="$(dirname "$ff_path")"
  fi
fi
if [ -n "$system_dir" ] && works "$system_dir"; then
  log "using the runner's ffmpeg in ${system_dir}: $(version "$system_dir")"
  finish system
fi
[ -z "$system_dir" ] || log "ffmpeg in ${system_dir} fails the smoke test — installing a static build"

# --- 2. cache ------------------------------------------------------------------------
if works "${CACHE_DIR}/bin"; then
  log "using the cached static build: $(version "${CACHE_DIR}/bin")"
  finish cache "${CACHE_DIR}/bin"
fi

# --- 3. download ---------------------------------------------------------------------
if [ "$ARCH" != x86_64 ]; then
  echo "::warning title=ffmpeg::no static build for ${ARCH} — the ffmpeg tests in MediaQualityCheckTest will skip"
  finish none
fi
for url in $URLS; do
  for attempt in $(seq 1 "$ATTEMPTS"); do
    remaining=$((DEADLINE - SECONDS))
    if [ "$remaining" -lt 15 ]; then
      log "deadline of ${DEADLINE}s reached"
      break 2
    fi
    max_time=$((remaining < MAX_TIME ? remaining : MAX_TIME))
    log "download ${attempt}/${ATTEMPTS}: ${url} (max ${max_time}s)"
    rm -rf "${WORK:?}/archive" "${WORK:?}/x" "${WORK:?}/bin"
    if ! "$CURL" -fsSL --connect-timeout 20 --max-time "$max_time" -o "$WORK/archive" "$url"; then
      log "download failed"
      if [ "$attempt" -lt "$ATTEMPTS" ]; then "$SLEEP" $((BACKOFF * attempt)); fi
      continue
    fi
    mkdir -p "$WORK/x" "$WORK/bin"
    if ! tar -xJf "$WORK/archive" -C "$WORK/x" 2> /dev/null; then
      log "archive is not a readable .tar.xz"
      if [ "$attempt" -lt "$ATTEMPTS" ]; then "$SLEEP" $((BACKOFF * attempt)); fi
      continue
    fi
    ff="$(find "$WORK/x" -type f -name ffmpeg | head -n1)"
    fp="$(find "$WORK/x" -type f -name ffprobe | head -n1)"
    if [ -z "$ff" ] || [ -z "$fp" ]; then
      log "archive has no ffmpeg / ffprobe — next source"
      continue 2
    fi
    cp "$ff" "$fp" "$WORK/bin/"
    chmod 0755 "$WORK/bin/ffmpeg" "$WORK/bin/ffprobe"
    if ! works "$WORK/bin"; then
      log "this build fails the smoke test — next source"
      continue 2
    fi
    rm -rf "${CACHE_DIR:?}/bin"
    mkdir -p "$CACHE_DIR"
    mv "$WORK/bin" "${CACHE_DIR}/bin"
    log "installed $(version "${CACHE_DIR}/bin") from ${url}"
    finish download "${CACHE_DIR}/bin"
  done
done

# --- 4. give up, cleanly ---------------------------------------------------------------
echo "::warning title=ffmpeg::no working ffmpeg / ffprobe (sources: ${URLS}) — the ffmpeg tests in MediaQualityCheckTest will skip; the production-image job still checks ffmpeg in the app image"
finish none
