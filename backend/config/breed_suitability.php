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
        // PDSA-style "family pet" statement (David 2026-10-09: chip "Družinski pes").
        'family_pet' => 'suits',
        // Only for a breed with a sourced child rating or an explicit statement
        // (Golden Retriever: PDSA S68 "can be fantastic with children", David 2026-10-09).
        'children' => 'suits',
        'small_children' => 'suits',
        'first_time_owner' => 'suits',
        'apartment' => 'suits',
        // RKC "Size of home: Large house" + "Size of garden: Large garden".
        'large_home' => 'suits',
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
        // M5-R10-02 (David 2026-10-09): brushing clearly more than once a week
        // (Golden Retriever: RKC "More than once a week", PDSA ≥ 3× a week, Woodgreen "High").
        'frequent_grooming' => 'consider',
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
                // No source rates the Labrador with children explicitly (RKC shows no such field) →
                // `family_pet`, not `children` (David 2026-10-09).
                ['tag' => 'family_pet', 'source_ids' => ['S53'], 'refs' => ['labrador_retriever.suitability.pdsa_family', 'labrador_retriever.behaviour.family']],
                // S50 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S50'], 'refs' => ['labrador_retriever.suitability.rkc_size_of_home', 'labrador_retriever.suitability.rkc_size_of_garden']],
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

        // M5-R10-02: golden_retriever.* (S63–S75), potrdil David 2026-10-09
        // (data.json proposed_game_parameters.golden_retriever.suitability_tags).
        // AKC trait ratings were not reachable (S75) → RKC (S65), PDSA (S68),
        // Woodgreen (S69), Guide Dogs (S70). Not `mouthy` (no such tag), not
        // `small_children` (PDSA / Guide Dogs: supervise with young children).
        'golden_retriever' => [
            'suits' => [
                // S65 "Exercise: More than 2 hours per day"; S68 "a great family dog for an active family".
                ['tag' => 'active_family', 'source_ids' => ['S65', 'S68'], 'refs' => ['golden_retriever.exercise.adult', 'golden_retriever.suitability.pdsa_family']],
                // S68 "can be a great family dog"; S70 "often make much-loved family dogs".
                ['tag' => 'family_pet', 'source_ids' => ['S68', 'S70'], 'refs' => ['golden_retriever.suitability.pdsa_family', 'golden_retriever.behaviour.family']],
                // S68 "If you have a young family then Golden Retrievers can be fantastic with children."
                // A statement, not a rating; PDSA adds "always supervise" (caveat kept in data.json).
                ['tag' => 'children', 'source_ids' => ['S68'], 'refs' => ['golden_retriever.suitability.pdsa_children']],
                // S68 "can make good first dogs for new dog owners" (long_daily_exercise shown next to it).
                ['tag' => 'first_time_owner', 'source_ids' => ['S68'], 'refs' => ['golden_retriever.suitability.pdsa_first_time_owner']],
                // S65 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S65'], 'refs' => ['golden_retriever.suitability.rkc_size_of_home', 'golden_retriever.suitability.rkc_size_of_garden']],
                // S69 "Sociable with pets: High".
                ['tag' => 'other_pets', 'source_ids' => ['S69'], 'refs' => ['golden_retriever.suitability.woodgreen_sociable_with_pets']],
            ],
            'consider' => [
                // S65 "More than 2 hours per day"; S68 "a minimum of two hours of good exercise per day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S65', 'S68'], 'refs' => ['golden_retriever.exercise.adult']],
                // S65 "Sheds: Yes"; S68 "generally do shed a lot"; S69 "Shedding: High".
                ['tag' => 'sheds', 'source_ids' => ['S65', 'S68', 'S69'], 'refs' => ['golden_retriever.suitability.rkc_shedding', 'golden_retriever.suitability.woodgreen_shedding']],
                // S70 "motivated by food and play"; S68 "they can easily become overweight".
                ['tag' => 'food_motivated_weight', 'source_ids' => ['S70', 'S68'], 'refs' => ['golden_retriever.behaviour.food_motivation', 'golden_retriever.behaviour.obesity_tendency']],
                // S65 "Grooming: More than once a week"; S68 brush "three times a week at a minimum"; S69 "Grooming needs: High".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S65', 'S68', 'S69'], 'refs' => ['golden_retriever.suitability.rkc_grooming', 'golden_retriever.suitability.woodgreen_grooming']],
            ],
        ],
    ],
];
