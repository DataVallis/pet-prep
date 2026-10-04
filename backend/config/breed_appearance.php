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
| !!! NOT VERIFIED !!!
| David 2026-10-03 (DECISIONS, ROADMAP M1-19): breed data must come from
| verifiable sources. These option lists are a first draft written by Claude from
| general knowledge so that DNA v2 and the AI Lab can be tested. Every breed is
| marked `verified => false` and must be replaced in M1-19 with options sourced
| from the FCI standard (border collie: FCI No. 297) / AKC standard, with the
| source recorded in `source`. Do not present these lists as breed facts to
| parents or children.
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
        'source' => null,
        'todo' => 'M1-19: no breed standard exists for mixed breeds; define a broad, realistic range (e.g. from shelter intake statistics) and record the source.',
        'traits' => [
            'size' => ['small', ['value' => 'medium-sized', 'weight' => 3], 'large'],
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
        'verified' => false,
        'source' => null,
        'todo' => 'M1-19: replace with options sourced from the FCI standard No. 297 (Border Collie) and the AKC standard; record both URLs here.',
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'athletic', 'weight' => 3], 'lean', 'well-muscled'],
            'coat_length' => [['value' => 'medium-length rough double', 'weight' => 3], 'smooth short'],
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
            'eye_color' => [
                ['value' => 'brown', 'weight' => 6],
                ['value' => 'amber', 'only_with' => ['coat_color' => ['red and white', 'red merle and white', 'sable and white']]],
                ['value' => 'one blue and one brown', 'only_with' => ['coat_color' => ['blue merle and white', 'red merle and white']]],
                ['value' => 'blue', 'only_with' => ['coat_color' => ['blue merle and white']]],
            ],
            'tail' => ['long, low-set with an upward swirl at the tip'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'markings', 'ear_carriage', 'eye_color', 'tail'],
    ],
];
