<?php

/*
|--------------------------------------------------------------------------
| Breed appearance options for Pet DNA v2 (M4-08)
|--------------------------------------------------------------------------
|
| Per breed (keyed by App\Enums\BreedType value) the visual traits a pet may be
| born with. App\Services\Media\PetDnaService samples one option per trait with
| a deterministic seed, so every dog of a breed looks different (like in real
| life) but stays inside what the breed can look like.
|
| PARTLY VERIFIED (M5-R01, 2026-10-05 — docs/research/dog-data, README §4)
| David 2026-10-03: breed data must come from verifiable sources. `sources`
| names the source of each trait that a breed standard supports (FCI 297 = S1,
| AKC 2015 = S3, RKC = S6, size class S5); every trait NOT listed there, and
| every `weight`, is an unsourced draft (no standard lists colour names or
| frequencies, face markings or mixed-breed appearance). That is why each
| breed stays `verified => false`. Do not present these lists as breed facts
| to parents or children.
|
| Format of a trait: ordered list of options. An option is either a string or
| ['value' => string, 'weight' => int (default 1),
|  'only_with' => [otherTrait => [allowed values of that earlier trait]]].
| Traits are sampled in the order listed; `only_with` may only reference
| traits listed before it.
|
| `prompt_order` decides how the traits are woven into the natural-language
| description (App\Services\Media\PetAppearancePrompt).
|
*/

return [

    'mutt' => [
        'display_name' => 'mixed-breed dog',
        'verified' => false,
        'source' => 'Size: David 2026-10-05 — the game\'s mutt is a medium mixed breed of 15–30 kg adult weight (Salt size category IV, S8). Everything else: no standard exists and no shelter appearance statistics were found (dog-data README §2.10) — unsourced draft.',
        'sources' => [
            'size' => 'S8 (category IV 15–<30 kg) + David 2026-10-05',
        ],
        'traits' => [
            // Medium mixed breed only (David 2026-10-05: 15–30 kg adult weight).
            'size' => ['medium-sized'],
            'build' => ['slender', ['value' => 'athletic', 'weight' => 2], 'sturdy', 'stocky'],
            'coat_length' => [['value' => 'short smooth', 'weight' => 3], 'medium-length', 'wiry', 'long fluffy'],
            'coat_color' => [
                ['value' => 'tan', 'weight' => 2],
                ['value' => 'black', 'weight' => 2],
                'brown',
                'cream',
                'golden',
                'red',
                'grey',
                'black and tan',
                'brindle',
                'tricolour black, white and tan',
            ],
            'coat_pattern' => [
                ['value' => 'solid', 'weight' => 2],
                'with a white chest patch',
                'with white socks',
                'with irregular white patches',
                ['value' => 'with a few dark ticked spots', 'only_with' => ['coat_color' => ['tan', 'cream', 'golden', 'grey']]],
            ],
            'markings' => [
                ['value' => 'no special markings', 'weight' => 2],
                'a white blaze on the face',
                'a dark muzzle',
                'a white tail tip',
                'a darker mask around the eyes',
                'one ear darker than the other',
            ],
            'ear_carriage' => ['floppy', 'semi-erect', 'erect', 'one up and one folded'],
            'eye_color' => [['value' => 'brown', 'weight' => 4], 'amber', 'dark brown'],
            'tail' => ['long and curled', 'long and straight', 'medium with a slight curve'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'markings', 'ear_carriage', 'eye_color', 'tail'],
    ],

    'border_collie' => [
        'display_name' => 'Border Collie',
        // Colour names / weights, coat patterns and face markings are not in any standard (unsourced).
        'verified' => false,
        'source' => 'FCI-Standard N° 297 (S1, https://www.fci.be/Nomenclature/Standards/297g01-en.pdf), AKC standard 2015 (S3), Royal Kennel Club standard (S6), RKC breed page (S5) — for the traits listed in `sources`.',
        'sources' => [
            'size' => 'S5 "Size: Medium"',
            'build' => 'S1 "sufficient substance to give impression of endurance" (partly)',
            'coat_length' => 'S1 "Two varieties: Moderately long or Smooth … topcoat dense … undercoat soft and dense"; S3',
            'coat_color' => 'S1 "Variety of colours permissible. White should never predominate." (the names and weights are unsourced)',
            'ear_carriage' => 'S1 "Carried erect or semi-erect"; S3 "one or both carried erect and/or semi-erect"',
            'eye_color' => 'S1/S6 "Brown in colour except in merles where one or both or part of one or both may be blue."',
            'tail' => 'S1 "Moderately long … set on low … with an upward swirl towards the end"',
        ],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'athletic', 'weight' => 3], 'lean', 'well-muscled'],
            // FCI / AKC: two varieties, both with a double coat (S1, S3).
            'coat_length' => [['value' => 'moderately long double', 'weight' => 3], 'smooth double'],
            'coat_color' => [
                ['value' => 'black and white', 'weight' => 6],
                ['value' => 'black, white and tan tricolour', 'weight' => 2],
                'red and white',
                'blue and white',
                'blue merle and white',
                'red merle and white',
                'sable and white',
            ],
            'coat_pattern' => [
                ['value' => 'with a white collar', 'weight' => 2],
                'with a half white collar',
                'with a full white chest and white legs',
                'with mostly coloured body and small white points',
            ],
            'markings' => [
                ['value' => 'a symmetrical white blaze on the face', 'weight' => 2],
                'a narrow white stripe on the face',
                'an asymmetrical white blaze',
                'no white on the face',
                'freckles of colour on the white legs',
            ],
            'ear_carriage' => ['semi-erect', 'erect', 'one erect and one semi-erect'],
            // FCI / RKC (S1, S6): brown; in merles (blue AND red merle) one or both, or part
            // of one or both, may be blue. Amber is named by no standard (AKC: "any eye
            // colour acceptable") → dropped (dog-data README §4).
            'eye_color' => [
                ['value' => 'brown', 'weight' => 6],
                ['value' => 'one blue and one brown', 'only_with' => ['coat_color' => ['blue merle and white', 'red merle and white']]],
                ['value' => 'blue', 'only_with' => ['coat_color' => ['blue merle and white', 'red merle and white']]],
                ['value' => 'partly blue', 'only_with' => ['coat_color' => ['blue merle and white', 'red merle and white']]],
            ],
            'tail' => ['moderately long, low-set with an upward swirl at the tip'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'markings', 'ear_carriage', 'eye_color', 'tail'],
    ],
];
