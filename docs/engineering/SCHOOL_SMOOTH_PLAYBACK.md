# School smooth playback — contract (orchestrator, 2026-10-28)

> **Rules v2 (GAMES_SPEC §4.1 Š1–Š2, David 2026-10-30):** a session started with `rules: 2` no longer plays the `release` clip — R = `MASK_MS` 1200 (the cut `success_loop` → `attention_loop` hides under the feedback mask) and a 2.5 s break follows cue 3. The release derivatives stay stored (0 $, the decision is reversible) and v1 sessions (old builds) keep everything below. Contract: ARCHITECTURE *Games rules v2 — contract for the app*.

David 2026-10-28 (screen recording of a Dobermann puppy's »Place« session on iPhone): school is glitchy — ghost double images, poses jumping back and forth, the dog snapping from lying to standing, frozen stills. Must be smooth.

## Diagnosis (from the recording, 10 fps frame analysis + code)
1. `PetMediaView` crossfades (CROSSFADE_MS) between phase clips of DIFFERENT poses (standing ↔ lying) → ~0.3 s semi-transparent double dog (recording 7 s, 22 s, 41 s).
2. Each phase clip is a NEW player mounted only when its phase starts → loads; meanwhile the still under it is the END pose (`phase.still` = pose image) → dog shows lying, then the clip starts standing → jumps back and forth.
3. No »stand up« clip: after `success` (lying on the mat) the next trial's `attention` (standing) is a hard cut.
4. A cue the dog ignores shows the standing reference STILL for up to ~5 s (frozen).
5. Schedule too tight: TRIAL_SLOT_MS 6 s, obey 0.8–2.5 s after the cue, so the 5 s performing clip is nearly always started 2–4 s INTO the clip (`startAtMs` seek on a just-mounted player) → more jumps.

## David's decisions 2026-10-28
- **6 commands per session (~60 s)** (was 8 × 6 s + 2 s lead-in = 50 s).
- **Stand-up clip = the performing clip played backwards**, made on our server with ffmpeg — **0 $ AI cost**, no fal call.
- Number of commands does not change MB / cost; derived clips are per shared look (stored once, cached once on the phone).
- Orchestrator decisions (tell David): the learning pace per completed session stays the same (progress per on-time praise scaled so 6 trials give what 8 gave); an ignored cue shows the living attention loop (dog looks around), never a frozen still.

## A. Derived clips (backend, ffmpeg, no AI)
For every stored school phase set of a look (`school_<cmd>_attention`, `school_<cmd>_performing`, `school_<cmd>_success`; cmd ∈ sit, come, place, potty), the server stores three DERIVED clips next to them:

| key | made from | content |
|---|---|---|
| `school_<cmd>_attention_loop` | `attention` | forward + reversed (ping-pong) → seamless loop, starts/ends on the clip's first frame (standing reference) |
| `school_<cmd>_success_loop` | `success` | forward + reversed → seamless loop holding the pose |
| `school_<cmd>_release` | `performing` | reversed (pose → standing reference); sped to **≤ 2.5 s** (setpts), so it fits the slot |

- Encoding: H.264 High, yuv420p, CRF ~23, same resolution and fps as the source (cap the long side at 1280 if larger — the `reverse` filter buffers every frame in memory; keep peak memory well under the queue worker's limit), no audio, `+faststart`, constant frame rate. **Exception (frugal clips, David 2026-10-30):** the home-screen clips derived by the same service (low-motion ping-pong loops; and the LEGACY `sleeping_to_idle` reversal — dropped 2026-10-30, made only for slots marked before then until `media:frugal-unreverse` / the next generation replaces them with a fal clip) keep the source size (1076 × 1924) at CRF 21 — full-screen; every ffmpeg call is niced and memory-capped (`prlimit --as` 2048 MB) and one heavy ffmpeg job runs at a time (ARCHITECTURE *Frugal clips*).
- Made by a queued job after the source clip is stored / replaced (also after a quality-check regeneration) and by a scheduled backfill (small batches, every 10 min, idempotent — ops must be automated, David 2026-10-15) for existing looks; plus `media:school-derivatives {--pet=} {--look=} {--dry-run}`. Works on the local disk and the `pet_media_s3` disk. Never counted in the AI budget / ledger (cost 0).
- A derived clip is regenerated when its source file changes (store a source hash / media id + updated_at).
- Payload (`PetMediaPayload`): the derived keys appear in `media.phases` (signed URLs) and `media.phase_seconds` (their real lengths) like any phase clip. Older apps ignore unknown keys.

## B. New school schedule (backend `TrainingService`)
- `TRIALS_PER_SESSION = 6`. Lead-in 2 s.
- Per trial, with `P` = the pet's stored `school_<cmd>_performing` length in ms (`phase_seconds`; 3 000 when the pet has none / picture mode does not matter — the server always uses the stored length or 3 000) and `R` = `school_<cmd>_release` length (2 500 when not stored yet):
  - `cue_at_ms`: previous trial's slot end (first = lead-in).
  - obeys: `perform_at_ms = cue + think`, `think` random **300–1 500 ms** (the dog »thinks«; keeps the timing unpredictable); `obey_at_ms = perform_at + P` (the dog reaches the pose exactly when the clip ends); `window_end_ms = obey_at + PRAISE_WINDOW_MS (1 500)`; `release_at_ms = window_end`; slot end = `release_at + R + 700` (short calm moment standing before the next cue).
  - ignores: `perform_at_ms`, `obey_at_ms`, `window_end_ms`, `release_at_ms` = null; slot end = `cue + 4 500`.
  - `duration_ms` = last slot end.
- New optional trial fields **`perform_at_ms`** and **`release_at_ms`** (int|null) in the start response / resumed schedule; existing fields unchanged; scoring rules unchanged (obey_at + MIN_REACTION_MS … window_end; first tap per trial decides; taps between trials ignored as today).
- Obey chance logic unchanged except it now picks among 6 trials (keep the "at least one obeys / at least one ignores" guarantees).
- Learning pace: progress per on-time praise × 8/6 so a full session moves a skill as much as before (document the constant in PRODUCT_SPEC).
- A schedule already started with the old shape keeps working (resume returns it as stored; fields absent → app falls back).

## C. App (mobile)
- `modules/petMedia/phasePlayer.ts#schoolMomentAt`: when the trial has `perform_at_ms`, use the server's times directly — attention_loop (loop) until `perform_at`; `performing` once from frame 0 (never seeked) until `obey_at`; `success_loop` (loop) until `release_at`; `release` once; then attention_loop until the next cue. An ignored cue: attention_loop continues (text »would rather play«). Without `perform_at_ms` (old server / old schedule) keep today's logic.
- **School stage player:** all clips of the command (attention_loop, performing, success_loop, release — whichever exist) are mounted ONCE when the session screen opens, each in its own persistent player from the local media cache, paused at frame 0 and hidden. The session clock starts only when all are ready (existing »Your pup is getting ready …«; bounded wait — if not ready in 12 s, run this session in picture mode). A phase change = instant switch (no crossfade) to an already-rendered player + `currentTime = 0` + play; the previous one pauses after the switch. Never mount / load during the session.
- Under a clip never show the END pose; the still = the clip's START frame (attention/performing → reference image, success_loop → pose image, release → pose image).
- Missing derived clips (server not backfilled yet): attention_loop → `attention` looping; success_loop → `success` holding its last frame; release missing → hard cut to attention (no crossfade). No ghosting in any fallback.
- Text: the previous trial's feedback line clears at the next cue; the command bubble (»Place!«) shows from cue until obey.
- Picture mode (M5-T11) unchanged in behaviour but uses the same server times.
- Ball / wand games are NOT in scope.
