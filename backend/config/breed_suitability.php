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
        // M5-R10-03 (David 2026-10-10): flat-faced (brachycephalic) breed — breathing
        // and heat problems (French Bulldog: RKC S78, PDSA S81, Woodgreen S82,
        // VetCompass S84, heat illness S86). A calm chip, never a percentage.
        'brachycephalic_breathing' => 'consider',
        // M5-R10-04 (runbook welfare rule, 2026-10-10): hind-leg / hip conformation is a
        // recognised welfare concern (German Shepherd Dog: RKC Breed Watch S99, RKC
        // breed page S97 hip / elbow tests, PDSA S100, VetCompass S101). A calm chip
        // asking for health-tested parents, never a percentage.
        'hips_hind_legs' => 'consider',
        // M5-R10-05 (runbook welfare rule, 2026-10-10): heart disease (mitral valve) and
        // the painful, skull-shape-related Chiari-like malformation / syringomyelia
        // (Cavalier King Charles Spaniel: PDSA S108, RKC breed page S105 heart scheme /
        // MRI, University of Bristol S110). A calm chip asking for health-tested
        // parents, never a percentage.
        'heart_and_spine' => 'consider',
        // M5-R10-08 (runbook welfare rule, 2026-10-10): long back and short legs —
        // intervertebral disc disease (Dachshund: PDSA S129 / S127, RKC IVDD screening
        // scheme S125, BVA S128). A calm chip asking for spine-screened parents and a
        // careful life (no jumping), never a percentage.
        'back_spine' => 'consider',
        // M5-R10-11 (runbook welfare rule, 2026-10-10): skin allergies / dermatitis are an
        // RKC Breed Watch point of concern (West Highland White Terrier: RKC Breed Watch S146
        // "Signs of dermatitis irritation …", PDSA S147 "Westies are known to suffer from skin
        // allergies", VetCompass S148). A calm chip asking for a vet's advice on skin care,
        // never a percentage.
        'sensitive_skin' => 'consider',
        // M5-R10-12 (runbook "new tag" rule, 2026-10-10): a clearly shorter life than most
        // breeds (Bernese Mountain Dog: RKC breed page S152 "Lifespan: Under 10 years", PDSA
        // S154 "Up to 10 years", Woodgreen S155 "7-10 years"). A sourced, important trait no
        // other key covers; a calm chip, never a number or a percentage.
        'shorter_lifespan' => 'consider',
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

        // M5-R10-03: french_bulldog.* (S76–S94), potrdil David 2026-10-10
        // (data.json proposed_game_parameters.french_bulldog.suitability_tags).
        // AKC trait ratings not attempted → RKC (S78), PDSA (S81), Woodgreen (S82).
        // Not `small_children` (PDSA: supervise play), not `other_pets`
        // (Woodgreen "Sociable with pets: Low"), not `sheds` / `low_shedding`
        // (RKC "Sheds: Yes" vs PDSA / Woodgreen "minimal").
        'french_bulldog' => [
            'suits' => [
                // S78 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S78'], 'refs' => ['french_bulldog.suitability.rkc_size_of_home', 'french_bulldog.suitability.rkc_size_of_garden']],
                // S81 "…they tend to get along well with children of all ages which makes them popular family pets."
                ['tag' => 'family_pet', 'source_ids' => ['S81'], 'refs' => ['french_bulldog.suitability.pdsa_family', 'french_bulldog.behaviour.family']],
                // S81 (same statement); S82 "are even tolerant of children". A statement,
                // not a rating; PDSA advises supervising play (caveat kept in data.json).
                ['tag' => 'children', 'source_ids' => ['S81', 'S82'], 'refs' => ['french_bulldog.suitability.pdsa_children', 'french_bulldog.behaviour.family']],
            ],
            'consider' => [
                // S81 "As a flat-faced breed, French Bulldogs can overheat and struggle to breathe
                // really quickly, especially in warmer weather."; S78 narrow nostrils / excess soft
                // tissue; S82 breathing problems, heat stroke; S84 BOAS; S86 heat illness.
                ['tag' => 'brachycephalic_breathing', 'source_ids' => ['S78', 'S81', 'S82', 'S84', 'S86'], 'refs' => [
                    'french_bulldog.health.brachycephaly_boas',
                    'french_bulldog.health.boas_odds_vs_other_dogs',
                    'french_bulldog.health.heat_stroke',
                    'french_bulldog.exercise.heat_and_walk_timing',
                ]],
            ],
        ],
        // M5-R10-04: german_shepherd.* (S95–S102), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.german_shepherd.suitability_tags).
        // Woodgreen not reachable → RKC (S97), PDSA (S100). Not `children` /
        // `small_children` (PDSA "Some can …" + always supervise around young
        // children), not `other_pets` (only pets they grew up with).
        'german_shepherd' => [
            'suits' => [
                // S97 "Exercise: More than 2 hours per day"; S100 "a minimum of two hours of exercise every day".
                ['tag' => 'active_family', 'source_ids' => ['S97', 'S100'], 'refs' => ['german_shepherd.exercise.adult', 'german_shepherd.suitability.pdsa_exercise']],
                // S100 "Some can make great family pets in homes with children of all ages".
                ['tag' => 'family_pet', 'source_ids' => ['S100'], 'refs' => ['german_shepherd.suitability.pdsa_family', 'german_shepherd.behaviour.family']],
                // S97 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S97'], 'refs' => ['german_shepherd.suitability.rkc_size_of_home', 'german_shepherd.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S97 "More than 2 hours per day"; S100 "a minimum of two hours".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S97', 'S100'], 'refs' => ['german_shepherd.exercise.adult']],
                // S97 "Sheds: Yes"; S100 "Be prepared for a lot of shedding".
                ['tag' => 'sheds', 'source_ids' => ['S97', 'S100'], 'refs' => ['german_shepherd.suitability.rkc_shedding', 'german_shepherd.suitability.pdsa_shedding']],
                // S97 "Grooming: More than once a week" (PDSA S100: a few times a week).
                ['tag' => 'frequent_grooming', 'source_ids' => ['S97', 'S100'], 'refs' => ['german_shepherd.suitability.rkc_grooming']],
                // S100 exercise stops barking "out of boredom or having a nibble on the furniture".
                ['tag' => 'chews_when_bored', 'source_ids' => ['S100'], 'refs' => ['german_shepherd.behaviour.boredom_chewing']],
                // S99 "Incorrect hind conformation and/or poor rear movement"; S97 hip / elbow tests;
                // PDSA S100 hips and back legs; VetCompass S101 lower hindquarters.
                ['tag' => 'hips_hind_legs', 'source_ids' => ['S99', 'S97', 'S100', 'S101'], 'refs' => ['german_shepherd.health.hind_conformation', 'german_shepherd.health.hip_elbow_dysplasia']],
            ],
        ],
        // M5-R10-05: cavalier_king_charles_spaniel.* (S103–S110), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.cavalier_king_charles_spaniel.suitability_tags).
        // Woodgreen not found → RKC (S105), PDSA (S108). Not `small_children` (PDSA:
        // supervise play), not `other_pets` (prey drive with small pets), not
        // `often_alone` (separation anxiety).
        'cavalier_king_charles_spaniel' => [
            'suits' => [
                // S105 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S105'], 'refs' => ['cavalier_king_charles_spaniel.suitability.rkc_size_of_home', 'cavalier_king_charles_spaniel.suitability.rkc_size_of_garden']],
                // S108 "Cavaliers are known for making really great family pets".
                ['tag' => 'family_pet', 'source_ids' => ['S108'], 'refs' => ['cavalier_king_charles_spaniel.suitability.pdsa_family', 'cavalier_king_charles_spaniel.behaviour.family']],
                // S108 "They're known to be good around children …" (supervise play).
                ['tag' => 'children', 'source_ids' => ['S108'], 'refs' => ['cavalier_king_charles_spaniel.suitability.pdsa_children']],
            ],
            'consider' => [
                // S105 "Sheds: Yes"; S108 "they shed … when they shed even more".
                ['tag' => 'sheds', 'source_ids' => ['S105', 'S108'], 'refs' => ['cavalier_king_charles_spaniel.suitability.rkc_shedding', 'cavalier_king_charles_spaniel.suitability.pdsa_shedding']],
                // S105 "Grooming: More than once a week"; S108 "you may find they need to be brushed daily".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S105', 'S108'], 'refs' => ['cavalier_king_charles_spaniel.suitability.rkc_grooming', 'cavalier_king_charles_spaniel.suitability.pdsa_grooming']],
                // S108 mitral valve disease "a big problem for this breed" and CM/SM; S105 heart
                // scheme / MRI; S110 skull shape.
                ['tag' => 'heart_and_spine', 'source_ids' => ['S108', 'S105', 'S110'], 'refs' => ['cavalier_king_charles_spaniel.health.heart_mvd', 'cavalier_king_charles_spaniel.health.syringomyelia']],
            ],
        ],
        // M5-R10-06: beagle.* (S111–S117), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.beagle.suitability_tags). Woodgreen not
        // found → RKC (S113), PDSA (S115). Not `children` (only "supervise", no explicit
        // statement), not `apartment` (RKC "Small house"), not `other_pets` (not alone
        // with smaller pets), not `food_motivated_weight` (obesity is common, S116, but no
        // source says the Beagle "loves food").
        'beagle' => [
            'suits' => [
                // S115 "if socialised correctly from a young age Beagles suit family life really well";
                // S113 "popular family companion in modern times".
                ['tag' => 'family_pet', 'source_ids' => ['S115', 'S113'], 'refs' => ['beagle.suitability.pdsa_family', 'beagle.behaviour.family']],
            ],
            'consider' => [
                // S113 "Sheds: Yes"; S115 "a reasonable amount of shedding".
                ['tag' => 'sheds', 'source_ids' => ['S113', 'S115'], 'refs' => ['beagle.suitability.rkc_shedding', 'beagle.suitability.pdsa_shedding']],
                // S115 "When left alone, Beagles can become distressed and bored … may even chew things they shouldn't."
                ['tag' => 'chews_when_bored', 'source_ids' => ['S115'], 'refs' => ['beagle.behaviour.chewing']],
            ],
        ],
        // M5-R10-07: standard_poodle.* (S118–S123), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.standard_poodle.suitability_tags). Woodgreen not
        // found → RKC (S120, S121), PDSA (S122). Not `family_pet` (no family statement), not
        // `small_children` (no explicit rating), not `apartment` (RKC "Large house"). Never
        // "hypoallergenic" — `low_shedding` is the sourced wording ("Sheds: No", "does not moult").
        'standard_poodle' => [
            'suits' => [
                // S122 "They generally get on well with other pets and children, given the right socialisation as puppies, as with all breeds."
                ['tag' => 'children', 'source_ids' => ['S122'], 'refs' => ['standard_poodle.suitability.pdsa_children', 'standard_poodle.behaviour.family']],
                // S120 "Size of home: Large house"; "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S120'], 'refs' => ['standard_poodle.suitability.rkc_size_of_home', 'standard_poodle.suitability.rkc_size_of_garden']],
                // S122 (same sentence) — other pets, with the general socialisation caveat.
                ['tag' => 'other_pets', 'source_ids' => ['S122'], 'refs' => ['standard_poodle.suitability.pdsa_other_pets', 'standard_poodle.behaviour.other_pets']],
                // S120 "Sheds: No"; S121 "a type of coat which does not moult".
                ['tag' => 'low_shedding', 'source_ids' => ['S120', 'S121'], 'refs' => ['standard_poodle.suitability.rkc_shedding', 'standard_poodle.suitability.rkc_non_moulting_coat']],
            ],
            'consider' => [
                // S120 "Grooming: Every day"; S122 "They need daily grooming" + professional clipping.
                ['tag' => 'frequent_grooming', 'source_ids' => ['S120', 'S122'], 'refs' => ['standard_poodle.suitability.rkc_grooming', 'standard_poodle.suitability.pdsa_grooming']],
            ],
        ],
        // M5-R10-08: dachshund.* (S124–S130), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.dachshund.suitability_tags). Woodgreen not
        // looked up → RKC (S125, S126), PDSA (S127, S129), BVA (S128). Not `small_children`
        // (supervise), not `other_pets` (not with smaller pets), not `apartment` / `large_home`
        // (RKC "Small house"), never `often_alone` (does not do well alone, S127).
        'dachshund' => [
            'suits' => [
                // S127 "Dachshunds love people and attention so generally get along well with children of all ages."
                ['tag' => 'children', 'source_ids' => ['S127'], 'refs' => ['dachshund.suitability.pdsa_children', 'dachshund.behaviour.family']],
            ],
            'consider' => [
                // S129 "Certain breeds such as the Dachshund and French bulldog are prone to IVDD and slipped discs due to the shape of their spine."
                ['tag' => 'back_spine', 'source_ids' => ['S129', 'S127', 'S125', 'S128'], 'refs' => ['dachshund.health.back_ivdd', 'dachshund.health.conformation_welfare']],
                // S125 "Sheds: Yes"; S127 "they will shed throughout the year".
                ['tag' => 'sheds', 'source_ids' => ['S125', 'S127'], 'refs' => ['dachshund.suitability.rkc_shedding', 'dachshund.suitability.pdsa_shedding']],
                // S127 "give your Dachshund lots to keep their brain active to stop them getting bored."
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S127'], 'refs' => ['dachshund.behaviour.mental_stimulation']],
            ],
        ],
        // M5-R10-09: australian_shepherd.* (S131–S135), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.australian_shepherd.suitability_tags). Woodgreen not
        // looked up → RKC (S133), PDSA (S134). Not `children` / `small_children` (PDSA advises
        // against it with smaller children — herding), not `other_pets` (conditional, may herd
        // them), not `first_time_owner` (easy to train for experienced owners), never `often_alone`.
        // Not a welfare-concern breed (RKC Breed Watch Category 1) → no welfare chip.
        'australian_shepherd' => [
            'suits' => [
                // S133 "Exercise: More than 2 hours per day"; S134 "only recommend having one if you’re on the go just as much as they are".
                ['tag' => 'active_family', 'source_ids' => ['S133', 'S134'], 'refs' => ['australian_shepherd.exercise.adult', 'australian_shepherd.exercise.energy']],
                // S134 "Aussies can make really good family pets in the right household."
                ['tag' => 'family_pet', 'source_ids' => ['S134'], 'refs' => ['australian_shepherd.suitability.pdsa_family', 'australian_shepherd.behaviour.family']],
                // S133 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S133'], 'refs' => ['australian_shepherd.suitability.rkc_size_of_home', 'australian_shepherd.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S133 "More than 2 hours per day"; S134 "a minimum of two hours exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S133', 'S134'], 'refs' => ['australian_shepherd.exercise.adult', 'australian_shepherd.suitability.pdsa_exercise']],
                // S134 "plenty of exercise and mental stimulation throughout the day".
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S134'], 'refs' => ['australian_shepherd.behaviour.mental_stimulation']],
                // S134 "they can get the urge to herd children in the home"; not recommended with smaller children.
                ['tag' => 'may_herd_children', 'source_ids' => ['S134'], 'refs' => ['australian_shepherd.behaviour.herding_children', 'australian_shepherd.suitability.pdsa_children']],
                // S134 "If they get bored, they can get into all kinds of mischief around the home (which usually involves a lot of chewing!)."
                ['tag' => 'chews_when_bored', 'source_ids' => ['S134'], 'refs' => ['australian_shepherd.behaviour.boredom_chewing']],
                // S133 "Sheds: Yes"; S134 "Your Aussie will shed throughout the year".
                ['tag' => 'sheds', 'source_ids' => ['S133', 'S134'], 'refs' => ['australian_shepherd.suitability.rkc_shedding', 'australian_shepherd.suitability.pdsa_shedding']],
                // S133 "Grooming: More than once a week"; S134 "a brush a few times a week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S133', 'S134'], 'refs' => ['australian_shepherd.suitability.rkc_grooming', 'australian_shepherd.suitability.pdsa_grooming']],
            ],
        ],
        // M5-R10-10: havanese.* (S136–S141), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.havanese.suitability_tags). Woodgreen not
        // looked up → FCI (S136), RKC (S138), PDSA (S140). Not `small_children` (no explicit
        // rating), not `first_time_owner` ("easy to train" is not such a statement), not
        // `active_family` / `long_daily_exercise` (30 min). Never "hypoallergenic" —
        // `low_shedding` is the sourced wording (RKC "Sheds: No"). Not a welfare-concern breed
        // (RKC Breed Watch Category 1) → no welfare chip.
        'havanese' => [
            'suits' => [
                // S138 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S138'], 'refs' => ['havanese.suitability.rkc_size_of_home', 'havanese.suitability.rkc_size_of_garden']],
                // S140 "They enjoy being in the centre of the family circle and make excellent family pets".
                ['tag' => 'family_pet', 'source_ids' => ['S140'], 'refs' => ['havanese.suitability.pdsa_family', 'havanese.behaviour.family']],
                // S136 "He loves children and plays endlessly with them." A breed-standard
                // statement, not a rating (PDSA: given the right socialisation — kept in data.json).
                ['tag' => 'children', 'source_ids' => ['S136'], 'refs' => ['havanese.suitability.fci_children', 'havanese.behaviour.family']],
                // S138 "Sheds: No".
                ['tag' => 'low_shedding', 'source_ids' => ['S138'], 'refs' => ['havanese.suitability.rkc_shedding']],
            ],
            'consider' => [
                // S138 "Grooming: Every day"; S140 "requires daily grooming to prevent knots and tangles".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S138', 'S140'], 'refs' => ['havanese.suitability.rkc_grooming', 'havanese.suitability.pdsa_grooming']],
            ],
        ],
        // M5-R10-11: west_highland_white_terrier.* (S142–S149), runbook rules 2026-10-10
        // (data.json proposed_game_parameters.west_highland_white_terrier.suitability_tags).
        // Woodgreen not looked up → RKC (S144, S146), PDSA (S147). Not `small_children`
        // (PDSA: always supervise), not `other_pets` (high prey drive — no smaller pets), not
        // `often_alone` (separation anxiety), not `first_time_owner` (classes recommended).
        // Welfare rule: RKC Breed Watch Category 2 dermatitis (S146) → new `sensitive_skin`.
        'west_highland_white_terrier' => [
            'suits' => [
                // S144 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S144'], 'refs' => ['west_highland_white_terrier.suitability.rkc_size_of_home', 'west_highland_white_terrier.suitability.rkc_size_of_garden']],
                // S147 "Westies are good-natured and loving family pets who adapt well to both countryside and city living".
                ['tag' => 'family_pet', 'source_ids' => ['S147'], 'refs' => ['west_highland_white_terrier.suitability.pdsa_family', 'west_highland_white_terrier.behaviour.family']],
                // S147 "… ideal for families with children of any age." A statement, not a
                // rating; PDSA adds "always supervise" (caveat kept in data.json).
                ['tag' => 'children', 'source_ids' => ['S147'], 'refs' => ['west_highland_white_terrier.suitability.pdsa_children', 'west_highland_white_terrier.behaviour.family']],
            ],
            'consider' => [
                // S147 "Westies are known to suffer from skin allergies …"; S146 dermatitis point of concern.
                ['tag' => 'sensitive_skin', 'source_ids' => ['S147', 'S146'], 'refs' => ['west_highland_white_terrier.health.sensitive_skin']],
                // S144 "Sheds: Yes"; S147 "they shed throughout the year".
                ['tag' => 'sheds', 'source_ids' => ['S144', 'S147'], 'refs' => ['west_highland_white_terrier.suitability.rkc_shedding', 'west_highland_white_terrier.suitability.pdsa_shedding']],
                // S144 "Grooming: More than once a week"; S147 brushing a few times a week + trims.
                ['tag' => 'frequent_grooming', 'source_ids' => ['S144', 'S147'], 'refs' => ['west_highland_white_terrier.suitability.rkc_grooming', 'west_highland_white_terrier.suitability.pdsa_grooming']],
                // S147 "If they are alone for too long, … could start chewing things around the home."
                ['tag' => 'chews_when_bored', 'source_ids' => ['S147'], 'refs' => ['west_highland_white_terrier.behaviour.chewing']],
            ],
        ],

        // M5-R10-12: bernese_mountain_dog.* (S150–S157), runbook rules (data.json
        // proposed_game_parameters.bernese_mountain_dog.suitability_tags). Not `children` /
        // `small_children`: PDSA S154 advises against families with smaller children while
        // Woodgreen S155 says children of all ages — conflicting sources, neither tag.
        'bernese_mountain_dog' => [
            'suits' => [
                // S153 "A kind and devoted family dog."; S150 "good-natured and devoted to his own people".
                ['tag' => 'family_pet', 'source_ids' => ['S153', 'S150'], 'refs' => ['bernese_mountain_dog.suitability.rkc_family_dog', 'bernese_mountain_dog.behaviour.family']],
                // S152 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S152'], 'refs' => ['bernese_mountain_dog.suitability.rkc_size_of_home', 'bernese_mountain_dog.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S152 "Lifespan: Under 10 years"; S154 "Up to 10 years"; S155 "7-10 years".
                ['tag' => 'shorter_lifespan', 'source_ids' => ['S152', 'S154', 'S155'], 'refs' => ['bernese_mountain_dog.health.shorter_lifespan', 'bernese_mountain_dog.suitability.rkc_lifespan']],
                // S152 "Sheds: Yes"; S154 "be prepared for a lot of shedding!".
                ['tag' => 'sheds', 'source_ids' => ['S152', 'S154'], 'refs' => ['bernese_mountain_dog.suitability.rkc_shedding', 'bernese_mountain_dog.suitability.pdsa_shedding']],
                // S152 "Grooming: More than once a week"; S154 "Their coats are fairly high maintenance.".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S152', 'S154'], 'refs' => ['bernese_mountain_dog.suitability.rkc_grooming', 'bernese_mountain_dog.suitability.pdsa_grooming']],
            ],
        ],
    ],
];
