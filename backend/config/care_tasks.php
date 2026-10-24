<?php

/*
|--------------------------------------------------------------------------
| Breed care mini-games (M5-R14 / R15 / R19 / R20, David 2026-10-24)
|--------------------------------------------------------------------------
| The child's care tasks are server-led sessions in `pet_care_sessions` like the
| grooming (CatChoreService): start → the server's schedule; finish → the app
| reports only the strokes / taps it saw ({t: ms since start}); the server
| judges them with the same pure check as the brushing (CatChoreService::score:
| ≥ `min_strokes`, at least one in each of `segments` equal parts, not
| machine-regular). A failed / stopped / expired / interrupted session has no
| consequence — start again at once.
|
| - `tooth_brushing`, `fold_cleaning`, `ear_cleaning`: the brush scratch card
|   (M5-T14 BrushArea) — 30 s, 10 strokes over three steps.
| - `nail_trim`: tap each claw — 8 claws (two front paws), one step; a short
|   session so the child is not left waiting after the last claw.
|
| Numbers are mini-game mechanics (game abstraction, Claude 2026-10-24 —
| DECISIONS.md), not dog data. HOW OFTEN a task is due is the breed's sourced
| `*_frequency` row (breed_stage_params), never a number here.
|
| `heat` (M5-R14): the summer midday walk warning for flat-faced breeds with a
| `no_walk_in_heat` row — no weather data (CLAUDE.md scope guard): summer =
| June–August (December–February in the southern hemisphere, read from the
| family timezone's location), 11:00–16:00 family time (David 2026-10-24).
*/

return [
    'tasks' => [
        'tooth_brushing' => [
            'session_seconds' => 30,
            'min_strokes' => 10,
            'segments' => 3,
            'finish_early_tolerance_seconds' => 3,
            'finish_grace_seconds' => 60,
        ],
        'fold_cleaning' => [
            'session_seconds' => 30,
            'min_strokes' => 10,
            'segments' => 3,
            'finish_early_tolerance_seconds' => 3,
            'finish_grace_seconds' => 60,
        ],
        'ear_cleaning' => [
            'session_seconds' => 30,
            'min_strokes' => 10,
            'segments' => 3,
            'finish_early_tolerance_seconds' => 3,
            'finish_grace_seconds' => 60,
        ],
        'nail_trim' => [
            'session_seconds' => 12,
            // One tap per claw: 4 claws on each front paw (the app shows each claw once).
            'min_strokes' => 8,
            'segments' => 1,
            'finish_early_tolerance_seconds' => 3,
            'finish_grace_seconds' => 60,
        ],
    ],

    'heat' => [
        // Family-local months of summer, northern / southern hemisphere.
        'summer_months_north' => [6, 7, 8],
        'summer_months_south' => [12, 1, 2],
        // Family-local wall clock [from, to).
        'from' => '11:00',
        'to' => '16:00',
    ],
];
