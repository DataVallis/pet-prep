<?php

/*
| Behaviour events — game rules that are not per breed (M5-R02).
|
| The bladder hold itself is per breed and stage (`breed_stage_params`
| `accident_hold_hours_per_age_month`, S30 / S31). These two numbers are
| PetPrep's own game rules, the same for every puppy
| (App\Services\BehaviourEventService; PRODUCT_SPEC §5 »Luža mladička«).
|
| `morning_take_out_minutes` (David 2026-11-08 »ja, daj 30 min«): when the
| family's overnight quiet stretch ends, a puppy that was not taken out late
| in the night must go out within this many minutes (counted outside quiet
| hours, like the hold) — e.g. night until 07:00 → accident at 07:30.
| 0 switches the morning rule off (the clock then only restarts at midnight).
|
| `night_take_out_counts_minutes` (Claude's default, DECISIONS 2026-11-08 —
| David may change it): a take-out (or a clock restart at birth / the end of a
| freeze / a scheduler outage) in the last this-many minutes of the night
| counts as the morning outing: the normal hold applies from it.
*/

return [
    'puppy' => [
        'morning_take_out_minutes' => 30,
        'night_take_out_counts_minutes' => 60,
    ],
];
