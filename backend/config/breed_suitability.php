<?php

/*
|--------------------------------------------------------------------------
| "Za koga je primerna" — breed suitability tags (M5-R10, ROADMAP M5-R10)
|--------------------------------------------------------------------------
|
| Shown to the parent in the breed picker (GET /api/breeds → `suitability`).
| The apps translate each tag key (i18n `breedSuitability.<tag>`), so the
| server never sends prose.
|
| `vocabulary`: the ONLY tag keys that exist, each with its kind:
|   - `suits`    — the breed fits this kind of family / home;
|   - `consider` — something a family must be ready for.
| A key never changes kind. Adding a key = add it here + the union in
| BreedCatalogResource + the mobile i18n strings. There is no
| "hypoallergenic" tag and there never will be (no dog is — ROADMAP
| M5-R10, David 2026-10-09); "low_shedding" is the honest wording.
|
| `breeds` (keyed by App\Enums\BreedType value): per kind a list of tags,
| each backed by sources — `source_ids` (docs/research/dog-data/sources.md)
| and `refs` (JSON paths into docs/research/dog-data/data.json whose entry
| cites those sources). Only tags the research supports with a quote; no
| tag is inferred from a breed's reputation. A breed without an entry (the
| mutt, the cats — no sourced suitability data yet) has empty lists.
| BreedSuitabilityTest checks the vocabulary, the kinds, every source id
| and every ref.
|
*/

return [

    'vocabulary' => [
        // suits
        'active_family' => 'suits',
        'children' => 'suits',
        'small_children' => 'suits',
        'first_time_owner' => 'suits',
        'apartment' => 'suits',
        'house_with_garden' => 'suits',
        'other_pets' => 'suits',
        'older_owners' => 'suits',
        'often_alone' => 'suits',
        'low_shedding' => 'suits',
        // consider
        'long_daily_exercise' => 'consider',
        'needs_mental_stimulation' => 'consider',
        'may_herd_children' => 'consider',
        'chews_when_bored' => 'consider',
        'sheds' => 'consider',
        'food_motivated_weight' => 'consider',
    ],

    'breeds' => [

        // Only tags backed by quotes already in data.json border_collie.* (M5-R01 research).
        'border_collie' => [
            'suits' => [
                // S5 "Exercise: More than 2 hours per day"; S4 "a very high energy breed".
                ['tag' => 'active_family', 'source_ids' => ['S5', 'S4'], 'refs' => ['border_collie.exercise.adult', 'border_collie.exercise.energy']],
            ],
            'consider' => [
                // S5 "Exercise: More than 2 hours per day" (PDSA S7: "a minimum of two hours exercise every day").
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S5', 'S7'], 'refs' => ['border_collie.exercise.adult']],
                // S4 "always need to have something to do, so making sure they have mental stimulation is very important".
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S4'], 'refs' => ['border_collie.behaviour.mental_stimulation']],
                // S4 "natural herding instincts may lead them to herd children during play" (PDSA S7 the same).
                ['tag' => 'may_herd_children', 'source_ids' => ['S4', 'S7'], 'refs' => ['border_collie.behaviour.herding_children']],
                // S7 "If left alone or not given enough exercise, your Collie will … let you know … by chewing anything in paw's reach!"
                ['tag' => 'chews_when_bored', 'source_ids' => ['S7'], 'refs' => ['border_collie.behaviour.boredom_chewing']],
            ],
        ],

        // M5-R10-01: labrador_retriever.* (S48–S62). AKC trait ratings were not
        // reachable (data.json suitability._note) → RKC (S50), PDSA (S53), Woodgreen (S60), Guide Dogs (S59).
        'labrador_retriever' => [
            'suits' => [
                // S59 "at least 90 minutes of exercise daily"; S50 "Exercise: More than 2 hours per day".
                ['tag' => 'active_family', 'source_ids' => ['S59', 'S50'], 'refs' => ['labrador_retriever.exercise.adult', 'labrador_retriever.suitability.rkc_exercise']],
                // S53 "Labradors make perfect family pets, given the right socialisation, as with all breeds."
                ['tag' => 'children', 'source_ids' => ['S53'], 'refs' => ['labrador_retriever.suitability.pdsa_family', 'labrador_retriever.behaviour.family']],
                // S50 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'house_with_garden', 'source_ids' => ['S50'], 'refs' => ['labrador_retriever.suitability.rkc_size_of_home', 'labrador_retriever.suitability.rkc_size_of_garden']],
                // S60 "Sociable with pets: High".
                ['tag' => 'other_pets', 'source_ids' => ['S60'], 'refs' => ['labrador_retriever.suitability.woodgreen_sociable_with_pets']],
            ],
            'consider' => [
                // S50 "Sheds: Yes" (Woodgreen S60: "Shedding: Moderate").
                ['tag' => 'sheds', 'source_ids' => ['S50'], 'refs' => ['labrador_retriever.suitability.rkc_shedding']],
                // S59 ≥ 90 min, S50 > 2 h every day.
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S59', 'S50'], 'refs' => ['labrador_retriever.exercise.adult']],
                // S53 "Prone to obesity."; S59 "highly food motivated".
                ['tag' => 'food_motivated_weight', 'source_ids' => ['S53', 'S59'], 'refs' => ['labrador_retriever.behaviour.obesity_tendency', 'labrador_retriever.behaviour.food_motivation']],
            ],
        ],
    ],
];
