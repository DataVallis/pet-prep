<?php

/*
| PetPrep product switches.
|
| `cats_enabled` (M5-R06-01; since M5-R06-09 only an override): who may create
| a NEW cat is the superadmin switch /admin → Funkcije (app_settings
| `cats_availability`: off | test_families | everyone, App\Services\AppSettingsService,
| default off). PETPREP_CATS_ENABLED=true forces `everyone` (backwards compatible
| with the pre-admin switch); false / unset lets the admin setting decide. A cat
| is offered only to an app build that declares the `species_cat` client
| feature (App\Services\SpeciesAvailability). Existing cat pets keep working
| when cats are switched off again; only new cats are refused.
*/

return [
    'cats_enabled' => (bool) env('PETPREP_CATS_ENABLED', false),
];
