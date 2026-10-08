<?php

/*
|--------------------------------------------------------------------------
| Play & cuddle (M5-R05, PLAY_CUDDLE_SPEC)
|--------------------------------------------------------------------------
| Breed-independent numbers of the play feature. Play has no consequences
| (mood / video only), so these are not in breed_stage_params (no source
| exists). Values marked (D) are Claude's proposals in the spec, waiting for
| David — change them here.
*/

return [
    // (D) Q2: legacy-profile pets (created before the puppy picker) get no play.
    'legacy_pets' => false,

    // (D) §3.1: free play is not possible during quiet hours (the dog sleeps).
    'free_play_in_quiet_hours' => false,

    // (D) §3.2: invitations per family-local day per pet — 1 ball game + 1 cuddle
    // (at most 2: one per kind and day).
    'invitations_per_day' => 2,

    // (D) §3.2: invitations fall on a whole minute inside this family-local band
    // ([start, end)) and outside quiet hours.
    'day_band' => ['07:00', '20:00'],

    // (D) §3.2: minimum real time between the day's two invitations.
    'min_gap_minutes' => 180,

    // (D) §3.2: an invitation stays open this long (cut at the next quiet hours).
    'open_minutes' => 120,

    // David 2026-10-08 (Q5): the dog is "happy" for 30 minutes after a play.
    'happy_minutes' => 30,

    // (D) §7: at most one parent-timeline row per child and kind in this many
    // minutes — later plays only increment that row's value (count).
    'timeline_merge_minutes' => 60,

    // (D) §12.5: the same child finishing the same kind again within this many
    // seconds is a double tap / retry (200, no new row).
    'repeat_seconds' => 10,

    // (D) §3.2 / Q4: an invitation shows only while hunger and thirst show more
    // than this (and today's walk goal is reached — David).
    'offer_min_hunger' => 30,
    'offer_min_thirst' => 30,
];
