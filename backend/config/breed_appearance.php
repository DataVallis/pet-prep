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
| Cats (M5-R06-07, CAT_SPEC §8, docs/research/cat-data): two optional keys
| that dog breeds do not use — `features` (fixed breed features every pet of
| the breed has, appended to the description) and `stage_notes` (an extra
| sentence per life stage after the species stage cue). The species comes
| from BreedType::species(); cat prompts never contain the word "dog".
| Domestic cat: no source for colour frequencies (data.json `appearance`
| UNSOURCED) → everything is a draft. Maine Coon: the traits in `sources`
| follow the FIFe standard (C16); colour names / weights are unsourced.
| Both `verified => false` (same rule as the dog breeds).
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
    'domestic_cat' => [
        'display_name' => 'domestic mixed-breed cat',
        'verified' => false,
        'source' => 'UNSOURCED draft (D) — cat-data/data.json domestic_cat.appearance: "short coat (rarely semi-long); tabby, solid, bicolour, tortoiseshell, calico; eyes green / yellow-amber / copper, blue only with white". No source for colour frequencies was found (cat-data/sources.md gaps), so every option and weight is a proposal, like the mutt.',
        'sources' => [],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => ['slender', ['value' => 'athletic', 'weight' => 2], 'sturdy'],
            'coat_length' => [['value' => 'short', 'weight' => 4], 'semi-long'],
            'coat_color' => [
                ['value' => 'brown', 'weight' => 3],
                ['value' => 'ginger', 'weight' => 2],
                ['value' => 'grey-blue', 'weight' => 2],
                'silver',
                ['value' => 'black', 'weight' => 2],
                'white',
                ['value' => 'black and white', 'weight' => 2],
                'grey and white',
                'ginger and white',
                'tortoiseshell black and orange',
                'calico white, black and orange',
            ],
            'coat_pattern' => [
                ['value' => 'with mackerel tabby stripes', 'weight' => 2, 'only_with' => ['coat_color' => ['brown', 'ginger', 'grey-blue', 'silver']]],
                ['value' => 'with classic marbled tabby swirls', 'only_with' => ['coat_color' => ['brown', 'ginger', 'grey-blue', 'silver']]],
                ['value' => 'with spotted tabby markings', 'only_with' => ['coat_color' => ['brown', 'ginger', 'silver']]],
                ['value' => 'solid', 'only_with' => ['coat_color' => ['black', 'white', 'grey-blue']]],
                ['value' => 'with a tuxedo pattern (white chest, paws and muzzle)', 'weight' => 2, 'only_with' => ['coat_color' => ['black and white', 'grey and white', 'ginger and white']]],
                ['value' => 'with large white patches', 'only_with' => ['coat_color' => ['black and white', 'grey and white', 'ginger and white']]],
                ['value' => 'with mottled patches', 'only_with' => ['coat_color' => ['tortoiseshell black and orange']]],
                ['value' => 'with distinct patches on a white base', 'only_with' => ['coat_color' => ['calico white, black and orange']]],
            ],
            'markings' => [
                ['value' => 'no special markings', 'weight' => 4],
                ['value' => 'white paws', 'only_with' => ['coat_color' => ['brown', 'ginger', 'silver']]],
                ['value' => 'a small white chest patch', 'only_with' => ['coat_color' => ['brown', 'ginger', 'silver']]],
            ],
            'ear_carriage' => ['upright'],
            'eye_color' => [
                ['value' => 'green', 'weight' => 3],
                ['value' => 'yellow', 'weight' => 2],
                ['value' => 'amber', 'weight' => 2],
                'copper',
                // Blue / odd eyes only with a white coat (data.json appearance).
                ['value' => 'blue', 'only_with' => ['coat_color' => ['white']]],
                ['value' => 'one blue and one copper', 'only_with' => ['coat_color' => ['white']]],
            ],
            'tail' => ['medium-long, tapering'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'markings', 'ear_carriage', 'eye_color', 'tail'],
    ],

    'maine_coon' => [
        'display_name' => 'Maine Coon cat',
        // Colour names / weights are not in the standard (unsourced) — same rule as the Border Collie.
        'verified' => false,
        'source' => 'FIFe breed standard Maine Coon (MCO), 01.01.2026 (C16, https://fifeweb.org/dnld/std/MCO.pdf) — for the traits listed in `sources`; size / maturity C17 (TICA via USA TODAY). Colour names and all weights are unsourced.',
        'sources' => [
            'size' => 'C16 "large framed"; C17 TICA males 5.9–8.2 kg, females 4.1–5.9 kg',
            'build' => 'C16 "broad chest, solid bone structure"',
            'coat_length' => 'C16 coat silky, "short on head, shoulders and legs, becoming gradually longer down the back and sides"',
            'coat_color' => 'C16 all colours allowed "except pointed patterns and chocolate and lilac, cinnamon and fawn" (the names and weights are unsourced)',
            'ear_carriage' => 'C16 "large ears"; "Lynx-tufts are desirable"',
            'eye_color' => 'C16 "All eye colours, except blue" (blue only with white)',
            'tail' => 'C16 tail "at least as long as the body"',
            'features' => 'C16 "square outline of the head"; "A frill is expected"; coat short on head / shoulders, longer down the back and sides',
            'stage_notes' => 'C17 "full maturity may take three to five years"; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'muscular, broad-chested', 'weight' => 2], 'solid-boned, broad-chested'],
            'coat_length' => ['silky, shaggy semi-long'],
            // No pointed, chocolate, lilac, cinnamon or fawn (C16).
            'coat_color' => [
                ['value' => 'brown', 'weight' => 3],
                'red',
                ['value' => 'silver', 'weight' => 2],
                'cream',
                'blue-grey',
                ['value' => 'black', 'weight' => 2],
                'white',
                'tortoiseshell black and red',
            ],
            'coat_pattern' => [
                ['value' => 'with classic tabby markings', 'weight' => 2, 'only_with' => ['coat_color' => ['brown', 'red', 'silver', 'cream', 'blue-grey']]],
                ['value' => 'with mackerel tabby stripes', 'only_with' => ['coat_color' => ['brown', 'red', 'silver', 'cream', 'blue-grey']]],
                ['value' => 'solid', 'only_with' => ['coat_color' => ['black', 'white', 'blue-grey']]],
                ['value' => 'with mottled patches', 'only_with' => ['coat_color' => ['tortoiseshell black and red']]],
                ['value' => 'with a white chest, belly and paws', 'only_with' => ['coat_color' => ['brown', 'red', 'silver', 'black', 'tortoiseshell black and red']]],
            ],
            'ear_carriage' => [['value' => 'large, tall lynx-tufted', 'weight' => 3], 'large, tall lightly tufted'],
            'eye_color' => [
                ['value' => 'green', 'weight' => 2],
                ['value' => 'gold', 'weight' => 2],
                ['value' => 'green-gold', 'weight' => 2],
                'copper',
                // Blue only with white (C16).
                ['value' => 'blue', 'only_with' => ['coat_color' => ['white']]],
                ['value' => 'one blue and one gold', 'only_with' => ['coat_color' => ['white']]],
            ],
            'tail' => ['very long, flowing, fully furred'],
        ],
        'features' => [
            'a square outline of the head',
            'a full frill around the neck and chest',
            'fur that is short on the head and shoulders and gradually longer down the back and sides',
        ],
        // CAT_SPEC §8 (D): the Maine Coon kitten is already bigger and has tufts;
        // the young Maine Coon is not fully grown yet (full size at 3–5 years, C17).
        'stage_notes' => [
            'puppy' => 'As a Maine Coon kitten it is already noticeably bigger than other kittens of its age, very fluffy, with small tufts on the ear tips',
            'young' => 'As a young Maine Coon it is not fully grown yet: a little lanky, with the frill and tail fur not yet full (the breed reaches full size at three to five years)',
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
];
