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
];
