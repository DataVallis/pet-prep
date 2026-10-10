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
| Cats (M5-R06-07, CAT_SPEC §8, docs/research/cat-data): three optional keys
| that dog breeds do not use — `features` (fixed breed features every pet of
| the breed has, appended to the description), `stage_notes` (an extra
| sentence per life stage after the species stage cue) and `stage_overrides`
| (per stage: trait values / `features` that replace the adult wording while
| the cat is growing — every `stage_notes` stage has one). The species comes
| from BreedType::species(); cat prompts never contain the word "dog".
| Domestic cat: no source for colour frequencies (data.json `appearance`
| UNSOURCED) → everything is a draft. Maine Coon: the traits in `sources`
| follow the FIFe standard (C16); colour names / weights are unsourced.
| Both `verified => false` (same rule as the dog breeds).
|
| Labrador Retriever (M5-R10, docs/research/dog-data labrador_retriever.appearance):
| colours, coat, ears, eyes and the otter tail follow FCI 122 (S48) / RKC (S51) /
| AKC (S52) — solid colours only; colour weights are unsourced → `verified => false`.
|
| Golden Retriever (M5-R10-02, docs/research/dog-data golden_retriever.appearance):
| colours ("any shade of gold or cream, neither red nor mahogany"), the flat or
| wavy feathered coat with a dense water-resisting undercoat, ears, eyes and the
| level tail follow FCI 111 (S63) / RKC (S66) / AKC (S67); shade names and
| weights are unsourced → `verified => false`.
|
| French Bulldog (M5-R10-03, docs/research/dog-data french_bulldog.appearance):
| size, compact build, short smooth coat, bat ears, eyes, short low-set tail and
| the standard colours (brindle, fawn, pied — never merle / blue / black-and-tan,
| S78) follow FCI 101 (S76) / RKC standard (S79) / AKC (S80). David 2026-10-10:
| a moderate (not extreme) face with visibly open nostrils — the `muzzle` trait
| (RKC S79 "No point exaggerated" / "visibly open nostrils"); fawn weighted
| highest (portrait default). Colour weights are unsourced → `verified => false`.
|
| German Shepherd Dog (M5-R10-04, docs/research/dog-data german_shepherd.appearance):
| size, build, double coat, erect ears, dark eyes, bushy sabre tail and the
| standard colours (black with tan / gold markings, sable, black — never white,
| S95; never blue / liver, S97) follow FCI 166 (S95) / RKC standard (S98) / RKC
| breed page (S97). Welfare rule (RKC Breed Watch S99 "Incorrect hind
| conformation"): a level back and moderate, natural hind legs — the dog trait
| `topline`; black-and-tan weighted highest (portrait default). Colour weights
| are unsourced → `verified => false`.
|
| Cavalier King Charles Spaniel (M5-R10-05, docs/research/dog-data
| cavalier_king_charles_spaniel.appearance): size, build, long silky feathered
| coat, long feathered ears, large dark eyes "not prominent" and the four
| standard colours (Blenheim, tricolour, ruby, black and tan — never chocolate,
| S105) follow FCI 136 (S103) / RKC standard (S106) / RKC breed page (S105).
| Welfare rule (RKC Breed Watch S107 "Protruding eyes"; skull shape and CM/SM,
| S110): a visible, well-tapered muzzle and eyes that do not protrude — the dog
| trait `muzzle`; Blenheim weighted highest (portrait default). Colour weights
| are unsourced → `verified => false`.
|
| Beagle (M5-R10-06, docs/research/dog-data beagle.appearance): size, sturdy
| compact build, short dense coat, long low-set ears, dark brown or hazel eyes,
| broad nose with wide nostrils, the white tail tip and the standard colours
| follow FCI 161 (S111) / RKC standard (S114) / RKC breed page (S113). Not a
| welfare-concern breed (Breed Watch Category 1). Tricolour weighted highest
| (portrait default); only a subset of the ten standard colours is drawn.
| Colour weights are unsourced → `verified => false`.
|
| Standard Poodle (M5-R10-07, docs/research/dog-data standard_poodle.appearance):
| elegant, well-balanced build, profuse curly coat, long wide low-set ears, dark
| almond-shaped eyes, long fine head with straight muzzle, tail set rather high
| and the five solid colours follow FCI 172 (S118) / RKC standard (S121) / RKC
| breed page (S120). Not a welfare-concern breed (Breed Watch Category 1). Solid
| black weighted highest (portrait default); never parti-colour or white marks
| (FCI disqualification). Short even all-over trim (no show clip), natural tail.
| Colour weights are unsourced → `verified => false`.
|
| Dachshund, standard size (M5-R10-08, docs/research/dog-data dachshund.appearance):
| smooth dense short coat, high-set broad rounded ears, dark almond-shaped eyes,
| long conical head, tail continuing the spine and the colours red, black and
| tan, chocolate and tan follow the RKC standard (S126) / RKC breed page (S125).
| Welfare rule (long back → IVDD; BVA S128, RKC IVDD scheme S125): a moderately
| long body "with no exaggeration" and enough ground clearance — the dog trait
| `body`. Never dapple (RKC dapple-gene registration restriction, S125), white,
| piebald or tricolour. Red weighted highest (portrait default). Colour weights
| are unsourced → `verified => false`.
|
| Australian Shepherd (M5-R10-09, docs/research/dog-data australian_shepherd.appearance):
| medium size, slightly longer than tall, medium-length straight-to-wavy coat with a
| moderate mane, triangular high-set ears breaking forward, almond eyes (brown, blue
| or amber) and a natural long tail follow the FCI standard (S131). Colours: the four
| standard colours blue merle, black, red merle, red ("all with or without white
| markings and/or tan markings", S131) — merle IS a standard colour for this breed
| (runbook §3). White only within the standard's limits (collar, chest, legs, muzzle,
| blaze; eyes fully surrounded by colour, never white body splashes). Blue merle
| weighted highest (portrait default). Colour weights are unsourced → `verified => false`.
|
| Havanese (M5-R10-10, docs/research/dog-data havanese.appearance): a sturdy little
| dog, low on the legs, body slightly longer than tall, with a very long, soft, flat
| or wavy coat that is never trimmed (FCI S136), drop ears falling along the cheeks,
| large dark almond eyes and a tail carried high and rolled over the back (FCI S136,
| RKC standard S139). Colours: the FCI list — fawn in its shades, black, havana
| brown, tobacco, reddish brown and (rarely) white, with patches in these colours or
| tan markings (S136). Never merle (RKC S139 "except merle which is unacceptable").
| Fawn weighted highest (portrait default). Colour weights are unsourced → `verified => false`.
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

    // M5-R10 (docs/research/dog-data/data.json labrador_retriever.appearance).
    'labrador_retriever' => [
        'display_name' => 'Labrador Retriever',
        // Colour frequencies are in no source (labrador_retriever.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 122 (S48, https://www.fci.be/Nomenclature/Standards/122g08-en.pdf), Royal Kennel Club standard (S51), AKC standard 1994 (S52), RKC breed page (S50) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S50 "Size: Large"; PDSA S53, Woodgreen S60 the same',
            'build' => 'S48 "Strongly built, short-coupled, very active; broad in skull; broad and deep through chest and ribs"',
            'coat_length' => 'S48 "short, dense, without wave or feathering … weather-resistant undercoat"; S52 "short, straight and very dense"',
            'coat_color' => 'S48 "Wholly black, yellow or liver/chocolate. Yellows range from light cream to fox red, livers/chocolates range from light to dark." (weights unsourced)',
            'coat_pattern' => 'S48 "Small white spot on chest and the rear of pasterns permissible." "Any other colour or combination of colours unacceptable."',
            'ear_carriage' => 'S48 "Not large or heavy, hanging close to head and set rather far back." (one ear type)',
            'eye_color' => 'S48 "brown or hazel"; S52 "brown in black and yellow Labradors, and brown or hazel in chocolates" (the stricter AKC rule is used)',
            'tail' => 'S48 "very thick towards base, gradually tapering towards tip … described as "Otter" tail"',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'strongly built, broad-chested', 'weight' => 3], 'sturdy, short-coupled'],
            'coat_length' => ['short, dense'],
            // Solid colours only (S48, S51, S52); shades inside the standard's ranges.
            'coat_color' => [
                ['value' => 'black', 'weight' => 3],
                ['value' => 'yellow', 'weight' => 3],
                'light cream yellow',
                'fox red yellow',
                ['value' => 'chocolate brown', 'weight' => 2],
                'light liver brown',
            ],
            'coat_pattern' => [
                ['value' => 'solid', 'weight' => 4],
                'with a small white spot on the chest',
            ],
            'ear_carriage' => ['close-hanging'],
            'eye_color' => [
                ['value' => 'brown', 'weight' => 4],
                ['value' => 'hazel', 'only_with' => ['coat_color' => ['chocolate brown', 'light liver brown']]],
            ],
            'tail' => ['thick, tapering "otter"'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],

    // M5-R10-02 (docs/research/dog-data/data.json golden_retriever.appearance).
    'golden_retriever' => [
        'display_name' => 'Golden Retriever',
        // Shade names / frequencies are in no source (golden_retriever.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 111 (S63, https://www.fci.be/Nomenclature/Standards/111g08-en.pdf), Royal Kennel Club standard (S66), AKC standard 1981/1990 (S67), RKC breed page (S65) — for the traits listed in `sources`. Shade names and weights are unsourced.',
        'sources' => [
            'size' => 'S65 "Size: Large"; PDSA S68, Woodgreen S69 the same',
            'build' => 'S63 "Symmetrical, balanced, active, powerful, level mover; sound with kindly expression."',
            'coat_length' => 'S63 "Flat or wavy with good feathering, dense water-resisting undercoat."; S65 "Coat length: Medium"',
            'coat_color' => 'S63 "Any shade of gold or cream, neither red nor mahogany." (shade names and weights unsourced)',
            'coat_pattern' => 'S66 "A few white hairs on chest only, permissible."',
            'ear_carriage' => 'S63 "Moderate size, set on approximate level with eyes." (one ear type)',
            'eye_color' => 'S63 "Dark brown, set well apart, dark rims."; S67 "Color preferably dark brown; medium brown acceptable."',
            'tail' => 'S63 "Set on and carried level with back, reaching to hocks, without curl at tip."',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'symmetrical, powerful', 'weight' => 3], 'balanced, well-muscled'],
            // Flat or wavy (S63), both feathered with a water-resisting undercoat.
            'coat_length' => [
                ['value' => 'flat, feathered, water-resistant medium-length', 'weight' => 3],
                'wavy, feathered, water-resistant medium-length',
            ],
            // Gold or cream shades only — never red or mahogany (S63).
            'coat_color' => [
                ['value' => 'rich gold', 'weight' => 3],
                ['value' => 'light gold', 'weight' => 2],
                ['value' => 'deep gold', 'weight' => 2],
                'cream',
                'pale cream',
            ],
            'coat_pattern' => [
                ['value' => 'solid', 'weight' => 4],
                'with a few white hairs on the chest',
            ],
            'ear_carriage' => ['moderate-sized hanging'],
            'eye_color' => [
                ['value' => 'dark brown', 'weight' => 4],
                'medium brown',
            ],
            'tail' => ['feathered, level-carried, reaching to the hocks, without a curl'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],

    // M5-R10-03 (docs/research/dog-data/data.json french_bulldog.appearance;
    // David 2026-10-10: moderate face, open nostrils, standard colours, fawn portrait).
    'french_bulldog' => [
        'display_name' => 'French Bulldog',
        // Colour frequencies are in no source (french_bulldog.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 101 (S76, https://www.fci.be/Nomenclature/Standards/101g09-en.pdf), Royal Kennel Club standard (S79), AKC standard 2018 (S80), RKC breed page (S78) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S78 "Size: Small"; PDSA S81 the same',
            'build' => 'S76 "A powerful dog for its small size, short, stocky, compact in all its proportions, smooth-coated"; S79 "Sturdy, compact , solid, small dog with good bone"',
            'coat_length' => 'S76 "Smooth coat, close, glossy and soft, without undercoat."; S79 "Texture fine, smooth, lustrous, short and close."',
            'coat_color' => 'S79 "The only correct colours are: Brindle; Fawn; Pied;" "Any other colour or combination of colours unacceptable." (weights unsourced; merle / blue never, S78)',
            'coat_pattern' => 'S76 "fawn, brindled or not, with or without white spotting."; S78 standard colours incl. "Fawn & White", "Brindle & White", "Fawn Pied", "Pied", "Fawn With Black Mask"',
            'ear_carriage' => 'S79 "Bat ears, of medium size, wide at base, rounded at top; set high, carried upright and parallel" (quote marks around "Bat ears" dropped; one ear type)',
            'eye_color' => 'S79 "Preferably dark and matching. Moderate size, round, neither sunken nor prominent"',
            'tail' => 'S79 "Undocked, set low, thick at root, tapering quickly towards tip, preferably straight"',
            'muzzle' => 'S79 "Nose black and wide, relatively short, with visibly open nostrils"; "No point exaggerated, balance essential." (David 2026-10-10: moderate, not extreme)',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'sturdy, compact', 'weight' => 3], 'muscular, stocky'],
            'coat_length' => ['short, smooth, glossy'],
            // Standard colours only (S79); fawn highest → the register portrait is fawn.
            'coat_color' => [
                ['value' => 'fawn', 'weight' => 4],
                ['value' => 'brindle', 'weight' => 3],
                'light fawn',
            ],
            'coat_pattern' => [
                ['value' => 'solid', 'weight' => 4],
                ['value' => 'with a dark mask', 'only_with' => ['coat_color' => ['fawn', 'light fawn']]],
                'with white markings on the chest',
                'pied — mostly white with large patches of that colour',
            ],
            'ear_carriage' => ['upright, rounded "bat"'],
            'eye_color' => ['dark brown'],
            'tail' => ['naturally short, low-set, straight'],
            // David 2026-10-10: never an extreme face — moderate muzzle, open nostrils.
            'muzzle' => ['short but not exaggerated, with a black nose and visibly open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-04 (docs/research/dog-data/data.json german_shepherd.appearance;
    // runbook rules 2026-10-10: standard colours, level back, black-and-tan portrait).
    'german_shepherd' => [
        'display_name' => 'German Shepherd Dog',
        // Colour frequencies are in no source (german_shepherd.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 166 (S95, https://www.fci.be/Nomenclature/Standards/166g01-en.pdf), Royal Kennel Club standard (S98), RKC breed page (S97), RKC Breed Watch (S99) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S97 "Size: Large"; PDSA S100 the same',
            'build' => 'S95 "medium-size, slightly elongated, powerful and well-muscled"; S98 "balanced and free from exaggeration"',
            'coat_length' => 'S95 "the hair varieties double coat and long double coat"; S98 "Outer coat consisting of straight, hard, close-lying hair as dense as possible; thick undercoat."',
            'coat_color' => 'S95 "Colours are black with reddish-brown, brown and yellow to light grey markings." "The colour white is not allowed."; S97 standard colours Black & Tan, Black & Gold, Sable, Black … (weights unsourced; white / blue / liver never)',
            'coat_pattern' => 'S97 standard colours "Black & Tan", "Black & Gold", "Sable", "Gold Sable", "Grey Sable", "Black", "Bi-Colour"',
            'ear_carriage' => 'S95 "erect ears of medium size, which are carried upright and aligned"',
            'eye_color' => 'S98 "Medium-sized, almond-shaped, never protruding." "Dark brown preferred"',
            'tail' => 'S98 "Bushy-haired, reaches at least to hock." "At rest tail hangs in slight sabre-like curve"',
            'topline' => 'S98 "The topline runs without any visible break from the set on of the neck." "Any tendency towards over-angulation of hindquarters … highly undesirable."; S99 "Incorrect hind conformation and/or poor rear movement" (welfare rule: never the sloping show stance)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'powerful, well-muscled, slightly longer than tall', 'weight' => 3], 'athletic, balanced'],
            'coat_length' => [['value' => 'dense short double coat', 'weight' => 4], 'long double coat'],
            // Standard colours only (S95 / S97); black-and-tan highest → the register portrait is black and tan.
            'coat_color' => [
                ['value' => 'black and tan', 'weight' => 5],
                ['value' => 'black and gold', 'weight' => 2],
                ['value' => 'sable', 'weight' => 2],
                'solid black',
            ],
            'coat_pattern' => [
                ['value' => 'black saddle with tan legs, chest and face', 'weight' => 4, 'only_with' => ['coat_color' => ['black and tan', 'black and gold']]],
                ['value' => 'darker tips and a dark mask', 'only_with' => ['coat_color' => ['sable']]],
                ['value' => 'solid', 'only_with' => ['coat_color' => ['solid black']]],
            ],
            'ear_carriage' => ['erect, pointed, medium-sized'],
            'eye_color' => ['dark brown'],
            'tail' => ['bushy, hanging in a gentle sabre curve'],
            // Welfare rule (S99): never a sloping back or over-angulated hind legs.
            'topline' => ['level back with moderate, natural hind legs, standing square'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'topline'],
    ],
    // M5-R10-05 (docs/research/dog-data/data.json cavalier_king_charles_spaniel.appearance;
    // runbook rules 2026-10-10: standard colours, visible muzzle, Blenheim portrait).
    'cavalier_king_charles_spaniel' => [
        'display_name' => 'Cavalier King Charles Spaniel',
        // Colour frequencies are in no source (cavalier_king_charles_spaniel.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 136 (S103, https://www.fci.be/Nomenclature/Standards/136g09-en.pdf), Royal Kennel Club standard (S106), RKC breed page (S105), RKC Breed Watch (S107) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S105 "Size: Small"; PDSA S108 the same',
            'build' => 'S106 "Active, graceful and well balanced, with gentle expression."',
            'coat_length' => 'S106 "Long, silky, free from curl. Slight wave permissible."; S103 "Plenty of feathering. Totally free from trimming."',
            'coat_color' => 'S106 Black and Tan, Ruby, Blenheim, Tricolour; "Any other colour or combination of colours unacceptable." (weights unsourced; chocolate never, S105 NBS)',
            'coat_pattern' => 'S106 Blenheim "rich chestnut markings well broken up, on pearly white ground."; Ruby "whole coloured rich red."; Black and Tan "raven black with tan markings above the eyes, on cheeks, inside ears, on chest and legs and underside of tail."',
            'ear_carriage' => 'S106 "Long, set high, with plenty of feather."',
            'eye_color' => 'S106 "Large, dark, round but not prominent; spaced well apart."',
            'tail' => 'S106 "Length of tail in balance with body, well set on, carried happily but never much above the level of the back."',
            'muzzle' => 'S103 "Nostrils black and well developed without flesh marks." muzzle "about 1 1/2 ins. (3,8 cm). Well tapered."; S107 "Protruding eyes" (welfare rule: never a flat face or bulging eyes)',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'graceful, well balanced', 'weight' => 3], 'small, active'],
            'coat_length' => ['long, silky, with plenty of feathering'],
            // Standard colours only (S106); Blenheim highest → the register portrait is Blenheim.
            'coat_color' => [
                ['value' => 'Blenheim (rich chestnut and pearly white)', 'weight' => 4],
                ['value' => 'tricolour (black, white and tan)', 'weight' => 2],
                'ruby (whole rich red)',
                'black and tan',
            ],
            'coat_pattern' => [
                ['value' => 'chestnut patches well broken up on a white ground', 'weight' => 4, 'only_with' => ['coat_color' => ['Blenheim (rich chestnut and pearly white)']]],
                ['value' => 'black and white well broken up, tan over the eyes and on the cheeks', 'only_with' => ['coat_color' => ['tricolour (black, white and tan)']]],
                ['value' => 'solid, without white', 'only_with' => ['coat_color' => ['ruby (whole rich red)']]],
                ['value' => 'raven black with tan markings above the eyes, on the cheeks, chest and legs', 'only_with' => ['coat_color' => ['black and tan']]],
            ],
            'ear_carriage' => ['long, set high, with plenty of feather'],
            'eye_color' => ['dark brown, large and round but not protruding'],
            'tail' => ['feathered, carried happily, never much above the back'],
            // Welfare rule (S107 / S110): a visible, tapered muzzle — never a flat face.
            'muzzle' => ['visible, well-tapered muzzle with a black nose and open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-06 (docs/research/dog-data/data.json beagle.appearance; runbook rules
    // 2026-10-10: standard colours only, tricolour portrait).
    'beagle' => [
        'display_name' => 'Beagle',
        // Colour frequencies are in no source (beagle.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 161 (S111, https://www.fci.be/Nomenclature/Standards/161g06-en.pdf), Royal Kennel Club standard (S114), RKC breed page (S113) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S113 "Size: Small"; S114 height 33–40 cm (PDSA S115 says "Medium")',
            'build' => 'S114 "A sturdy, compactly built hound, conveying the impression of quality without coarseness."',
            'coat_length' => 'S114 "Short, dense and weatherproof."',
            'coat_color' => 'S114 "Tricolour (black, tan and white); blue, white and tan; badger pied; hare pied; lemon pied; lemon and white; red and white; tan and white; black and white; all white." "No other colours are permissible." (weights unsourced; a subset is drawn)',
            'coat_pattern' => 'S114 "Tip of stern white."; "With the exception of all white, all the above mentioned colours can be found as mottle."',
            'ear_carriage' => 'S114 "Long, with rounded tip, reaching nearly to end of nose when drawn out." "Set on low, fine in texture and hanging gracefully close to cheeks."',
            'eye_color' => 'S114 "Dark brown or hazel, fairly large, not deep set or prominent, set well apart with mild, appealing expression."',
            'tail' => 'S114 "Sturdy, moderately long. Set on high, carried gaily but not curled over back or inclined forward from root."',
            'muzzle' => 'S114 "Muzzle not snipy, lips reasonably well flewed." "Nose broad, preferably black" "Nostrils wide."',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'sturdy, compactly built', 'weight' => 3], 'sturdy, athletic'],
            'coat_length' => ['short, dense'],
            // Standard colours only (S114); tricolour highest → the register portrait is tricolour.
            'coat_color' => [
                ['value' => 'tricolour (black, tan and white)', 'weight' => 4],
                ['value' => 'tan and white', 'weight' => 2],
                'lemon and white',
                'red and white',
            ],
            'coat_pattern' => [
                ['value' => 'black saddle, tan head and ears, white legs, chest and tail tip', 'weight' => 4, 'only_with' => ['coat_color' => ['tricolour (black, tan and white)']]],
                ['value' => 'tan patches on white, white tail tip', 'only_with' => ['coat_color' => ['tan and white']]],
                ['value' => 'pale lemon patches on white, white tail tip', 'only_with' => ['coat_color' => ['lemon and white']]],
                ['value' => 'red patches on white, white tail tip', 'only_with' => ['coat_color' => ['red and white']]],
            ],
            'ear_carriage' => ['long, low-set, rounded tips, hanging close to the cheeks'],
            'eye_color' => [['value' => 'dark brown', 'weight' => 3], 'hazel'],
            'tail' => ['sturdy, moderately long, carried gaily, white tip'],
            'muzzle' => ['moderate muzzle, broad black nose with wide nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-07 (docs/research/dog-data/data.json standard_poodle.appearance; runbook rules
    // 2026-10-10: standard solid colours only, black portrait, natural short trim).
    'standard_poodle' => [
        'display_name' => 'Standard Poodle',
        // Colour frequencies are in no source (standard_poodle.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 172 (S118, https://www.fci.be/Nomenclature/Standards/172g09-en.pdf), Royal Kennel Club standard (S121), RKC breed page (S120) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S118 "Standard Poodles: Over 45 cm up to 60 cm with a tolerance of +2 cm."; S120 "Size: Medium" (PDSA S122 says "Large")',
            'build' => 'S121 "Well balanced, elegant looking with very proud carriage."; S118 "Dog of medium proportions"',
            'coat_length' => 'S118 "Profuse of fine, woolly texture, very frizzy, elastic and resistant to pressure of the hand."; S121 "All traditional trims permissible in the show ring" (the game uses a short even trim)',
            'coat_color' => 'S118 "Solid colour: black, white, brown, grey, fawn."; S121 "All solid colours." (weights unsourced)',
            'coat_pattern' => 'S118 disqualifying faults: "Subjects whose coat is not of solid colour." "All white marks on the body and/or feet for all subjects other than white."',
            'ear_carriage' => 'S121 "Leathers long and wide, set low, hanging close to face."',
            'eye_color' => 'S121 "Almond-shaped, dark, not set too close together, full of fire and intelligence."; S118 "Black or dark brown colour."',
            'tail' => 'S121 (undocked) "Thick at root, set on rather high, carried away from the body and as straight as possible."',
            'muzzle' => 'S118 muzzle "Upper profile is perfectly straight"; nose "open nostrils. Black nose in black, white and grey subjects"; S121 "Long and fine with slight peak."',
        ],
        'traits' => [
            'size' => ['medium-large, tall'],
            'build' => [['value' => 'elegant, well balanced, proud carriage', 'weight' => 3], 'elegant, athletic'],
            'coat_length' => ['dense curly coat in a short, even all-over trim'],
            // Standard solid colours only (S118); black highest → the register portrait is black.
            'coat_color' => [
                ['value' => 'solid black', 'weight' => 4],
                ['value' => 'solid white', 'weight' => 3],
                ['value' => 'solid brown', 'weight' => 2],
                'solid grey',
                'solid fawn',
            ],
            'coat_pattern' => ['one solid colour, no white marks'],
            'ear_carriage' => ['long, wide, set low, hanging close to the face'],
            'eye_color' => ['dark brown, almond-shaped'],
            'tail' => ['natural undocked tail set rather high, carried away from the body'],
            'muzzle' => ['long fine head, straight muzzle, black nose with open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-08 (docs/research/dog-data/data.json dachshund.appearance; runbook rules
    // 2026-10-10: standard colours only, never dapple, red portrait, moderate body length).
    'dachshund' => [
        'display_name' => 'Dachshund',
        // Colour frequencies are in no source (dachshund.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'Royal Kennel Club standard, Dachshund (Smooth Haired) (S126, https://www.royalkennelclub.com/breed-standards/hound/dachshund-smooth-haired/), RKC breed page (S125), PDSA (S127) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S126 "Ideal weight: 9-12 kgs (20-26 lbs)."; S125 "Size: Medium"; S127 "Standard 20-27cm"',
            'build' => 'S126 "Moderately long in proportion to height, with no exaggeration."',
            'coat_length' => 'S126 "Smooth Haired: Dense, short and smooth."',
            'coat_color' => 'S126 colour section: red, black or chocolate with tan markings (dapple not drawn, S125 dapple-gene restriction; weights unsourced)',
            'coat_pattern' => 'S126 "In all colours no white permissible, save for a small patch on chest which is permitted but not desirable."',
            'ear_carriage' => 'S126 "Set high, and not too far forward. Broad, of moderate length, and well rounded (not pointed or folded)."',
            'eye_color' => 'S126 "Medium size, almond-shaped, set obliquely. Dark except in chocolates, where they can be lighter."',
            'tail' => 'S126 "Continues line of spine, but slightly curved, without kink or twist,"',
            'body' => 'S126 "Moderately long and full muscled." "Sloping shoulders, back reasonably level"; welfare: S128 / S129 long back and IVDD → moderate length, enough ground clearance',
        ],
        'traits' => [
            'size' => ['small, low to the ground'],
            'build' => [['value' => 'muscular, moderately long, no exaggeration', 'weight' => 3], 'compact, muscular'],
            'coat_length' => ['dense, short, smooth coat'],
            // Standard colours only (S126); red highest → the register portrait is red. Never dapple.
            'coat_color' => [
                ['value' => 'red', 'weight' => 4],
                ['value' => 'black and tan', 'weight' => 3],
                'chocolate and tan',
            ],
            'coat_pattern' => ['no white markings'],
            'ear_carriage' => ['set high, broad, rounded, hanging close to the cheeks'],
            'eye_color' => ['dark, almond-shaped'],
            'tail' => ['tail continuing the line of the back, slightly curved'],
            'body' => ['moderately long body with a level back and enough ground clearance, sturdy legs'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'body'],
    ],
    // M5-R10-09 (docs/research/dog-data/data.json australian_shepherd.appearance; runbook rules
    // 2026-10-10: standard colours only — merle is standard here — blue merle portrait, natural long tail).
    'australian_shepherd' => [
        'display_name' => 'Australian Shepherd',
        // Colour frequencies are in no source (australian_shepherd.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 342 (S131, https://www.fci.be/Nomenclature/Standards/342g01-en.pdf), RKC breed page (S133) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S133 "Size: Medium"; S131 "The preferred height for males is 20-23 inches (51-58 cm), females 18-21 inches (46-53 cm)."',
            'build' => 'S131 "well balanced, slightly longer than tall, of medium size and bone"; "lithe and agile, solid and muscular without cloddiness."',
            'coat_length' => 'S131 "Of medium texture, straight to wavy, weather resistant and of medium length." "There is a moderate mane and frill"',
            'coat_color' => 'S131 "Blue merle, black, red merle, red – all with or without white markings and/or tan markings, with no order of preference." (weights unsourced)',
            'coat_pattern' => 'S131 white "on the neck (either in part or as a full collar), chest, legs, muzzle underparts, blaze on head"; "the eyes must be fully surrounded by colour and pigment"; disqualifying "White body splashes"',
            'ear_carriage' => 'S131 "Triangular, of moderate size and leather, set high on the head. At full attention they break forward and over, or to the side as a rose ear."',
            'eye_color' => 'S131 "Brown, blue, amber or any variation or combination thereof, including flecks and marbling." "Almond shaped, not protruding nor sunken."',
            'tail' => 'S131 "Straight, naturally long or naturally short." (the game draws a natural long tail — no docking)',
        ],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'well balanced, athletic, slightly longer than tall', 'weight' => 3], 'lithe, muscular'],
            'coat_length' => ['medium-length, straight to wavy coat with a moderate mane'],
            // Standard colours only (S131); blue merle highest → the register portrait is blue merle.
            'coat_color' => [
                ['value' => 'blue merle', 'weight' => 4],
                ['value' => 'black', 'weight' => 2],
                ['value' => 'red merle', 'weight' => 2],
                'red',
            ],
            'coat_pattern' => [
                ['value' => 'white collar, chest and legs with copper points, eyes fully surrounded by colour', 'weight' => 3],
                'small white blaze, white chest and feet with copper points, eyes fully surrounded by colour',
            ],
            'ear_carriage' => ['triangular, set high, breaking forward'],
            'eye_color' => [['value' => 'brown', 'weight' => 3], 'blue', 'amber'],
            'tail' => ['natural long tail'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-10 (docs/research/dog-data/data.json havanese.appearance; runbook rules
    // 2026-10-10: FCI colours only, never merle — fawn portrait, natural long coat).
    'havanese' => [
        'display_name' => 'Havanese',
        // Colour frequencies are in no source (havanese.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 250 (S136, https://www.fci.be/Nomenclature/Standards/250g09-en.pdf), RKC breed standard (S139), RKC breed page (S138) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S138 "Size: Small"; S136 "Height at the withers: From 23 to 27 cm."',
            'build' => 'S136 "The Havanese is a sturdy little dog, low on his legs, with long abundant hair, soft and preferably wavy."; S139 "Small, sturdy, slightly longer in body than height at withers."',
            'coat_length' => 'S136 "The topcoat is very long (12-18 cm in an adult dog), soft, flat or wavy and may form curly strands."; all trimming forbidden',
            'coat_color' => 'S136 "Rarely completely pure white, fawn in its different shades (slight blackened overlay admitted), black, havana-brown, tobacco colour, reddish-brown." S139 "except merle which is unacceptable" (weights unsourced)',
            'coat_pattern' => 'S136 "Patches in mentioned colours allowed." "Tan markings in all nuances permitted."',
            'ear_carriage' => 'S136 "Set relatively high; they fall along the cheeks forming a discreet fold which raises them slightly."',
            'eye_color' => 'S136 "Quite big, almond shape, of brown colour as dark as possible."; S139 "Dark, large, almond shaped, gentle expression"',
            'tail' => 'S136 "Carried high, either in shape of a crozier or preferably rolled over the back;"; S139 "profusely feathered with long silky hair."',
        ],
        'traits' => [
            'size' => ['small, toy-sized'],
            'build' => [['value' => 'sturdy, low on the legs, body slightly longer than tall', 'weight' => 3], 'sturdy, compact'],
            'coat_length' => [
                ['value' => 'very long, soft, wavy natural coat', 'weight' => 3],
                'very long, soft, flat natural coat with a few curly strands',
            ],
            // FCI colours only (S136); fawn highest → the register portrait is fawn. Never merle (S139).
            'coat_color' => [
                ['value' => 'fawn', 'weight' => 4],
                ['value' => 'black', 'weight' => 2],
                ['value' => 'havana brown', 'weight' => 2],
                'tobacco',
                'reddish brown',
                'white',
            ],
            'coat_pattern' => [
                ['value' => 'solid colour', 'weight' => 3],
                'with white patches',
                'with tan markings',
            ],
            'ear_carriage' => ['drop ears falling along the cheeks with a slight fold'],
            'eye_color' => ['large, dark brown, almond-shaped'],
            'tail' => ['tail carried high and curled over the back, richly feathered'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
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
        // A growing Maine Coon (QA M5-R06-07): no "full frill" / "very long" tail in the
        // same prompt as the stage note — these replace the adult features / tail.
        'stage_overrides' => [
            'puppy' => [
                'tail' => 'long, fluffy',
                'features' => ['a square outline of the head', 'soft kitten fur that is already a little longer on the back and sides'],
            ],
            'young' => [
                'tail' => 'long, bushy',
                'features' => ['a square outline of the head', 'a frill that is still filling out', 'fur that is short on the head and shoulders and longer down the back and sides'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
];
