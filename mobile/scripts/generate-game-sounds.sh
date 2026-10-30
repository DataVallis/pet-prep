#!/usr/bin/env bash
# Synthesizes the game layer's sound effects (GAMES_SPEC §7.1 GameFx, §7.6) from pure math
# with ffmpeg's `aevalsrc` — no samples, no third-party audio: the files are PetPrep's own.
# Short, soft and never scary (no buzzers, no "wrong" sounds — a miss has no sound at all).
# Output: mobile/assets/sounds/*.wav (mono 22.05 kHz, 16-bit PCM — instant start on both
# platforms, no AAC priming gap); each file stays under 30 kB (checked below).
#
# Usage (from the repo root or mobile/): bash mobile/scripts/generate-game-sounds.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="$here/assets/sounds"
mkdir -p "$out"

synth() { # name duration expression
  local name="$1" dur="$2" expr="$3"
  local fade_start
  fade_start="$(awk -v d="$dur" 'BEGIN { printf "%.3f", d - 0.04 }')"
  ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i "aevalsrc=exprs='${expr}':s=22050:d=${dur}" \
    -af "afade=t=in:d=0.004,afade=t=out:st=${fade_start}:d=0.04" \
    -ac 1 -ar 22050 -c:a pcm_s16le -bitexact -map_metadata -1 "$out/$name.wav"
  local size
  size="$(wc -c < "$out/$name.wav")"
  if [ "$size" -ge 30720 ]; then
    echo "$name.wav is ${size} B (limit 30 kB)" >&2
    exit 1
  fi
  echo "$name.wav ${size} B"
}

# Throw: a soft airy swish (band-limited noise under a smooth bump).
synth whoosh 0.28 "0.22*(random(0)*2-1)*sin(PI*t/0.28)*(0.6+0.4*sin(2*PI*7*t))"
# Ball touches the floor: a rubbery low "boing" with a falling pitch.
synth bounce 0.22 "(0.55*sin(2*PI*(240*t-260*t*t))+0.12*sin(4*PI*(240*t-260*t*t)))*exp(-16*t)"
# Calling the dog: two gentle rising whistle notes ("fiu-fiu").
synth whistle 0.46 "0.32*(if(lt(t,0.16),sin(2*PI*(1300*t+1562*t*t))*sin(PI*t/0.16),0)+if(between(t,0.22,0.44),sin(2*PI*(1300*(t-0.22)+1590*(t-0.22)*(t-0.22)))*sin(PI*(t-0.22)/0.22),0))"
# Success (caught!): a bright two-note chime, C6 then G6, soft decay.
synth chime 0.6 "0.34*sin(2*PI*1046.5*t)*exp(-6*t)+0.10*sin(2*PI*2093*t)*exp(-9*t)+if(gte(t,0.12),0.34*sin(2*PI*1568*(t-0.12))*exp(-6*(t-0.12))+0.08*sin(2*PI*3136*(t-0.12))*exp(-9*(t-0.12)),0)"
# The returned ball lands in front of the dog: a tiny soft pop.
synth pop 0.12 "0.5*sin(2*PI*(520*t-900*t*t))*exp(-30*t)"
