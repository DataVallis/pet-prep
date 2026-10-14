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
        // other key covers; a calm chip, never a number or a percentage. Criterion for later
        // breeds: the RKC breed page says "Lifespan: Under 10 years" (or an A/B source gives an
        // upper bound of 10 years or less).
        'shorter_lifespan' => 'consider',
        // M5-R10-13 (runbook "new tag" rule, 2026-10-10): known to escape — digs under and
        // gets over fences, needs a very secure garden with high fences (Siberian Husky: PDSA
        // S162 "Owners need a very secure garden with high fences as Huskies are known for
        // getting up to mischief", "they'll often dig holes to try and tunnel under fences.").
        // A sourced, important trait no other key covers; a calm chip, never a number.
        'secure_fencing' => 'consider',
        // M5-R10-19 (runbook "new tag" rule, 2026-10-11): a powerful dog that needs an
        // experienced owner (Rottweiler: PDSA S169 "Because they're so powerful, Rottweilers
        // need an experienced owner who understands them."). A sourced, important trait no
        // other key covers (first_time_owner is only ever a `suits` tag); a calm chip, never
        // a number.
        'experienced_owner' => 'consider',
        // M5-R10-22 (runbook "new tag" rule, 2026-10-12): a tiny dog with fragile bones that
        // suits families with older children (Yorkshire Terrier: PDSA S194 "They are better
        // suited to families with older children as they have very fragile bones"). A sourced,
        // important trait no other key covers (`small_children` is only ever a `suits` tag);
        // a calm chip, never a number.
        'fragile_older_children' => 'consider',
        // M5-R10-23 (runbook "new tag" rule, 2026-10-12): a sourced advice against homes with
        // small children (Cane Corso: PDSA S199 "We really wouldn’t recommend having a Cane Corso
        // if you have small children" — they could be knocked over). A sourced, important trait no
        // other key covers (`small_children` is only ever a `suits` tag; `fragile_older_children`
        // is about the dog's fragile bones); a calm chip, never a number.
        'not_for_small_children' => 'consider',
        // M5-R10-23 (runbook "new tag" rule, 2026-10-12): the breed is known to drool (Cane
        // Corso: PDSA S199 "Cane Corsos are known to drool."). A sourced trait a family notices
        // every day that no other key covers; a calm chip, never a number.
        'drools' => 'consider',
        // David 2026-10-12 ("Da, želim."): the breed's own entry quotes a high prey drive —
        // it may chase small pets (French Bulldog PDSA S81 "French Bulldogs do have a high prey
        // drive."; Dachshund S127, Westie S147, Siberian Husky S162, Dobermann S211). Only for a
        // verbatim prey-drive statement — a supervision advice alone (Beagle, Bernese,
        // Rottweiler, Cavalier, German Shepherd) is not enough. A calm chip, never a number.
        'chases_small_pets' => 'consider',
        // M5-R10-26 (runbook "new tag" rule, 2026-10-12): loves company — can develop separation
        // anxiety and is best with someone at home most of the day (Boxer: PDSA S219 "Boxers love
        // human company so can develop separation anxiety if left alone.", "It's best to have a Boxer
        // if someone is going to be around the house with them all day."). A sourced, important
        // trait no other key covers (`often_alone` is only ever a `suits` tag); a calm chip, never
        // a number.
        'needs_company' => 'consider',
        // M5-R10-26 (runbook welfare rule, "a new one per the row above", 2026-10-12): a moderately
        // short-faced breed whose muzzle / nostrils are a welfare point of concern — breathing problems in
        // dogs with shorter faces (Boxer: VetCompass S220 "a moderately brachycephalic skull shape", RKC
        // Breed Watch S216 "Excessively short muzzle", PDSA S219 BOAS "in some Boxers with shorter faces").
        // Not `brachycephalic_breathing` (flat face, heat). A calm chip, never a percentage.
        'short_muzzle_breathing' => 'consider',
        // M5-R10-29 (runbook welfare rule, "a new one per the row above", 2026-10-13): eyelids turned
        // inwards or outwards are an RKC Breed Watch point of concern (Great Dane: RKC Breed Watch S244
        // "Eyelids not in normal contact with the eyeball, they are turned inwards or outwards"; PDSA
        // S243 entropion / ectropion; RKC breed page S241 eye scheme). A calm chip asking for eye-tested
        // parents, never a percentage.
        'eyelid_problems' => 'consider',
        // M5-R10-30 (runbook "new tag" rule, 2026-10-13): a small dog that is a better fit for
        // families with older children (Pomeranian: AKC S251 "Pomeranians tend to be a better fit for
        // families with older children."; "It's important that children are old enough to recognize the
        // difference between a small dog and a toy."). Not `fragile_older_children` — that chip names
        // fragile bones, which no A/B source says for this breed. A calm chip, never a number.
        'older_children' => 'consider',
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
                // S81 "French Bulldogs do have a high prey drive." (David 2026-10-12).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S81'], 'refs' => ['french_bulldog.behaviour.other_pets']],
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
                // S127 "Dachshunds do have a high prey drive and love to chase so we wouldn’t recommend keeping them with smaller pets." (David 2026-10-12).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S127'], 'refs' => ['dachshund.behaviour.other_pets']],
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
                // S147 "Westies have quite a high prey drive, so we wouldn't recommend keeping them with smaller pets." (David 2026-10-12).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S147'], 'refs' => ['west_highland_white_terrier.behaviour.other_pets']],
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

        // M5-R10-13: siberian_husky.* (S158–S165), runbook rules (data.json
        // proposed_game_parameters.siberian_husky.suitability_tags). Not `family_pet` /
        // `children` / `small_children`: PDSA S162 recommends adult-only or teenage households
        // and advises against smaller children. Not `other_pets` (high prey drive, S162).
        'siberian_husky' => [
            'suits' => [
                // S160 "Exercise: More than 2 hours per day"; S162 "the perfect pet for active, adventure-loving owners".
                ['tag' => 'active_family', 'source_ids' => ['S160', 'S162'], 'refs' => ['siberian_husky.exercise.adult', 'siberian_husky.exercise.energy']],
                // S160 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S160'], 'refs' => ['siberian_husky.suitability.rkc_size_of_home', 'siberian_husky.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S160 "More than 2 hours per day"; S162 "a minimum of two hours of exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S160', 'S162'], 'refs' => ['siberian_husky.exercise.adult', 'siberian_husky.suitability.pdsa_exercise']],
                // S162 "Owners need a very secure garden with high fences as Huskies are known for getting up to mischief".
                ['tag' => 'secure_fencing', 'source_ids' => ['S162'], 'refs' => ['siberian_husky.behaviour.escaping']],
                // S162 "so you may find they chew furniture to let you know.".
                ['tag' => 'chews_when_bored', 'source_ids' => ['S162'], 'refs' => ['siberian_husky.behaviour.chewing']],
                // S160 "Sheds: Yes"; S162 "Be prepared – Huskies shed a lot!".
                ['tag' => 'sheds', 'source_ids' => ['S160', 'S162'], 'refs' => ['siberian_husky.suitability.rkc_shedding', 'siberian_husky.suitability.pdsa_shedding']],
                // S160 "Grooming: More than once a week"; S162 "brush your Husky a few times a week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S160', 'S162'], 'refs' => ['siberian_husky.suitability.rkc_grooming', 'siberian_husky.suitability.pdsa_grooming']],
                // S162 "Huskies have such a high prey drive that we wouldn’t recommend keeping them with smaller pets" (David 2026-10-12).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S162'], 'refs' => ['siberian_husky.behaviour.other_pets']],
            ],
        ],

        // M5-R10-19: rottweiler.* (S166–S174), runbook rules (data.json
        // proposed_game_parameters.rottweiler.suitability_tags). `family_pet` keeps its
        // condition in the sources ("A well-trained Rottweiler …", S168); not `children` /
        // `small_children` (PDSA S169 does not recommend the breed for families with younger
        // children and babies), not `other_pets` (S169), not `first_time_owner` (S169).
        'rottweiler' => [
            'suits' => [
                // S168 "Exercise: More than 2 hours per day"; S169 "at least two hours of exercise every day".
                ['tag' => 'active_family', 'source_ids' => ['S168', 'S169'], 'refs' => ['rottweiler.exercise.adult', 'rottweiler.suitability.pdsa_exercise']],
                // S168 "A well-trained Rottweiler makes a wonderful family companion."; S169 "can fit in well with family life if well trained".
                ['tag' => 'family_pet', 'source_ids' => ['S168', 'S169'], 'refs' => ['rottweiler.suitability.rkc_family', 'rottweiler.behaviour.family']],
                // S168 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S168'], 'refs' => ['rottweiler.suitability.rkc_size_of_home', 'rottweiler.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S168 "More than 2 hours per day"; S169 "at least two hours of exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S168', 'S169'], 'refs' => ['rottweiler.exercise.adult', 'rottweiler.suitability.pdsa_exercise']],
                // S169 "Because they're so powerful, Rottweilers need an experienced owner who understands them."
                ['tag' => 'experienced_owner', 'source_ids' => ['S169'], 'refs' => ['rottweiler.behaviour.experienced_owner', 'rottweiler.suitability.pdsa_experienced_owner']],
                // Welfare rule: S173 "Incorrect hind conformation and/or poor rear movement"; S168 hip / elbow tests.
                ['tag' => 'hips_hind_legs', 'source_ids' => ['S173', 'S168'], 'refs' => ['rottweiler.health.breed_watch', 'rottweiler.health.hip_elbow_dysplasia']],
                // S168 "Lifespan: Under 10 years"; S171 "Median longevity overall was 9.0 years".
                ['tag' => 'shorter_lifespan', 'source_ids' => ['S168', 'S171'], 'refs' => ['rottweiler.lifespan.rkc', 'rottweiler.lifespan.median_uk']],
                // S168 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S168'], 'refs' => ['rottweiler.suitability.rkc_shedding']],
            ],
        ],

        // M5-R10-20: bulldog.* (S175–S183), runbook rules (data.json
        // proposed_game_parameters.bulldog.suitability_tags). Flat-faced welfare breed →
        // `brachycephalic_breathing` (as the French Bulldog); Breed Watch skin-fold
        // dermatitis → `sensitive_skin` (as the Westie). Not `children` / `small_children`
        // (no child rating or statement), not `apartment` (RKC "Small house", S177).
        'bulldog' => [
            'suits' => [
                // S177 "The Bulldog remains hugely popular as a family companion for his great character and loyalty."
                ['tag' => 'family_pet', 'source_ids' => ['S177'], 'refs' => ['bulldog.suitability.rkc_family', 'bulldog.behaviour.family']],
                // S180 "… will get on well with other household pets if given the right socialisation when young."
                ['tag' => 'other_pets', 'source_ids' => ['S180'], 'refs' => ['bulldog.behaviour.other_pets', 'bulldog.suitability.pdsa_other_pets']],
            ],
            'consider' => [
                // Welfare rule: S180 "Bulldogs are a severely brachycephalic (flat-faced) breed."; S177 airway soft tissue;
                // S182 "Signs of respiratory difficulty or distress …"; S93 "Stop and think before buying a flat-faced dog."
                ['tag' => 'brachycephalic_breathing', 'source_ids' => ['S180', 'S177', 'S182', 'S93'], 'refs' => ['bulldog.health.brachycephaly_boas', 'bulldog.health.breed_watch', 'bulldog.exercise.heat_and_walk_timing']],
                // Welfare rule: S182 "Signs of dermatitis irritation in skin folds …"; S180 "their facial wrinkles will need daily cleaning."
                ['tag' => 'sensitive_skin', 'source_ids' => ['S182', 'S180'], 'refs' => ['bulldog.health.breed_watch', 'bulldog.health.skin_folds']],
                // S177 "Lifespan: Under 10 years"; S180 the same; S54 McMillan median 9.8 y.
                ['tag' => 'shorter_lifespan', 'source_ids' => ['S177', 'S180', 'S54'], 'refs' => ['bulldog.lifespan.rkc', 'bulldog.lifespan.pdsa', 'bulldog.lifespan.median_uk']],
                // S177 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S177'], 'refs' => ['bulldog.suitability.rkc_shedding']],
            ],
        ],

        // M5-R10-21: german_shorthaired_pointer.* (S184–S189), runbook rules (data.json
        // proposed_game_parameters.german_shorthaired_pointer.suitability_tags). No welfare chip
        // (no conformation concern found). Not `family_pet` / `children` (only a gundog-group
        // sentence on the RKC page, S187), not `apartment` (RKC "Large House").
        'german_shorthaired_pointer' => [
            'suits' => [
                // S187 "More than 2 hours per day"; S188 "over 2 hours ideally per day".
                ['tag' => 'active_family', 'source_ids' => ['S187', 'S188'], 'refs' => ['german_shorthaired_pointer.exercise.adult', 'german_shorthaired_pointer.suitability.pdsa_exercise']],
                // S187 Size of home "Large House".
                ['tag' => 'large_home', 'source_ids' => ['S187'], 'refs' => ['german_shorthaired_pointer.suitability.rkc_size_of_home']],
            ],
            'consider' => [
                // S187 "More than 2 hours per day"; S188 "over 2 hours ideally per day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S187', 'S188'], 'refs' => ['german_shorthaired_pointer.exercise.adult', 'german_shorthaired_pointer.suitability.pdsa_exercise']],
                // S189 "Short length with moderate shedding".
                ['tag' => 'sheds', 'source_ids' => ['S189'], 'refs' => ['german_shorthaired_pointer.suitability.dogs_trust_shedding']],
            ],
        ],

        // M5-R10-22: yorkshire_terrier.* (S190–S196), runbook rules (data.json
        // proposed_game_parameters.yorkshire_terrier.suitability_tags). No welfare chip (RKC
        // Breed Watch Category 1, S192). Not `children` / `small_children` (PDSA S194: better
        // suited to families with older children — fragile bones → new `fragile_older_children`),
        // not `family_pet` (no breed statement).
        'yorkshire_terrier' => [
            'suits' => [
                // S192 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S192'], 'refs' => ['yorkshire_terrier.suitability.rkc_size_of_home', 'yorkshire_terrier.suitability.rkc_size_of_garden']],
                // S192 "Sheds: No".
                ['tag' => 'low_shedding', 'source_ids' => ['S192'], 'refs' => ['yorkshire_terrier.suitability.rkc_shedding']],
            ],
            'consider' => [
                // S194 "They are better suited to families with older children as they have very fragile bones".
                ['tag' => 'fragile_older_children', 'source_ids' => ['S194'], 'refs' => ['yorkshire_terrier.suitability.pdsa_children', 'yorkshire_terrier.behaviour.family']],
                // S192 "Grooming: Every day"; S194 daily grooming.
                ['tag' => 'frequent_grooming', 'source_ids' => ['S192', 'S194'], 'refs' => ['yorkshire_terrier.suitability.rkc_grooming', 'yorkshire_terrier.suitability.pdsa_grooming']],
            ],
        ],

        // M5-R10-23: cane_corso.* (S197–S201), runbook rules (data.json
        // proposed_game_parameters.cane_corso.suitability_tags). No RKC breed page (breed not
        // RKC-recognised) → PDSA (S199) only; no `large_home` / `apartment` (RKC criteria). No
        // welfare chip (no Breed Watch / vet-association conformation point; not flat-faced).
        // Not `children` / `small_children` / `family_pet` (PDSA S199: suits adult-only homes or
        // families with older children → new `not_for_small_children`).
        'cane_corso' => [
            'suits' => [
                // S199 "Your Cane Corso will need around two hours’ exercise every day."
                ['tag' => 'active_family', 'source_ids' => ['S199'], 'refs' => ['cane_corso.exercise.adult', 'cane_corso.suitability.pdsa_exercise']],
            ],
            'consider' => [
                // S199 "around two hours’ exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S199'], 'refs' => ['cane_corso.exercise.adult', 'cane_corso.suitability.pdsa_exercise']],
                // S199 "We really wouldn’t recommend having a Cane Corso if you have small children".
                ['tag' => 'not_for_small_children', 'source_ids' => ['S199'], 'refs' => ['cane_corso.behaviour.family', 'cane_corso.suitability.pdsa_children']],
                // S199 "due to their size they’re better suited to experienced dog owners."
                ['tag' => 'experienced_owner', 'source_ids' => ['S199'], 'refs' => ['cane_corso.behaviour.experienced_owner', 'cane_corso.suitability.pdsa_experienced_owner']],
                // S199 "Cane Corsos love to be kept occupied and hate being bored."
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S199'], 'refs' => ['cane_corso.exercise.energy', 'cane_corso.suitability.pdsa_mental_stimulation']],
                // S199 "Average lifespan: Under 10 years"; S201 median 9.29 years.
                ['tag' => 'shorter_lifespan', 'source_ids' => ['S199', 'S201'], 'refs' => ['cane_corso.lifespan.pdsa', 'cane_corso.lifespan.median_international']],
                // S199 "they do shed and will do so more in spring and autumn".
                ['tag' => 'sheds', 'source_ids' => ['S199'], 'refs' => ['cane_corso.suitability.pdsa_shedding']],
                // S199 "Cane Corsos are known to drool."
                ['tag' => 'drools', 'source_ids' => ['S199'], 'refs' => ['cane_corso.behaviour.drooling', 'cane_corso.suitability.pdsa_drooling']],
            ],
        ],

        // M5-R10-24: pembroke_welsh_corgi.* (S202–S206), runbook rules (data.json
        // proposed_game_parameters.pembroke_welsh_corgi.suitability_tags). RKC (S204) + PDSA (S206).
        // No `suits` tag has a source: RKC "Small house" (not "Flat/ Apartment" or "Large house"),
        // 60 min (no active_family, as the Dachshund), no family / children rating. Not a
        // welfare-concern breed (RKC Breed Watch Category 1) → no welfare chip; PDSA's list of
        // potential problems (IVDD, hip dysplasia) stays in research (open for David).
        'pembroke_welsh_corgi' => [
            'suits' => [],
            'consider' => [
                // S206 "They may nip at the heels of running children" (herding).
                ['tag' => 'may_herd_children', 'source_ids' => ['S206'], 'refs' => ['pembroke_welsh_corgi.behaviour.herding_children', 'pembroke_welsh_corgi.suitability.pdsa_children']],
                // S204 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S204'], 'refs' => ['pembroke_welsh_corgi.suitability.rkc_shedding']],
                // S204 "Grooming: More than once a week"; S206 "grooming two-three times per week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S204', 'S206'], 'refs' => ['pembroke_welsh_corgi.suitability.rkc_grooming', 'pembroke_welsh_corgi.suitability.pdsa_grooming']],
            ],
        ],

        // M5-R10-25: dobermann.* (S207–S212), runbook rules (data.json
        // proposed_game_parameters.dobermann.suitability_tags). RKC (S209) + PDSA (S211).
        // `family_pet` from the RKC sentence, but never `children` / `small_children` (PDSA S211
        // does not recommend the breed with young children → `not_for_small_children`), not
        // `first_time_owner` (→ `experienced_owner`), not `other_pets` (high prey drive, S211).
        // Not a welfare-concern breed (RKC Breed Watch Category 1) → no welfare chip.
        'dobermann' => [
            'suits' => [
                // S209 "Exercise: More than 2 hours per day"; S211 "a minimum of two hours of exercise every day".
                ['tag' => 'active_family', 'source_ids' => ['S209', 'S211'], 'refs' => ['dobermann.exercise.adult', 'dobermann.suitability.pdsa_exercise']],
                // S209 "his loyal and obedient nature equips him to be an excellent family dog".
                ['tag' => 'family_pet', 'source_ids' => ['S209'], 'refs' => ['dobermann.suitability.rkc_family', 'dobermann.behaviour.family']],
                // S209 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S209'], 'refs' => ['dobermann.suitability.rkc_size_of_home', 'dobermann.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S209 "More than 2 hours per day"; S211 "a minimum of two hours of exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S209', 'S211'], 'refs' => ['dobermann.exercise.adult', 'dobermann.suitability.pdsa_exercise']],
                // S211 "due to their large size and strong build we wouldn’t recommend a Dobermann if you have young children."
                ['tag' => 'not_for_small_children', 'source_ids' => ['S211'], 'refs' => ['dobermann.suitability.pdsa_children']],
                // S211 "they probably aren’t the best breed for families with young children or first-time owners."
                ['tag' => 'experienced_owner', 'source_ids' => ['S211'], 'refs' => ['dobermann.behaviour.experienced_owner', 'dobermann.suitability.pdsa_experienced_owner']],
                // S211 "very energetic and will need plenty of mental and physical exercise".
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S211'], 'refs' => ['dobermann.exercise.energy', 'dobermann.suitability.pdsa_mental_stimulation']],
                // S209 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S209'], 'refs' => ['dobermann.suitability.rkc_shedding']],
                // S211 "Dobermanns are known to have quite a high prey drive and will love any opportunity to chase smaller pets" (David 2026-10-12).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S211'], 'refs' => ['dobermann.behaviour.other_pets', 'dobermann.suitability.pdsa_other_pets']],
            ],
        ],

        // M5-R10-26: boxer.* (S213–S220), runbook rules (data.json
        // proposed_game_parameters.boxer.suitability_tags). RKC (S215) + PDSA (S219) + welfare.
        // `family_pet` from the RKC / PDSA sentences, but never `children` / `small_children`
        // (PDSA S219 does not recommend the breed with younger children → `not_for_small_children`),
        // not `first_time_owner` (→ `experienced_owner`), not `other_pets` (very high prey drive),
        // not `often_alone` (→ new `needs_company`). Welfare breed (moderately brachycephalic,
        // VetCompass S220; RKC Breed Watch Category 2 S216) → the new `short_muzzle_breathing`
        // (not `brachycephalic_breathing`: that chip says "flat face … heat", and no Boxer source names heat).
        'boxer' => [
            'suits' => [
                // S215 "Exercise: More than 2 hours per day"; S219 "a minimum of two hours exercise every day".
                ['tag' => 'active_family', 'source_ids' => ['S215', 'S219'], 'refs' => ['boxer.exercise.adult', 'boxer.suitability.pdsa_exercise']],
                // S215 "much loved as a family companion"; S219 "popular family pets".
                ['tag' => 'family_pet', 'source_ids' => ['S215', 'S219'], 'refs' => ['boxer.suitability.rkc_family', 'boxer.suitability.pdsa_family']],
                // S215 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S215'], 'refs' => ['boxer.suitability.rkc_size_of_home', 'boxer.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S215 "More than 2 hours per day"; S219 "a minimum of two hours exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S215', 'S219'], 'refs' => ['boxer.exercise.adult', 'boxer.suitability.pdsa_exercise']],
                // Welfare rule (new key, not the flat-face chip): S219 BOAS "in some Boxers with shorter faces";
                // S220 "a moderately brachycephalic skull shape"; S216 "Excessively short muzzle".
                ['tag' => 'short_muzzle_breathing', 'source_ids' => ['S219', 'S220', 'S216'], 'refs' => ['boxer.health.brachycephaly_boas', 'boxer.health.skull_shape', 'boxer.health.breed_watch']],
                // S219 "we wouldn't recommend a Boxer if you have younger children as they could accidentally knock them over."
                ['tag' => 'not_for_small_children', 'source_ids' => ['S219'], 'refs' => ['boxer.suitability.pdsa_children']],
                // S219 "we wouldn't recommend a Boxer if you are a first time owner."
                ['tag' => 'experienced_owner', 'source_ids' => ['S219'], 'refs' => ['boxer.behaviour.experienced_owner', 'boxer.suitability.pdsa_experienced_owner']],
                // S219 "Boxers love human company so can develop separation anxiety if left alone."
                ['tag' => 'needs_company', 'source_ids' => ['S219'], 'refs' => ['boxer.behaviour.time_alone', 'boxer.suitability.pdsa_time_alone']],
                // S219 "If they are alone or bored, they like to chew".
                ['tag' => 'chews_when_bored', 'source_ids' => ['S219'], 'refs' => ['boxer.behaviour.chewing', 'boxer.suitability.pdsa_chewing']],
                // S215 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S215'], 'refs' => ['boxer.suitability.rkc_shedding']],
                // S219 "Boxers do have a very high prey drive, so we wouldn't recommend keeping them with smaller pets."
                ['tag' => 'chases_small_pets', 'source_ids' => ['S219'], 'refs' => ['boxer.behaviour.other_pets', 'boxer.suitability.pdsa_other_pets']],
            ],
        ],

        // M5-R10-27: miniature_schnauzer.* (S221–S228), runbook rules (data.json
        // proposed_game_parameters.miniature_schnauzer.suitability_tags). RKC (S223) + PDSA (S226).
        // `children` from PDSA's explicit statement (condition: introduced at an early age), never
        // `small_children` (no rating); not `family_pet` (RKC says companion dogs). Not a welfare
        // breed (RKC Breed Watch Category 1, S223). No new vocabulary key.
        'miniature_schnauzer' => [
            'suits' => [
                // S223 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S223'], 'refs' => ['miniature_schnauzer.suitability.rkc_size_of_home', 'miniature_schnauzer.suitability.rkc_size_of_garden']],
                // S226 "They can get on well with children if they're introduced at an early age."
                ['tag' => 'children', 'source_ids' => ['S226'], 'refs' => ['miniature_schnauzer.suitability.pdsa_children', 'miniature_schnauzer.behaviour.family']],
                // S223 "Sheds: No" (never "hypoallergenic").
                ['tag' => 'low_shedding', 'source_ids' => ['S223'], 'refs' => ['miniature_schnauzer.suitability.rkc_shedding']],
            ],
            'consider' => [
                // S223 "Grooming: More than once a week"; S226 "Grooming is needed 2-3 times a week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S223', 'S226'], 'refs' => ['miniature_schnauzer.suitability.rkc_grooming', 'miniature_schnauzer.suitability.pdsa_grooming']],
            ],
        ],

        // M5-R10-28: shih_tzu.* (S229–S236), runbook rules (data.json
        // proposed_game_parameters.shih_tzu.suitability_tags). RKC (S231) + PDSA (S233) + BVA (S236).
        // `family_pet` from PDSA (condition: right socialisation and training), never `children` /
        // `small_children` (PDSA only advises supervision). Welfare rule: brachycephalic breed (BVA
        // S236, PDSA BOAS S233) → `short_muzzle_breathing` (no Shih Tzu source names heat — Boxer
        // precedent). No new vocabulary key.
        'shih_tzu' => [
            'suits' => [
                // S231 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S231'], 'refs' => ['shih_tzu.suitability.rkc_size_of_home', 'shih_tzu.suitability.rkc_size_of_garden']],
                // S233 "… make great family pets in the right household."
                ['tag' => 'family_pet', 'source_ids' => ['S233'], 'refs' => ['shih_tzu.suitability.pdsa_family', 'shih_tzu.behaviour.family']],
                // S233 "Shih Tzus are an ideal pet for first-time owners."
                ['tag' => 'first_time_owner', 'source_ids' => ['S233'], 'refs' => ['shih_tzu.suitability.pdsa_first_time_owner']],
                // Not `low_shedding` / `sheds`: RKC "Sheds: No" (S231) and PDSA "Expect them to shed more
                // in autumn and spring" (S233) disagree → neither tag (runbook rule).
            ],
            'consider' => [
                // S231 "Grooming: Every day"; S233 "They’ll need a daily brush to keep their coat knot-free".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S231', 'S233'], 'refs' => ['shih_tzu.suitability.rkc_grooming', 'shih_tzu.suitability.pdsa_grooming']],
                // S233 BOAS "caused by their shorter faces"; S236 BVA brachycephalic breeds (Figure 1).
                ['tag' => 'short_muzzle_breathing', 'source_ids' => ['S233', 'S236'], 'refs' => ['shih_tzu.health.brachycephaly_boas', 'shih_tzu.health.skull_shape']],
            ],
        ],

        // M5-R10-29: great_dane.* (S239–S244), runbook rules (data.json
        // proposed_game_parameters.great_dane.suitability_tags). RKC (S241) + PDSA (S243) + Breed Watch (S244).
        // `family_pet` from the RKC / PDSA sentences, but never `children` / `small_children` (PDSA S243
        // does not recommend the breed with younger children → `not_for_small_children`); neither
        // `first_time_owner` nor `experienced_owner` (PDSA only suggests a trainer's help); not
        // `other_pets` (fairly strong prey drive). Welfare rule: RKC Breed Watch Category 2 (S244) →
        // `hips_hind_legs` (as the German Shepherd Dog and the Rottweiler) and the new `eyelid_problems`.
        'great_dane' => [
            'suits' => [
                // S241 "Exercise: More than 2 hours per day"; S243 "a minimum of two hours of exercise every day".
                ['tag' => 'active_family', 'source_ids' => ['S241', 'S243'], 'refs' => ['great_dane.exercise.adult', 'great_dane.suitability.pdsa_exercise']],
                // S241 "Despite the size, the Great Dane makes a wonderful family dog"; S243 "they can make great family pets".
                ['tag' => 'family_pet', 'source_ids' => ['S241', 'S243'], 'refs' => ['great_dane.suitability.rkc_family', 'great_dane.suitability.pdsa_family']],
                // S241 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S241'], 'refs' => ['great_dane.suitability.rkc_size_of_home', 'great_dane.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S241 "More than 2 hours per day"; S243 "a minimum of two hours of exercise every day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S241', 'S243'], 'refs' => ['great_dane.exercise.adult', 'great_dane.suitability.pdsa_exercise']],
                // S243 "we wouldn’t recommend a Great Dane if you have younger children".
                ['tag' => 'not_for_small_children', 'source_ids' => ['S243'], 'refs' => ['great_dane.suitability.pdsa_children']],
                // S241 "Lifespan: Under 10 years"; S243 "Average lifespan | Under 10 years".
                ['tag' => 'shorter_lifespan', 'source_ids' => ['S241', 'S243'], 'refs' => ['great_dane.health.shorter_lifespan', 'great_dane.lifespan.rkc']],
                // Welfare rule: S244 "Incorrect hind conformation and/or poor rear movement"; S243 hip dysplasia.
                ['tag' => 'hips_hind_legs', 'source_ids' => ['S244', 'S243'], 'refs' => ['great_dane.health.hind_conformation', 'great_dane.health.hip_elbow_dysplasia']],
                // Welfare rule (new key): S244 "Eyelids not in normal contact with the eyeball …"; S243 entropion / ectropion.
                ['tag' => 'eyelid_problems', 'source_ids' => ['S244', 'S243'], 'refs' => ['great_dane.health.breed_watch', 'great_dane.health.eyelids']],
                // S243 "You might find that they chew your furniture and possessions when they're bored or left alone".
                ['tag' => 'chews_when_bored', 'source_ids' => ['S243'], 'refs' => ['great_dane.behaviour.chewing', 'great_dane.suitability.pdsa_chewing']],
                // S241 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S241'], 'refs' => ['great_dane.suitability.rkc_shedding']],
                // S243 "Great Danes are, however, known for drooling!"
                ['tag' => 'drools', 'source_ids' => ['S243'], 'refs' => ['great_dane.behaviour.drooling', 'great_dane.suitability.pdsa_drooling']],
                // S243 "Great Danes do have a fairly strong prey drive, though, so all introductions need to be done very carefully."
                ['tag' => 'chases_small_pets', 'source_ids' => ['S243'], 'refs' => ['great_dane.behaviour.other_pets', 'great_dane.suitability.pdsa_other_pets']],
            ],
        ],

        // M5-R10-30: pomeranian.* (S245–S252), runbook rules (data.json
        // proposed_game_parameters.pomeranian.suitability_tags). RKC (S247) + PDSA (S250) + AKC (S251) + FCI (S246).
        // `family_pet` from the FCI sentence, but never `children` / `small_children` (AKC S251: a better
        // fit for families with older children → the new `older_children`); `other_pets` with the
        // socialisation caveat (Standard Poodle precedent). No welfare chip: RKC Breed Watch Category 2
        // (S248), but its points of concern were not returned (open for David); not flat-faced.
        'pomeranian' => [
            'suits' => [
                // S247 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S247'], 'refs' => ['pomeranian.suitability.rkc_size_of_home', 'pomeranian.suitability.rkc_size_of_garden']],
                // S246 "Its companionable and cheerful character makes it the ideal companion and family dog"
                // (the FCI standard's sentence for the whole German Spitz breed, of which the Pomeranian is a variety).
                ['tag' => 'family_pet', 'source_ids' => ['S246'], 'refs' => ['pomeranian.behaviour.family', 'pomeranian.behaviour.temperament']],
                // S251 "Poms can get along well with other animals, especially if socialized early."
                ['tag' => 'other_pets', 'source_ids' => ['S251'], 'refs' => ['pomeranian.behaviour.other_pets', 'pomeranian.suitability.akc_other_pets']],
            ],
            'consider' => [
                // S251 "Pomeranians tend to be a better fit for families with older children."
                ['tag' => 'older_children', 'source_ids' => ['S251'], 'refs' => ['pomeranian.behaviour.family', 'pomeranian.suitability.akc_children']],
                // S247 "Grooming: More than once a week"; S250 "needs brushing two-three times per week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S247', 'S250'], 'refs' => ['pomeranian.suitability.rkc_grooming', 'pomeranian.suitability.pdsa_grooming']],
                // S247 "Sheds: Yes"; S250 "more often when shedding".
                ['tag' => 'sheds', 'source_ids' => ['S247', 'S250'], 'refs' => ['pomeranian.suitability.rkc_shedding', 'pomeranian.suitability.pdsa_grooming']],
            ],
        ],

        // M5-R10-31: boston_terrier.* (S253–S263), runbook rules (data.json
        // proposed_game_parameters.boston_terrier.suitability_tags). RKC (S255) + PDSA (S259) + AKC (S260).
        // `children` with the caveats (PDSA: the right socialisation as a puppy; AKC: children who know
        // how to be gentle) — never `small_children`. Welfare breed (flat-faced, PDSA S259 / RKC S261,
        // heat warning S259) → brachycephalic_breathing, as the French Bulldog and the Bulldog.
        // Sheds: conflicting sources (RKC yes, AKC minimally) → neither sheds nor low_shedding.
        'boston_terrier' => [
            'suits' => [
                // S255 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S255'], 'refs' => ['boston_terrier.suitability.rkc_size_of_home', 'boston_terrier.suitability.rkc_size_of_garden']],
                // S259 "They enjoy playing games and will join in with plenty of enthusiasm, making them perfect family pets."
                ['tag' => 'family_pet', 'source_ids' => ['S259'], 'refs' => ['boston_terrier.suitability.pdsa_family', 'boston_terrier.behaviour.family']],
                // S259 "and are especially good with children."; S260 "They do well with children who know how to be gentle with animals."
                ['tag' => 'children', 'source_ids' => ['S259', 'S260'], 'refs' => ['boston_terrier.suitability.pdsa_children', 'boston_terrier.behaviour.family', 'boston_terrier.suitability.akc_children']],
            ],
            'consider' => [
                // S259 "Boston Terriers are a brachycephalic (flat-faced) breed."; S255 BOAS text; S261 RKC / Cambridge
                // BOAS research; S93 "Stop and think before buying a flat-faced dog."; S259 heat warning.
                ['tag' => 'brachycephalic_breathing', 'source_ids' => ['S259', 'S255', 'S261', 'S93'], 'refs' => ['boston_terrier.health.brachycephaly_boas', 'boston_terrier.exercise.heat_and_walk_timing']],
                // S260 "This breed doesn’t fare well when left alone for extended periods of time."
                ['tag' => 'needs_company', 'source_ids' => ['S260'], 'refs' => ['boston_terrier.behaviour.time_alone', 'boston_terrier.suitability.akc_time_alone']],
            ],
        ],

        // M5-R10-32: miniature_american_shepherd.* (S264–S271), runbook rules (data.json
        // proposed_game_parameters.miniature_american_shepherd.suitability_tags). AKC (S267) + PetMD (S269);
        // the RKC breed page could not be fetched → no apartment / large_home. `children` and `other_pets`
        // with the caveats (may herd children, supervise at first) — never `small_children`. Not
        // `active_family` / `long_daily_exercise` (at least 1 h a day). Not `frequent_grooming`: PetMD "a couple
        // times a week" vs AKC "Weekly brushing is sufficient during most of the year" → conflicting sources, no chip
        // (the game still brushes 2× a week). Not a welfare breed → no welfare chip.
        'miniature_american_shepherd' => [
            'suits' => [
                // S267 "Mini Americans can make for great family dogs, …"; S269 "… make great family dogs …".
                ['tag' => 'family_pet', 'source_ids' => ['S267', 'S269'], 'refs' => ['miniature_american_shepherd.suitability.akc_family', 'miniature_american_shepherd.suitability.petmd_family']],
                // S267 "They tend to be good with children, but may try to herd them."; S269 "They are good with children and other pets …".
                ['tag' => 'children', 'source_ids' => ['S267', 'S269'], 'refs' => ['miniature_american_shepherd.suitability.akc_children', 'miniature_american_shepherd.suitability.petmd_children_pets']],
                // S267 "They’re extremely affectionate and friendly with their families and other pets."; S269 the same sentence as above.
                ['tag' => 'other_pets', 'source_ids' => ['S267', 'S269'], 'refs' => ['miniature_american_shepherd.suitability.akc_other_pets', 'miniature_american_shepherd.suitability.petmd_children_pets']],
            ],
            'consider' => [
                // S269 "Miniature American Shepherds need to be given jobs to stay active and prevent boredom."
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S269'], 'refs' => ['miniature_american_shepherd.behaviour.mental_stimulation']],
                // S267 "… but may try to herd them."
                ['tag' => 'may_herd_children', 'source_ids' => ['S267'], 'refs' => ['miniature_american_shepherd.behaviour.herding_children', 'miniature_american_shepherd.suitability.akc_children']],
                // S269 "Their devotion to family can lead to separation anxiety when they're left alone."
                ['tag' => 'needs_company', 'source_ids' => ['S269'], 'refs' => ['miniature_american_shepherd.behaviour.time_alone']],
                // S267 "The breed sheds a fair amount, …"; S269 "This breed sheds year-round, …".
                ['tag' => 'sheds', 'source_ids' => ['S267', 'S269'], 'refs' => ['miniature_american_shepherd.suitability.akc_shedding', 'miniature_american_shepherd.suitability.petmd_shedding']],
            ],
        ],

        // M5-R10-33: chihuahua.* (S272–S280), runbook rules (data.json proposed_game_parameters.chihuahua
        // .suitability_tags). RKC Smooth Coat (S275) + PDSA (S277); AKC (S278) advises against homes with
        // children → never family_pet / children / small_children, the PDSA's older_children instead.
        // other_pets with the PDSA caveat (pets it grew up with). Not flat-faced; Breed Watch Category 2
        // without returned points → no welfare chip (open for David).
        'chihuahua' => [
            'suits' => [
                // S275 "Size of home: Flat/ Apartment" / "Size of garden: Small/ medium garden".
                ['tag' => 'apartment', 'source_ids' => ['S275'], 'refs' => ['chihuahua.suitability.rkc_size_of_home', 'chihuahua.suitability.rkc_size_of_garden']],
                // S277 "Generally speaking, your Chihuahua will live happily with another pet they have grown up with."
                ['tag' => 'other_pets', 'source_ids' => ['S277'], 'refs' => ['chihuahua.behaviour.other_pets']],
            ],
            'consider' => [
                // S277 "Because of this, they're better suited to families with slightly older children".
                ['tag' => 'older_children', 'source_ids' => ['S277'], 'refs' => ['chihuahua.behaviour.family', 'chihuahua.suitability.pdsa_children']],
                // S277 "As companion dogs Chihuahuas love company and need someone around the home all the time."
                ['tag' => 'needs_company', 'source_ids' => ['S277'], 'refs' => ['chihuahua.behaviour.time_alone', 'chihuahua.suitability.pdsa_time_alone']],
                // S275 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S275'], 'refs' => ['chihuahua.suitability.rkc_shedding']],
            ],
        ],
        // M5-R10-34: english_springer_spaniel.* (S281–S288), runbook rules (data.json
        // proposed_game_parameters.english_springer_spaniel.suitability_tags). RKC (S284) + PDSA (S285) + AKC (S286).
        // PDSA "get along well with children and other household pets" → children + other_pets (no rating for
        // small children). RKC "Small house" → neither apartment nor large_home. Breed Watch Category 1 with
        // no points of concern → no welfare chip.
        'english_springer_spaniel' => [
            'suits' => [
                // S284 "Exercise: More than 2 hours per day"; S285 "over 2 hours of exercise per day"; S286 "A more active household would be the best fit".
                ['tag' => 'active_family', 'source_ids' => ['S284', 'S285', 'S286'], 'refs' => ['english_springer_spaniel.exercise.adult', 'english_springer_spaniel.suitability.pdsa_exercise', 'english_springer_spaniel.suitability.akc_active_household']],
                // S285 "They make good family dogs; they’re well behaved, love to be kept involved in family activities".
                ['tag' => 'family_pet', 'source_ids' => ['S285'], 'refs' => ['english_springer_spaniel.suitability.pdsa_family', 'english_springer_spaniel.behaviour.family']],
                // S285 "get along well with children and other household pets."
                ['tag' => 'children', 'source_ids' => ['S285'], 'refs' => ['english_springer_spaniel.suitability.pdsa_children']],
                ['tag' => 'other_pets', 'source_ids' => ['S285'], 'refs' => ['english_springer_spaniel.behaviour.other_pets', 'english_springer_spaniel.suitability.pdsa_children']],
            ],
            'consider' => [
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S284', 'S285'], 'refs' => ['english_springer_spaniel.exercise.adult', 'english_springer_spaniel.suitability.pdsa_exercise']],
                // S286 "Springers should not be left alone for long periods of time, …"
                ['tag' => 'needs_company', 'source_ids' => ['S286'], 'refs' => ['english_springer_spaniel.behaviour.time_alone', 'english_springer_spaniel.suitability.akc_time_alone']],
                // S284 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S284'], 'refs' => ['english_springer_spaniel.suitability.rkc_shedding']],
                // S284 "Grooming: More than once a week"; S285 "once or twice a week, plus clipping every 3 months".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S284', 'S285'], 'refs' => ['english_springer_spaniel.suitability.rkc_grooming', 'english_springer_spaniel.suitability.pdsa_grooming']],
            ],
        ],
        // M5-R10-35: shetland_sheepdog.* (S289–S295), runbook rules (data.json
        // proposed_game_parameters.shetland_sheepdog.suitability_tags). RKC (S291) + PDSA (S293) + AKC (S294).
        // PDSA "get along happily with children" → children (no rating for small children); AKC "would fare
        // best in a home with older children due to their herding instincts" → may_herd_children. RKC
        // "Small house" → neither apartment nor large_home. Breed Watch Category 2 without a named point
        // (S292) → no welfare chip (open for David). AKC "will chase just about anything" is not a named
        // high prey drive → no chases_small_pets.
        'shetland_sheepdog' => [
            'suits' => [
                // S293 "Shelties often fit well into family life and get along happily with children."
                ['tag' => 'family_pet', 'source_ids' => ['S293'], 'refs' => ['shetland_sheepdog.suitability.pdsa_family', 'shetland_sheepdog.behaviour.family']],
                ['tag' => 'children', 'source_ids' => ['S293'], 'refs' => ['shetland_sheepdog.suitability.pdsa_family']],
            ],
            'consider' => [
                // S294 "Shelties are quite athletic, and require regular mental and physical exercise to keep them from acting out."
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S294'], 'refs' => ['shetland_sheepdog.behaviour.mental_stimulation', 'shetland_sheepdog.suitability.akc_mental_exercise']],
                // S294 "They can do well with kids, but would fare best in a home with older children due to their herding instincts."
                ['tag' => 'may_herd_children', 'source_ids' => ['S294'], 'refs' => ['shetland_sheepdog.behaviour.herding_children', 'shetland_sheepdog.suitability.akc_children']],
                // S291 "Grooming: Every day"; S293 "They need daily grooming to keep their thick coat in tip-top condition."
                ['tag' => 'frequent_grooming', 'source_ids' => ['S291', 'S293'], 'refs' => ['shetland_sheepdog.suitability.rkc_grooming', 'shetland_sheepdog.suitability.pdsa_grooming']],
                // S291 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S291'], 'refs' => ['shetland_sheepdog.suitability.rkc_shedding']],
            ],
        ],
        // M5-R10-36: brittany.* (S296–S302), runbook rules (data.json
        // proposed_game_parameters.brittany.suitability_tags). RKC (S298) + AKC (S301) + PetMD (S302).
        // RKC "More than 2 hours per day" → active_family + long_daily_exercise. RKC "Small house" →
        // neither apartment nor large_home. PetMD "hardwired with a high prey drive" → chases_small_pets
        // (its "other pets" line conflicts → no other_pets). RKC "More than once a week" vs AKC / PetMD
        // weekly → no frequent_grooming. Breed Watch Category 1 → no welfare chip.
        'brittany' => [
            'suits' => [
                // S298 "Exercise: More than 2 hours per day"; S301 "they require plenty of exercise."
                ['tag' => 'active_family', 'source_ids' => ['S298', 'S301'], 'refs' => ['brittany.exercise.adult', 'brittany.exercise.energy']],
                // S302 "the perfect, medium-size sporting dog for an active family with children and other pets"
                ['tag' => 'family_pet', 'source_ids' => ['S302'], 'refs' => ['brittany.suitability.petmd_family', 'brittany.behaviour.family']],
                // S302 "sweet and well-behaved with children of all ages"; S301 "tend to be patient with children."
                ['tag' => 'children', 'source_ids' => ['S302', 'S301'], 'refs' => ['brittany.suitability.petmd_children', 'brittany.suitability.akc_children']],
            ],
            'consider' => [
                // S298 "Exercise: More than 2 hours per day".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S298'], 'refs' => ['brittany.exercise.adult', 'brittany.suitability.rkc_exercise']],
                // S301 "It’s essential that Brittanys get plenty of exercise and mental stimulation to keep them from acting out."
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S301'], 'refs' => ['brittany.behaviour.mental_stimulation', 'brittany.suitability.akc_mental_exercise']],
                // S302 "don’t do well when left alone for long periods"
                ['tag' => 'needs_company', 'source_ids' => ['S302'], 'refs' => ['brittany.behaviour.time_alone', 'brittany.suitability.petmd_time_alone']],
                // S298 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S298'], 'refs' => ['brittany.suitability.rkc_shedding']],
                // S302 "The Brittany was bred to hunt birds, so they are hardwired with a high prey drive." (David 2026-10-12: last).
                ['tag' => 'chases_small_pets', 'source_ids' => ['S302'], 'refs' => ['brittany.behaviour.other_pets']],
            ],
        ],
        // M5-R10-37: american_cocker_spaniel.* (S303–S311), runbook rules (data.json
        // proposed_game_parameters.american_cocker_spaniel.suitability_tags). RKC (S305) + AKC (S308) +
        // PetMD (S309) + VCA (S310). RKC "More than 2 hours per day" conflicts with AKC / PetMD "don't
        // require a lot / high levels of exercise" → no active_family / long_daily_exercise. RKC "Small
        // house" → neither apartment nor large_home. Breed Watch Category 2 without a named point → no
        // welfare chip.
        'american_cocker_spaniel' => [
            'suits' => [
                // S309 "At home, these sweet-tempered pups love being close to their people and make great family dogs."
                ['tag' => 'family_pet', 'source_ids' => ['S309'], 'refs' => ['american_cocker_spaniel.suitability.petmd_family', 'american_cocker_spaniel.behaviour.family']],
                // S310 "Usually very good with children."; S308 "They generally enjoy spending time with children …"
                ['tag' => 'children', 'source_ids' => ['S310', 'S308'], 'refs' => ['american_cocker_spaniel.suitability.vca_children', 'american_cocker_spaniel.suitability.akc_children']],
                // S310 "Gets along well with other pets and dogs."; S309 "… both with people and with other animals."
                ['tag' => 'other_pets', 'source_ids' => ['S310', 'S309'], 'refs' => ['american_cocker_spaniel.suitability.vca_other_pets', 'american_cocker_spaniel.suitability.petmd_other_animals']],
            ],
            'consider' => [
                // S305 "Grooming: Every day"; S310 "brushing and combing two to three times a week".
                ['tag' => 'frequent_grooming', 'source_ids' => ['S305', 'S310'], 'refs' => ['american_cocker_spaniel.suitability.rkc_grooming', 'american_cocker_spaniel.suitability.vca_grooming']],
                // S310 "Cockers have a tendency to become overweight."; S309 "often easily motivated by rewards of food".
                ['tag' => 'food_motivated_weight', 'source_ids' => ['S310', 'S309'], 'refs' => ['american_cocker_spaniel.behaviour.obesity_tendency', 'american_cocker_spaniel.behaviour.food_motivation']],
                // S305 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S305'], 'refs' => ['american_cocker_spaniel.suitability.rkc_shedding']],
            ],
        ],
        // M5-R10-38: belgian_malinois.* (S312–S318), runbook rules (data.json
        // proposed_game_parameters.belgian_malinois.suitability_tags). RKC (S314) + AKC (S316) + PetMD
        // (S317). No family_pet / children / other_pets: AKC "older, responsible kids", PetMD herds
        // toddlers and has a high prey drive. RKC "More than once a week" vs AKC / PetMD "occasional"
        // brushing → no frequent_grooming. Breed Watch Category 1 → no welfare chip.
        'belgian_malinois' => [
            'suits' => [
                // S314 "Exercise: More than 2 hours per day"; S316 "Mals require significant exercise on a daily basis".
                ['tag' => 'active_family', 'source_ids' => ['S314', 'S316'], 'refs' => ['belgian_malinois.exercise.adult', 'belgian_malinois.suitability.akc_exercise']],
                // S314 "Size of home: Large house" / "Size of garden: Large garden".
                ['tag' => 'large_home', 'source_ids' => ['S314'], 'refs' => ['belgian_malinois.suitability.rkc_size_of_home', 'belgian_malinois.suitability.rkc_size_of_garden']],
            ],
            'consider' => [
                // S314 "More than 2 hours per day"; S316 "significant exercise on a daily basis".
                ['tag' => 'long_daily_exercise', 'source_ids' => ['S314', 'S316'], 'refs' => ['belgian_malinois.exercise.adult', 'belgian_malinois.suitability.akc_exercise']],
                // S316 "don't make them a good choice for first-time dog owners"; S317 "need a dedicated and experienced pet parent".
                ['tag' => 'experienced_owner', 'source_ids' => ['S316', 'S317'], 'refs' => ['belgian_malinois.behaviour.experienced_owner', 'belgian_malinois.suitability.akc_experienced_owner', 'belgian_malinois.suitability.petmd_experienced_owner']],
                // S316 "Mals would fare best in families with older, responsible kids." + "supervision around children is a must"
                // (the older_children chip reads "small dog" — not this breed).
                ['tag' => 'not_for_small_children', 'source_ids' => ['S316'], 'refs' => ['belgian_malinois.suitability.akc_children', 'belgian_malinois.behaviour.family']],
                // S317 "They may also try to use their herding skills to round up toddlers and children."
                ['tag' => 'may_herd_children', 'source_ids' => ['S317'], 'refs' => ['belgian_malinois.suitability.petmd_herding_children']],
                // S316 "happiest with plenty of mental and physical activity".
                ['tag' => 'needs_mental_stimulation', 'source_ids' => ['S316'], 'refs' => ['belgian_malinois.exercise.energy', 'belgian_malinois.suitability.akc_mental_stimulation']],
                // S316 "they should not be left alone for extended periods of time."
                ['tag' => 'needs_company', 'source_ids' => ['S316'], 'refs' => ['belgian_malinois.behaviour.time_alone', 'belgian_malinois.suitability.akc_time_alone']],
                // S314 "Sheds: Yes".
                ['tag' => 'sheds', 'source_ids' => ['S314'], 'refs' => ['belgian_malinois.suitability.rkc_shedding']],
                // S317 "Belgian Malinois also have a high prey drive, making them extremely interested in moving objects."
                ['tag' => 'chases_small_pets', 'source_ids' => ['S317'], 'refs' => ['belgian_malinois.behaviour.other_pets', 'belgian_malinois.suitability.petmd_prey_drive']],
            ],
        ],

    ],
];
