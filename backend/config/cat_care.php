<?php

/*
|--------------------------------------------------------------------------
| Cat care mini-games (M5-R06-05, CAT_SPEC Q3 / Q8 / Q10)
|--------------------------------------------------------------------------
| Server-led sessions in `pet_care_sessions` (like the wand game, M5-R06-04):
| start → the server's schedule; finish → the app reports only what the
| child did and the server judges it. A failed / aborted / expired /
| interrupted session has no consequence: no penalty, start again at once.
|
| - `grooming` ("Počeši muco", Maine Coon only, 3× per program week, ≥ 1
|   day apart): a short rubbing game — the app reports the comb strokes
|   ({t: ms since start}); it counts with ≥ `min_strokes` strokes spread
|   over all `segments` and not machine-regular. While the coat is matted
|   (≥ 2 of the previous week's 3 groomings missed, David 2026-10-08 ~22:20)
|   the next grooming lasts `matted_session_seconds` (~2×) and needs
|   `matted_min_strokes`; it resolves the matted coat.
| - `litter_change` (the weekly full change, CAT_SPEC Q3): dump, wash and
|   refill — the same stroke check as grooming (scrub strokes).
| - `scratching` ("Odnesi na praskalnik in pohvali", CAT_SPEC Q10, AAFP
|   C23 "reward immediately"): the child carries the cat to the scratcher;
|   the cat lands at `land_at_ms` of the schedule (random between the
|   bounds); a praise tap within `praise_window_ms` (3 s, David 2026-10-08)
|   and not earlier than `min_reaction_ms` after the landing resolves the
|   scratching. Never a punishment: a late / early praise just tries again.
|
| Numbers are mini-game mechanics (game abstraction, Claude 2026-10-08 —
| DECISIONS.md), not cat data — except the 3 s praise window (CAT_SPEC Q10)
| and "matted → ~2× longer" (David 2026-10-08 ~22:20).
*/

return [
    'grooming' => [
        'session_seconds' => 30,
        // David 2026-10-08 ~22:20: the grooming that resolves a matted coat is longer (~2×).
        'matted_session_seconds' => 60,
        'min_strokes' => 10,
        'matted_min_strokes' => 20,
        'segments' => 3,
        'finish_early_tolerance_seconds' => 3,
        'finish_grace_seconds' => 60,
    ],

    'litter_change' => [
        'session_seconds' => 30,
        'min_strokes' => 10,
        'segments' => 3,
        'finish_early_tolerance_seconds' => 3,
        'finish_grace_seconds' => 60,
    ],

    // Shared by the stroke games: strokes closer than this count once; ≥
    // `uniform_min_gaps` gaps all within `uniform_max_spread_ms` = scripted.
    'min_stroke_interval_ms' => 150,
    'uniform_min_gaps' => 8,
    'uniform_max_spread_ms' => 40,
    'max_strokes' => 600,

    'scratching' => [
        'land_at_min_ms' => 800,
        'land_at_max_ms' => 2000,
        // CAT_SPEC Q10 (David 2026-10-08 13:47): praise within 3 s.
        'praise_window_ms' => 3000,
        // Human reaction floor (like training's 150 ms).
        'min_reaction_ms' => 150,
        // TTL after the praise window ends.
        'finish_grace_seconds' => 30,
        // A reported praise may lie this far after the server's elapsed time (network).
        'clock_tolerance_ms' => 2000,
    ],
];
