<?php

/*
| PetPrep product switches.
|
| `cats_enabled` (M5-R06-01, M5-R06_PLAN T4): cats stay "dark" until the cat
| rules, media and app UI are finished (M5-R06-09, David's device check). While
| false no client sees a cat in GET /api/breeds and generate-pin with
| `species: cat` → 422 `species_unavailable`. Even when true, a cat is offered
| only to an app build that declares the `species_cat` client feature
| (App\Services\SpeciesAvailability). Existing cat pets keep working when it is
| switched off again; only new cats are refused.
*/

return [
    'cats_enabled' => (bool) env('PETPREP_CATS_ENABLED', false),
];
