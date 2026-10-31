<?php

/*
|--------------------------------------------------------------------------
| Cat wand play — "Palica s peresom" (M5-R06-04, CAT_SPEC §5.2)
|--------------------------------------------------------------------------
| The ~60 s mini-game that is the cat's daily play (CAT_SPEC Q1). The server
| starts a session (schedule: length, the cat's pounces, the catch at the
| end) and judges the finish like the training mini-game: the app reports
| only the feather moves it saw ({t: ms since start, away: bool} — `away` =
| the feather moved AWAY from the cat, like prey; C11: "moving away from the
| cat … avoid dangling it in front of your cat's face").
|
| A session counts (play meter, routine, the 2 h gap) only when the child
| really took part (David 2026-10-08 ~20:40):
|   - it is finished no earlier than `session_seconds` − `finish_early_
|     tolerance_seconds` after the start and before the TTL;
|   - at least `min_away_moves` "away" moves (moves closer than
|     `min_move_interval_ms` count once — no tap spamming),
|   - in every one of `segments` equal parts of the game (spread over the
|     whole minute, not one burst),
|   - at least `min_away_share` of all moves were "away" (the right
|     technique, C11),
|   - an "away" move within `pounce_window_ms` after every pounce cue of the
|     schedule (the child reacts to the cat),
|   - and the gaps between moves are not machine-regular (`uniform_*`).
| A failed / aborted / expired session has no consequence: no penalty and
| the child can start again at once.
|
| These are the v1 rules (old apps, a start without `rules: 2`); the v2
| stars rules are under `v2` below (GAMES_SPEC §5.3).
|
| Numbers are mini-game mechanics (game abstraction, Claude 2026-10-08 —
| DECISIONS.md), not cat data — so they live here, not in
| breed_stage_params. `session_seconds` = 60 is David's decision; the daily
| goal (play_sessions_per_day) and the 2 h gap (play_min_gap_minutes) are
| per-breed data in breed_stage_params.
*/

return [
    // David 2026-10-08 ~20:40: one game lasts ~60 s and ends with the catch.
    'session_seconds' => 60,

    // A finish this much before the scheduled end still counts (clock skew,
    // the catch animation may end a moment early).
    'finish_early_tolerance_seconds' => 5,

    // TTL: a finish is accepted until this long after the scheduled end.
    'finish_grace_seconds' => 60,

    // Participation check (see above).
    'min_away_moves' => 8,
    'segments' => 4,
    'min_move_interval_ms' => 300,
    'min_away_share' => 0.5,

    // QA M5-R06-04: the child must react to the cat. After every pounce cue of
    // the server schedule at least one "away" move must follow within this
    // window (the feather escapes the pounce) — a script that does not read
    // the schedule fails.
    'pounce_window_ms' => 2000,

    // QA M5-R06-04 (like TrainingService's "too uniform" check, but refusing):
    // with at least `uniform_min_gaps` gaps between counted moves, gaps that
    // all lie within `uniform_max_spread_ms` of each other look scripted → the
    // game does not count. The app sends ONE move per finger stroke (not a
    // sampled stream), so human gaps vary by far more.
    'uniform_min_gaps' => 6,
    'uniform_max_spread_ms' => 60,

    // Validation upper bound for one finish request.
    'max_moves' => 600,

    // The cat pounces this many times during a game (animation cues of the
    // server schedule; no scoring), never in the first / last `pounce_margin_ms`.
    'pounces_min' => 3,
    'pounces_max' => 5,
    'pounce_margin_ms' => 8000,

    /*
    |----------------------------------------------------------------------
    | Rules v2 — one ~25 s hunt scored in stars (GAMES_SPEC §5.2–§5.4,
    | David 2026-10-30, P1 + P2)
    |----------------------------------------------------------------------
    | Only for a session started with `rules: 2` (a new app); without it the
    | keys above stay the v1 rules byte for byte. Schedule: stalk 0–stalk_ms,
    | pounce p1 ∈ first_pounce_ms, p(n+1) = p(n) + pounce_gap_ms, crouch cue
    | p − crouch_cue_lead_ms, catch c = p_last + catch_after_last_ms, end =
    | c + catch_clip_ms (~23–29 s; never longer than round_ms).
    | Stars: one per pounce p when (1) at most calm_max_moves counted moves
    | in [p − calm_window_ms, p), (2) the FIRST away move after p lies in
    | [p + dodge_window_ms[0], p + dodge_window_ms[1]], (3) it ended inside
    | the play zone (`in_zone`, app) and (4) it was a clean stroke
    | (`speed_ok`, app: ≤ max_speed_pt_s and ≥ min_dodge_pt); one for the
    | catch when no away move lies in [c, c + catch_window_ms] and the
    | feather is in the zone. The hunt counts at ≥ min_stars.
    | `too_uniform` (v1 keys) is only logged in v2. A v2 finish is accepted
| from catch + catch_window_ms at the earliest (no early-finish tolerance
| inside the catch window — QA #461 m1).
    */
    'v2' => [
        // Upper bound of a v2 hunt (the drawn schedule is ~23–29 s).
        'round_ms' => 30000,
        'stalk_ms' => 3000,
        'pounces' => 3,
        'first_pounce_ms' => [6000, 7500],
        'pounce_gap_ms' => [5000, 6500],
        // The calm rule — decided by David 2026-10-31 (strict rule, GAMES_SPEC §5.3, QA #461 M3):
        // the 👀 crouch cue shows `crouch_cue_lead_ms` before each pounce (→ the schedule's
        // `crouch_cues_ms` = p − 1500) and the feather must be completely still in the last
        // `calm_window_ms` before the pounce (`calm_max_moves` counted moves in [p − 1000, p)).
        // Why: with the old 900 / 800 / 1 a steady slow mover (one stroke every ~0.8–1.2 s) got
        // the stars in ~90 % of hunts; now ≈ 0 %, while a fair child who stops mid-stroke up to
        // ~0.45 s after the cue still gets every star. Keep crouch_cue_lead_ms ≥ calm_window_ms
        // + a reaction time, or a fair child cannot comply. Values are copied into each
        // session's schedule (a running hunt keeps the rule it started with).
        'crouch_cue_lead_ms' => 1500,
        'calm_window_ms' => 1000,
        'calm_max_moves' => 0,
        // The first away move after the pounce must lie in [p + 150, p + 1500].
        'dodge_window_ms' => [150, 1500],
        'catch_after_last_ms' => 3500,
        'catch_window_ms' => 1500,
        'catch_clip_ms' => 3500,
        'min_stars' => 2,
        // What the app checks for `speed_ok` (sent to the app in the schedule).
        'max_speed_pt_s' => 4000,
        'min_dodge_pt' => 40,
    ],
];
