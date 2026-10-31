# Game sounds (GAMES_SPEC §7.1 GameFx, §7.6)

PetPrep's own sound effects, synthesized from math expressions with ffmpeg `aevalsrc` by
`mobile/scripts/generate-game-sounds.sh` (2026-10-30). No samples, no third-party audio, no
licence obligations. Re-run the script to regenerate them (the output is bit-exact).

| File | Used for | Length |
|---|---|---|
| `whoosh.wav` | ball thrown | 0.28 s |
| `bounce.wav` | thrown ball touches the floor | 0.22 s |
| `whistle.wav` | »Pokliči!« (calling the dog) | 0.46 s |
| `chime.wav` | ball caught (★) | 0.60 s |
| `pop.wav` | returned ball lands in front of the dog | 0.12 s |
| `cue.wav` | wand v2: the 👀 cue — the cat crouches, hold the feather still (2026-10-31) | 0.62 s |
| `swish.wav` | wand v2: the feather escaped the pounce (★ »Ušlo!«) | 0.22 s |
| `purr.wav` | cuddles v2: soft purr while a heart fills (every second of stroking) | 0.50 s |
| `heart.wav` | cuddles v2: a heart is full | 0.34 s |

Rules: short, soft, never scary; no sound for a miss. Mono 22.05 kHz 16-bit WAV, each < 30 kB.
They play only when the parent switch »Zvoki in vibracija v igrah« is on (default) and follow the
phone's silent mode (`playsInSilentMode: false`).
