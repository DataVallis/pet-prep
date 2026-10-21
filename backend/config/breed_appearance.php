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
| West Highland White Terrier (M5-R10-11, docs/research/dog-data
| west_highland_white_terrier.appearance): a small, strongly built terrier with a deep
| chest and level back, a harsh white double coat about 5 cm long without curl, small
| erect pointed ears, dark eyes set wide apart under heavy eyebrows, a black nose and a
| straight tail carried jauntily, never docked (FCI S142, RKC standard S145). Colour:
| white only — "Any other colour or combination of colours unacceptable." Welfare rule
| (RKC Breed Watch S146, dermatitis): the dog trait `coat_condition` draws healthy skin
| and a full coat, never red or bald patches. `verified => false` (single colour, but
| the traits are an unchecked draft like the other breeds).
|
| Bernese Mountain Dog (M5-R10-12, docs/research/dog-data
| bernese_mountain_dog.appearance): a longhaired, tricoloured, strong and agile working
| dog of above medium size with sturdy limbs; jet black with rich tan on the cheeks,
| above the eyes, on all four legs and on the chest, a symmetrical white blaze and
| muzzle band and white on the throat and chest; long, shining, straight or slightly
| wavy coat; medium triangular ears hanging flat; dark brown almond eyes; a bushy tail
| hanging down at rest (FCI S150, RKC standard S153). Colour: tricolour only — "Any
| other colour or combination of colours unacceptable." Not a conformation welfare
| breed (RKC Breed Watch Category 1, S152): a natural, moderate build. `verified =>
| false` (single colour, but the traits are an unchecked draft like the other breeds).
|
| Siberian Husky (M5-R10-13, docs/research/dog-data siberian_husky.appearance): a
| medium-sized working sled dog, quick and light on his feet, moderately compact and
| well furred; double, medium-length coat never so long as to obscure the outline;
| thick, well-furred, triangular, strongly erect ears; almond eyes, brown or blue (one
| of each or particoloured acceptable); a well-furred fox-brush tail carried over the
| back in a sickle curve at attention (FCI S158, RKC standard S161, AKC S163). Colours:
| "All ranges of the allowable colours which are black, grey, agouti, sable, red and
| white" — merle and brindle are disqualifying (S158, S161). Colour weights are
| unsourced (grey and white first → the register portrait) → `verified => false`. Not a
| conformation welfare breed (Breed Watch S164 lists only "Too fat" / "Too thin"): a
| moderate, athletic build, never heavy.
|
| Rottweiler (M5-R10-19, docs/research/dog-data rottweiler.appearance): a medium to
| large, stalwart dog, neither heavy nor light; top coat of medium length, coarse, dense
| and flat; medium-sized, pendant, triangular ears; medium, almond-shaped, dark brown
| eyes; natural (undocked) tail (FCI 147 S166, AKC via the American Rottweiler Club S170).
| One standard colour: "Black with clearly defined markings of a rich tan on the cheeks,
| muzzle, throat, chest and legs" — white markings are a fault (S166). Welfare rule: RKC
| Breed Watch (S173) names "Excessively short muzzle" and "Incorrect hind conformation
| and/or poor rear movement" → the dog trait `build` asks for a level back and sound hind
| legs, and the `muzzle` trait for a moderate muzzle (FCI muzzle : skull ≈ 1 : 1.5).
| Weights unsourced (one colour) → `verified => false`.
|
| Bulldog (M5-R10-20, docs/research/dog-data bulldog.appearance): smooth-coated, fairly
| thick set, low, broad, powerful and compact; fine, short, close, smooth coat; small, thin
| rose ears; round, very dark eyes of moderate size, no white showing; low-set tail
| carried downward (RKC standard S178, FCI 149 S175, AKC via the Bulldog Club of America
| S179). Colours: red, fawn, white, red / fawn brindle, pied, red or fawn with white —
| "Any other colour or pattern highly undesirable" (S178); never black, black and tan
| (S175), merle (S179). Welfare rule (flat-faced breed, as the French Bulldog): RKC
| Breed Watch Category 3 (S182) names respiratory distress, heavy overnose wrinkle,
| pinched nostrils, screw tails and skin-fold dermatitis → the dog trait `muzzle` asks
| for a moderate face with wide-open nostrils and a light wrinkle, `build` for a sound,
| not exaggerated body, `tail` for a natural straight tail. Weights unsourced →
| `verified => false`; red and white weighted highest (the register portrait).
|
| German Shorthaired Pointer (M5-R10-21, docs/research/dog-data german_shorthaired_pointer.appearance):
| noble and balanced, built for strength, endurance and speed; short, dense, rough and hard
| coat; broad, flat ears set high; almond, dark-brown eyes; brown nose on a liver dog (FCI 119
| S184, AKC via GSPCA S186). Colours: solid liver, liver and white (ticked / roan / patched),
| solid black, black and white — "Any other color or color combination is a disqualification."
| and red / orange / lemon / tan or solid white disqualify (S186). Both standards describe a
| docked tail; the game shows the natural tail (Claude's proposal 2026-10-11, open for David). Weights
| unsourced → `verified => false`; liver roan with a solid liver head weighted highest (the
| register portrait).
|
| Yorkshire Terrier (M5-R10-22, docs/research/dog-data yorkshire_terrier.appearance):
| long-coated toy terrier, coat hanging straight and evenly with a parting from nose to tail;
| moderately long, perfectly straight, glossy, fine silky coat that must never impede
| movement; small V-shaped erect ears of rich tan; medium dark eyes; black nose (FCI 86 S190,
| RKC S193). Dark steel blue and tan is the only standard colour (never fawn, bronze or dark
| hairs in the blue). FCI describes both a docked and an undocked tail ("Previously
| customarily docked") → the game shows the natural tail. One colour; the trait list is an
| unchecked draft → `verified => false`.
|
| Cane Corso (M5-R10-23, docs/research/dog-data cane_corso.appearance): medium to large,
| robust and sturdy molosser with some elegance, slightly longer than tall; short, shiny,
| very dense coat; uncropped ears ("Ears are un-cropped."); natural tail ("Natural."); iris
| as dark as possible; muzzle strong and square, about half the skull length (FCI 343, 2023,
| S197; AKC S200). Colours: black, grey (blue) shades, fawn (with mask), stag red, dark wheat,
| black / grey brindle — "All colours not indicated in the standard; large white patches."
| disqualify (S197); never black-and-tan (AKC S200). Ear cropping and tail docking are illegal
| in the UK (PDSA S199) → natural ears and tail only. PDSA lists eyelid problems (S199) → the
| `muzzle` trait asks for a moderate face without exaggerated loose skin. Colour weights are
| unsourced → `verified => false`; solid black weighted highest (the register portrait).
|
| Pembroke Welsh Corgi (M5-R10-24, docs/research/dog-data pembroke_welsh_corgi.appearance):
| low set, strong, sturdily built, alert and active; body of medium length with a level
| topline; medium-length straight coat with a dense undercoat; pricked, medium, slightly
| rounded ears; foxy head, slightly tapering muzzle; round brown eyes; black nose (FCI 39,
| 2026, S202; RKC S205). Colours: self red, sable, fawn, black and tan, with or without
| white markings on legs, brisket and neck. "Previously customarily docked short" → the game
| shows the natural tail set in line with the topline (a standard option). Moderate body
| length and enough ground clearance (PDSA S206 lists intervertebral disc disease) — the
| `body` trait (as the Dachshund). Colour weights are unsourced → `verified => false`;
| red with white markings weighted highest (the register portrait).
|
| Dobermann (M5-R10-25, docs/research/dog-data dobermann.appearance): medium size, strong
| and muscular with elegant lines and a proud stature; short, hard, dense, tight-lying coat
| without undercoat; long head like a blunt wedge; medium, oval, dark eyes; ears and tail
| "left natural" (FCI 143, 2015, S207) — natural drop ears lying close to the cheeks and a
| natural tail, never cropped or docked. Colours: black or brown with clearly defined rust
| red tan markings (FCI; the RKC standard S210 also lists blue and fawn — not drawn); never
| white markings. Not a welfare-concern breed (RKC Breed Watch Category 1). Colour weights
| are unsourced → `verified => false`; black and rust weighted highest (the register portrait).
|
| Boxer (M5-R10-26, docs/research/dog-data boxer.appearance): medium-sized, square build,
| strong bone and well developed muscles; short, glossy, smooth coat tight to the body; broad,
| deep muzzle that is "never narrow, pointed, short or shallow" with a broad black nose and
| wide nostrils (RKC standard S217); dark brown eyes; ears lying flat and close to the cheek,
| natural (FCI 144, S213) — never cropped (the AKC S218 cropping is not drawn); natural
| undocked tail. Colours: fawn or brindle with a black mask, white markings up to a third
| (all three standards). Welfare (moderately brachycephalic, VetCompass S220; Breed Watch
| Category 2 "Pinched nostrils" / "Excessively short muzzle", S216): a `muzzle` trait asks
| for a moderate muzzle with wide open nostrils (as the French Bulldog). Colour weights are
| unsourced → `verified => false`; fawn with a black mask weighted highest (the register
| portrait).
|
| Miniature Schnauzer (M5-R10-27, docs/research/dog-data miniature_schnauzer.appearance): small,
| strong, stocky, nearly square; harsh, wiry, dense coat with a dense undercoat, beard and
| eyebrows (FCI 183 S221, RKC standard S224); natural V-shaped drop ears (FCI S221) — never
| cropped (the AKC S225 cropping is not drawn); natural tail (FCI "Natural"). Colours: pepper and
| salt (with a dark facial mask), black and silver, pure black. White is standard in FCI / RKC
| but disqualifies under AKC (S225) → not drawn (open for David). Not a welfare-concern breed
| (RKC Breed Watch Category 1, S223). Colour weights are unsourced → `verified => false`; pepper
| and salt weighted highest (the register portrait).
|
| Shih Tzu (M5-R10-28, docs/research/dog-data shih_tzu.appearance): sturdy, abundantly coated
| small dog with a "chrysanthemum-like" face (RKC standard S232); long, dense, straight outer coat
| with a moderate undercoat; large dark round eyes, not prominent; large drooping ears; heavily
| plumed tail carried over the back (FCI 208 S229). Colours: "All colours permissible except merle"
| (S232) → parti-colours only: gold and white, black and white, liver and white. Welfare breed
| (brachycephalic — BVA S236, PDSA BOAS S233): a moderate, short square muzzle with clearly open
| nostrils (trait `muzzle`). Colour weights are unsourced → `verified => false`; gold and white
| weighted highest (the register portrait).
|
| Great Dane (M5-R10-29, docs/research/dog-data great_dane.appearance): a very large, powerful, well
| constructed body combining pride, strength and elegance (FCI 235 S239); very short, dense,
| smooth, glossy coat; long, narrow head with a broad muzzle; medium, dark eyes; ears naturally
| pendant, set on high (natural, never cropped); tail reaching to the hocks. Colours: only those
| both standards share (FCI S239 / RKC S242) — fawn (black mask desired), brindle, harlequin,
| black, blue; never merle (FCI only), mantle (RKC only), albino or a white blaze / collar.
| Welfare (RKC Breed Watch Category 2, S244: eyelids turned in or out, hind conformation): tight
| eyelids and a sound, natural build in the eye and build traits. Colour weights are unsourced →
| `verified => false`; fawn weighted highest (the register portrait).
|
| Pomeranian (M5-R10-30, docs/research/dog-data pomeranian.appearance): a compact, short-coupled
| toy spitz (RKC standard S249); double coat — soft fluffy undercoat, long straight harsh outer coat,
| abundant frill round the neck and chest, natural and untrimmed (FCI S246 "Hair must not look like
| being modelled."); small erect ears set high; fox-like head with a fine muzzle; medium, slightly
| oval, dark eyes; tail set high, turned over the back and carried flat. Colours: RKC whole colours
| (S249) — orange, cream, white, black, brown (the FCI S246 names all but cream: 'other colours'); never merle (RKC
| "Merles not permissible."), never white markings on a coloured dog (FCI disqualifying). Colour
| weights are unsourced → `verified => false`; orange weighted highest (the register portrait).
|
| Boston Terrier (M5-R10-31, docs/research/dog-data boston_terrier.appearance): a lively, smooth-coated,
| short-headed, compactly built, short-tailed, well-balanced dog (FCI 140 S254, RKC standard S257);
| short, smooth, bright, fine coat; small erect ears (natural — never cropped); wide-apart, large,
| round, dark eyes, not overly prominent; low-set, short, fine, tapering tail (natural — the FCI
| disqualifies a docked tail). Colours: brindle, seal or black, always with the required white markings
| (white muzzle band, blaze between the eyes, forechest; S254); never solid colours, grey / blue, liver,
| fawn or merle. Welfare breed (brachycephalic — PDSA S259, RKC S255 / S261, BVA S92): the dog trait
| `muzzle` asks for a moderate, square muzzle free from wrinkles with clearly open nostrils (FCI fault
| "Pinched nostrils.", RKC "Nostrils open."). Colour weights are unsourced → `verified => false`;
| brindle and white weighted highest (the colour both standards prefer — the register portrait).
|
| Miniature American Shepherd (M5-R10-32, docs/research/dog-data miniature_american_shepherd.appearance):
| a small, athletic herding dog, slightly longer than tall (FCI 367 S265, AKC standard S266); medium
| texture, straight-to-wavy, weather-resistant, medium-length coat; triangular, moderate, high-set ears
| that fold (prick ears a severe fault); eyes brown, blue, hazel, amber or any combination, fully
| surrounded by colour; natural undocked tail (the standards prefer docked / natural bobtail — the game
| never draws docking). Colours: black, blue merle, red (liver), red (liver) merle, with or without white
| and tan markings, "with no order of preference" — merle is standard here (as the Australian Shepherd);
| never white body splashes or a predominantly white head. Colour weights are unsourced →
| `verified => false`; blue merle weighted highest (the register portrait).
|
| Chihuahua (M5-R10-33, docs/research/dog-data chihuahua.appearance): the Smooth Coat variety (RKC S275) —
| small, dainty, compact (KC standard S274, FCI 218 S273); well-rounded 'apple dome' skull, definite stop,
| muzzle moderately short and slightly pointed; large round eyes, not protruding; large flaring ears at
| about 45°; medium-length sickle tail over the back; smooth, soft, close, glossy coat. Colours: any
| colour or mixture of colours, never merle (KC "Never merle (dapple).", FCI disqualifying). Welfare:
| the dog trait `muzzle` asks for a moderate dome and muzzle with eyes that are not protruding (no
| exaggerated tiny-dog features). Colour weights are unsourced → `verified => false`; fawn weighted
| highest (the register portrait).
|
| English Springer Spaniel (M5-R10-34, docs/research/dog-data english_springer_spaniel.appearance):
| symmetrically built, compact, strong, merry, active, the highest on leg of the British land spaniels
| (KC standard S282); skull of medium length, fairly broad, slightly rounded, foreface of proportionate
| length; medium, almond-shaped, dark hazel eyes; lobular, long, well-feathered ears set in line with
| the eye; close, straight, weather-resisting coat with moderate feathering; tail set low, never above
| the back — natural (KC "Previously customarily docked."). Colours (KC): liver and white, black and
| white, either with tan markings — never roan, lemon, red or orange in the game. Colour weights are
| unsourced → `verified => false`; liver and white weighted highest (the register portrait).
|
| Shetland Sheepdog (M5-R10-35, docs/research/dog-data shetland_sheepdog.appearance):
| small, long-haired working dog of great beauty, lithe and graceful (FCI standard S290); head a
| long, blunt wedge tapering from ear to nose; medium, oblique, almond-shaped dark brown eyes (blue
| allowed in merles); small ears carried semi-erect with the tips falling forward; double coat with a
| long, harsh, straight outer coat and abundant mane and frill; tail set low with abundant hair, never
| over the back. Colours (FCI): sable, tricolour, blue merle, black and white, black and tan — white
| only on the blaze, collar, chest, frill, legs and tail tip; never brindle, never white patches on
| the body, never a mostly white double merle. Colour weights are unsourced → `verified => false`;
| sable and white weighted highest (the register portrait).
|
| Brittany (M5-R10-36, docs/research/dog-data brittany.appearance): workmanlike, substance without
| heaviness, moderately boned, compact and short-backed — the smallest of the pointing dogs (RKC
| standard S299, FCI S297); skull slightly rounded, muzzle tapered but not snipy; expressive brown to
| dark eyes; triangular high-set ears, slightly rounded at the tip, lying flat; dense, fairly fine,
| flat or slightly wavy coat; naturally tailless or short tail (often born bob-tailed, S298). Colours
| (RKC / FCI): orange, liver or black and white, clear or roan, tricolour — never a solid colour, never
| white spots on the ears (FCI). AKC (S300) allows only orange or liver and white. Colour weights are
| unsourced → `verified => false`; orange and white weighted highest (the register portrait).
|
| American Cocker Spaniel (M5-R10-37, docs/research/dog-data american_cocker_spaniel.appearance): the
| smallest of the sporting dogs, compact and sturdy (AKC standard S307, FCI S304); rounded skull,
| pronounced stop, broad and deep muzzle; round, full, dark brown eyes (blue eyes disqualify, FCI);
| long lobular well-feathered ears set no higher than the lower part of the eye; silky, flat or
| slightly wavy coat with moderate feathering (pet length in the game, not a floor-length show skirt).
| Varieties (AKC / FCI): black (incl. black and tan), any solid colour other than black (cream / buff to
| red, brown), parti-colours with white (incl. roan, tricolour) — never merle. The standards describe a
| docked tail; the game shows a natural undocked tail (Claude's proposal, open for David). Colour weights
| are unsourced → `verified => false`; buff weighted highest (the register portrait).
|
| Belgian Malinois (M5-R10-38, docs/research/dog-data belgian_malinois.appearance): the short-haired
| variety of the Belgian Shepherd Dog (FCI 15, S313); square, well balanced, elegant and powerful with a
| proud carriage of head and neck (AKC standard S315); rather small, high-set, triangular erect ears
| (hanging or semi-prick ears disqualify, AKC); medium, brown, preferably dark eyes; short, straight,
| hard coat with a dense undercoat, fuller at the neck and tail. Colour (FCI): "Only fawn with black
| overlay and with black mask." — AKC "rich fawn to mahogany, with black tips"; a pronounced black mask
| is required. Never black, brindle, merle, grey or white patches. Natural erect ears and a full tail
| reaching the hock (cropped / stumped tails disqualify, AKC). Colour weights are unsourced →
| `verified => false`; fawn weighted highest (the register portrait).
|
| Basset Hound (M5-R10-39, docs/research/dog-data basset_hound.appearance): "Short-legged hound of
| considerable substance, well balanced, full of quality." (FCI 163, S320); smooth, short, close coat;
| low-set, narrow, inward-curling velvety ears; lozenge-shaped dark eyes; supple skin "without any
| exaggeration", a little wrinkle at the brow and beside the eyes; rather long tapering tail. Colours
| (FCI): "Generally black, white and tan (tri-colour); lemon and white (bi-colour)" — never merle.
| Welfare breed (RKC Breed Watch Category 3, S322: eyelids turned in or out, ears far beyond the muzzle,
| skin-fold dermatitis, inadequate ground clearance / excessive skin, too fat) → moderate ears, tight
| eyelids, little loose skin (dog trait `skin`) and adequate ground clearance. Colour weights are
| unsourced → `verified => false`; tricolour weighted highest (the register portrait).
|
| Hungarian Vizsla (M5-R10-40, docs/research/dog-data hungarian_vizsla.appearance): "Medium sized,
| elegant gun dog of noble appearance with short russet gold coat." (FCI 57, S327); short, dense, hard
| coat without undercoat; broad nose, self-coloured (AKC S331 — never black); slightly oval brown eyes;
| ears set at medium height hanging close to the cheeks; tail to the hock where docking is prohibited
| (the game shows the natural tail). Colours (FCI): "Various shades of russet gold and dark sandy gold
| (semmelgelb)." — never dark brown, pale yellow, parti-colour or white feet. Not a welfare breed (RKC
| Breed Watch Category 1). New dog trait `nose` (the self-coloured nose sets the breed apart). Colour
| weights are unsourced → `verified => false`; russet gold weighted highest (the register portrait).
|
| English Cocker Spaniel (M5-R10-41, docs/research/dog-data english_cocker_spaniel.appearance): "Chien
| de chasse gai et vigoureux, harmonieux et compact." (FCI 5, S336); flat, silky coat, never wiry, wavy
| or curly; lobular ears set low at eye level with long silky feathering; brown or dark-brown eyes, never
| light; square muzzle with a distinct stop (not short-faced); tail carried level, never cocked up (the
| game shows the natural undocked tail — the AKC standard S343 says "Docked."). Colours (AKC S343):
| "Solid colors are black, liver or shades of red." plus parti-colours and roans with white and tan
| markings — never merle. Not a welfare breed (RKC Breed Watch Category 1). Dog trait `muzzle` (as the
| American Cocker Spaniel, so the two spaniels stay apart). Colour weights are unsourced →
| `verified => false`; golden weighted highest (the register portrait).
|
| Maltese (M5-R10-42, docs/research/dog-data maltese.appearance): "Of small size, longish body." (FCI 65,
| S346); "The Maltese is a toy dog covered from head to foot with a mantle of long, silky, white hair."
| (AKC S349); single coat without undercoat, long, straight and silky, never curly or woolly; very dark
| round eyes with black rims; black nose; drop ears heavily feathered; plumed tail carried over the back.
| Colour: "Colour: Pure white, a pale ivory tinge is permitted." (S346) — the only option. Not a welfare
| breed (RKC Breed Watch Category 1). The game draws a natural pet-length coat (no show wrap, no bows).
| One colour, the trait list is an unchecked draft → `verified => false`.
|
| Pug (M5-R10-43, docs/research/dog-data pug.appearance): "Decidedly square and cobby, it is "multum in
| parvo" shown in compactness of form, well-knit proportions and hardness of muscle." (FCI 253, S354);
| "Fine, smooth, soft, short and glossy, neither harsh, off standing nor woolly."; nose "Black with fairly
| large well opened nostrils."; muzzle "Relatively short, blunt, square, not upfaced."; tail "High-set,
| tightly curled over hip." Colours: "Silver, apricot, fawn or black." (S354) — fawn weighted highest (the
| register portrait). Welfare breed (flat-faced, RKC Breed Watch Category 3, S356: pinched nostrils,
| protruding eyes, excessive skin, too fat) → a moderate face with clearly open nostrils, light wrinkles,
| eyes that do not bulge, a fit body (dog trait `muzzle`, French Bulldog precedent). Never merle (the RKC
| does not register merle Pugs, S355). Colour weights are unsourced → `verified => false`.
|
| Rough Collie (M5-R10-44, docs/research/dog-data rough_collie.appearance): "Appears as a dog of great
| beauty, standing with impassive dignity, with no part out of proportion to whole." (FCI 156, S364); coat
| "Fits outline of body, very dense. Outer coat straight and harsh to touch," with a very abundant mane and
| frill and a smooth face (RKC standard S366); nose "Always black."; muzzle "End of smooth, well rounded muzzle
| blunt, never square."; ears semi-erect when alert; tail long, carried low with a slight upward swirl.
| Colours: sable, tricolour, blue merle ("Any other colour or combination of colours unacceptable.", S364),
| all with "typical white Collie markings" (S366) — sable and white weighted highest (the register portrait).
| Never white (AKC only), cream or a double merle. Not a welfare breed (Breed Watch Category 2 without a
| named point, S365). Colour weights are unsourced → `verified => false`.
|
| Mastiff (M5-R10-45, docs/research/dog-data mastiff.appearance): "The Mastiff is a large, massive,
| symmetrical dog with a well-knit frame." (AKC S373); head square in outline, body broad, deep and powerful
| (FCI 264, S371); coat "Short and close-lying, but coarse over neck and shoulders."; colours "Apricot, fawn
| or brindle." (S371, S373) with a dark muzzle, ears and nose (S373) — fawn weighted highest (the register
| portrait). Welfare (Kennel Club Category 3, PDSA S370; Breed Watch points via S375): moderate skin with
| only a lightly wrinkled forehead (the `skin` trait), eyelids in normal contact, short broad muzzle with
| open nostrils. Never excessive white. Colour weights are unsourced → `verified => false`.
|
| Shiba Inu (M5-R10-46, docs/research/dog-data shiba_inu.appearance): "Small-sized dog, well balanced, well
| boned with well developed muscles." (FCI 257, S377); "Outer coat harsh and straight, undercoat soft and
| dense; hair on tail slightly long and standing off."; ears "Relatively small, triangular, slightly inclining
| forward and firmly pricked."; eyes "Triangular, not too small, and dark brown in colour."; tail "Set on high,
| thick, carried vigorously curled or curved as a sickle". Colours shared by FCI (S377) and AKC (S380): red,
| black and tan, sesame — always with urajiro (cream-white underside, cheeks and muzzle sides); red weighted
| highest (the register portrait). Never cream, white or pinto (AKC serious fault). Breed Watch Category 1
| (S378) — not a welfare breed. Colour weights are unsourced → `verified => false`.
|
| Rhodesian Ridgeback (M5-R10-47, docs/research/dog-data rhodesian_ridgeback.appearance): "should represent a
| well balanced, strong, muscular, agile and active dog," (FCI 146, S385); "A distinctive feature is the ridge on
| the back formed by hair growing in opposite direction to the remainder of coat." (RKC standard, S388) — the
| ridge (with two identical crowns) is the dog trait `ridge`, always visible (ridgeless = disqualification).
| Coat "short and dense, sleek and glossy"; colours "Light wheaten to red wheaten." (FCI S385, RKC S388, AKC
| S389) — red wheaten weighted highest (the register portrait), then light wheaten. Never black, black and
| tan, brindle or ridgeless. Nose black with dark eyes (the portrait) — the standards also allow a brown nose
| with amber eyes. Breed Watch Category 1 (S387) — not a welfare breed. Colour weights are unsourced →
| `verified => false`.
|
| Papillon (M5-R10-48, docs/research/dog-data papillon.appearance): "Small de luxe Toy spaniel, of a normal and
| harmonious build, with long hair," (FCI 77, S396); the erect-eared variety — "Ears of the erect type are carried
| obliquely and move like the spread wings of a butterfly." (AKC-approved standard, S399) — the dog trait
| `ear_carriage`; the drop-eared Phalène is the other FCI variety and is not drawn. Colours "All colours are
| admitted on a coat with a white background." (S396) / "Always parti-color or white with patches of any
| color(s)." (S399), colour over both ears and eyes with a white blaze; white with red patches weighted highest
| (the register portrait). Never all white or without white (AKC disqualification). Breed Watch Category 1
| (S397) — not a welfare breed. Colour weights are unsourced → `verified => false`.
|
| Portuguese Water Dog (M5-R10-49, docs/research/dog-data portuguese_water_dog.appearance): "Robust, well balanced,
| of almost square shape, very strongly muscled on shoulders." (RKC breed standard, S403 — the FCI 37 PDF was not
| reachable); profuse single coat without undercoat, curly or loosely waved, in the standard's clip (muzzle and
| hindquarters clipped — the lion clip; dog trait `clip`). Colours "Black, white, various shades of brown, black and
| white, brown and white." / "Any other colour or combination of colours unacceptable." (S403); black weighted
| highest (the register portrait). Breed Watch Category 1 (S404) — not a welfare breed. Colour weights are
| unsourced → `verified => false`.
|
| Bichon Frise (M5-R10-50, docs/research/dog-data bichon_frise.appearance): "A cheerful little dog, with a
| lively gait and very loose, white corkscrew-curled hair." (FCI 215, S410); colour "Pure white." (S410) — RKC
| standard (S412) "White, but cream or apricot markings acceptable up to 18 months." → the adult game dog is pure
| white only (the register portrait). Drooping ears, very dark round eyes with black rims, round black nose, plume
| over the back. Breed Watch Category 1 (S411) — not a welfare breed. Colour weight unsourced → `verified => false`.
|
| Newfoundland (M5-R10-51, docs/research/dog-data newfoundland.appearance): "The Newfoundland is a
| sweet-dispositioned dog that acts neither dull nor ill-tempered." (AKC S421); large, deep-bodied, muscular;
| flat, water-resistant double coat; colours black, white and black (Landseer), brown (FCI 50, S420 "Color: Negro,
| blanco y negro y castaño."; AKC S421 also gray — never in the game, not FCI) — black weighted highest ("the
| traditional colour", S420; the register portrait). Welfare (Kennel Club Category 2, PDSA S423; Breed Watch point
| "loose eyelids", S425): small, deep-set eyes with closely fitting eyelids (`eye_color`) and a moderate, deep muzzle
| (dog trait `muzzle`). Colour weights are unsourced → `verified => false`.
|
| Dalmatian (M5-R10-52, docs/research/dog-data dalmatian.appearance): "The body is rectangular, strong, muscled and
| distinctively spotted." (FCI 153, S428); short, shiny, hard dense coat; pure white ground colour with black spots or
| liver (brown) spots (S428 "Black spotted variety with black spots, liver spotted variety with brown spots.") —
| black-spotted weighted highest (the register portrait); never patches, tricolour, lemon / blue spots or blue eyes
| (disqualifying, S428). Breed Watch Category 1 (S429) — not a welfare breed. Colour weights unsourced → `verified => false`.
|
|
| British Shorthair (M5-R10-14, docs/research/cat-data british_shorthair.appearance):
| large to medium, muscular, cobby, broad chest; round, massive head with a broad
| skull; short, broad, straight nose with a slight indentation — "not a stop as in the
| Exotic" (no flat face); strong chin; small, slightly rounded ears set wide apart;
| large, round, wide-open eyes; short, dense, crisp coat with a good undercoat; short,
| thick tail rounded at the tip (FIFe BLH/BSH 01.07.2026, C26). Slow to mature, full
| size at three to five years (CFA C27, VCA C28). Colours from the FIFe varieties only; blue is the
| best-known colour (C27, C28) and weighted highest (portrait default). Colour weights
| are unsourced → `verified => false`.
|
| Ragdoll (M5-R10-15, docs/research/cat-data ragdoll.appearance): a firm, large cat with
| a long, muscular body of medium to strong bone, broad chest; medium, broad modified
| wedge head, straight nose with a slight curve, broad rounded muzzle, well-developed
| chin; medium ears broad at the base with rounded tips, slight forward tilt; large
| oval eyes, BLUE ONLY (any other eye colour disqualifies); large round paws tufted
| between the toes; long bushy tail; medium-long, soft, silky coat lying with the body,
| longest at the neck (bib) (FIFe RAG 01.01.2026, C29). Patterns colourpoint, mitted,
| bicolour in seal, blue, chocolate, lilac, red, cream (C29). Slow to mature: full coat
| colour at two years, full size at four (CFA C30). Seal colourpoint is weighted
| highest (portrait default); colour / pattern weights are unsourced → `verified => false`.
|
| Siberian (M5-R10-16, docs/research/cat-data siberian_cat.appearance): medium to large,
| rectangular, well boned and muscled body, powerful neck, broad chest; head a little
| longer than broad, softly rounded, massive; medium-length broad nose with a slight
| indentation, no stop; medium ears, well open at the base, rounded tips with tufts;
| large, slightly oval eyes set wide apart — any colour except blue (blue only with
| white); large round paws tufted between the toes; thick, medium-length tail with a
| rounded tip; semi-long, very dense, water-repellent coat, frill and knickerbockers in
| winter (FIFe SIB / NEM 01.07.2026, C33). All colours with or without white EXCEPT
| pointed (= the Neva Masquerade), chocolate, lilac, cinnamon and fawn (C33). Slow to
| mature: full development at four to five years (GCCF C34). Brown (black) tabby is
| weighted highest (portrait default); colour weights are unsourced → `verified => false`.
|
| Norwegian Forest Cat (M5-R10-17, docs/research/cat-data norwegian_forest_cat.appearance):
| medium to large, solid, elongated body with massive bone; head an equilateral
| triangle with a long, straight profile (never a curved profile or Roman nose),
| slightly rounded forehead, firm chin; very large ears, broad at the base, set high;
| large, open, slightly oval eyes (never round), colour matching the coat; long
| muscular legs, hind legs higher than the front; broad round paws tufted between the
| toes; very long bushy tail, at least as long as the body; woolly undercoat under a
| long, glossy, firm, water-repellent top coat (WCF NFO 2021-12-08, C36). All colours
| with any amount of white EXCEPT pointed, chocolate, lilac, cinnamon and fawn (FIFe via
| World Cat Congress, C40). Slow to mature: fully grown at four to five years (GCCF C37).
| Brown (black) mackerel tabby with white is weighted highest (portrait default); a
| brown cat is always tabby (never plain brown — would read as chocolate); colour
| weights are unsourced → `verified => false`.
|
| Bengal (M5-R10-18, docs/research/cat-data bengal.appearance): medium to large, sleek
| and very muscular, with a wild look; long, substantial body with robust bone,
| hindquarters slightly higher than the shoulders; broad modified wedge head with
| rounded contours, slightly small for the body, high cheekbones, full broad muzzle with
| large whisker pads; medium to small ears, wide at the base, rounded tips, set wide
| apart and tilted forward (never tufted); large oval eyes, not bulging; thick tail
| tapering to a rounded tip, medium length; short, dense, unusually soft, close-lying
| coat (WCF BEN 2024-08-01, C42; FIFe BEN 01.01.2026, C43). Spotted (rosettes preferred)
| or marbled, belly spotted, in brown (black), seal sepia / seal mink, snow and their
| silver forms (FIFe via World Cat Congress, C47); never white patches or medallions,
| never vertical tabby stripes (C42). Snow (seal lynx point) has blue eyes (C42). Fully
| grown at up to two years (VCA C46). Brown spotted with rosettes is weighted highest
| (portrait default); colour weights are unsourced → `verified => false`. A registered,
| domestic Bengal — never drawn as a wild cat (no leopard cat proportions).
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
                // Never "white … with white patches".
                ['value' => 'with white patches', 'only_with' => ['coat_color' => ['fawn', 'black', 'havana brown', 'tobacco', 'reddish brown']]],
                'with tan markings',
            ],
            'ear_carriage' => ['drop ears falling along the cheeks with a slight fold'],
            'eye_color' => ['large, dark brown, almond-shaped'],
            'tail' => ['tail carried high and curled over the back, richly feathered'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-11 (docs/research/dog-data/data.json west_highland_white_terrier.appearance;
    // runbook rules 2026-10-10: standard colour only — white; healthy skin and coat).
    'west_highland_white_terrier' => [
        'display_name' => 'West Highland White Terrier',
        // One standard colour; the trait list itself is an unchecked draft → false.
        'verified' => false,
        'source' => 'FCI-Standard N° 85 (S142, https://www.fci.be/Nomenclature/Standards/085g03-en.pdf), RKC breed standard (S145), RKC Breed Watch (S146), RKC breed page (S144) — for the traits listed in `sources`.',
        'sources' => [
            'size' => 'S144 "Size: Small"; S142 "Height at the withers: Approximately 28 cm."',
            'build' => 'S142 "Strongly built; deep in chest and back ribs; level back and powerful quarters on muscular legs"',
            'coat_length' => 'S142 "Double coated. Outer coat consists of harsh hair, about 5 cm long, free from any curl."',
            'coat_color' => 'S145 "White." "Any other colour or combination of colours unacceptable."',
            'coat_condition' => 'S146 "Signs of dermatitis irritation and/or hair loss or scarring from previous dermatitis" (point of concern); S145 "Free from obvious skin problems."',
            'ear_carriage' => 'S142 "Small, erect and carried firmly, terminating in sharp point, set neither too wide nor too close."',
            'eye_color' => 'S142 "Set wide apart, medium in size, not full, as dark as possible"; "set well under heavy eyebrows"',
            'tail' => 'S142 "13 -15 cm long, covered with harsh hair, no feathering, as straight as possible,"; "carried jauntily, not gay or carried over back."',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'strongly built, deep chest, level back, muscular legs', 'weight' => 3], 'compact and sturdy'],
            'coat_length' => [
                ['value' => 'harsh, straight double coat about 5 cm long, no curl', 'weight' => 3],
                'harsh, straight double coat, slightly fuller around the head',
            ],
            // White is the only standard colour (S142, S145).
            'coat_color' => ['pure white'],
            // Welfare rule (S146): healthy skin, never red, irritated or bald patches.
            'coat_condition' => ['healthy skin and a full, clean coat'],
            'ear_carriage' => ['small, erect, pointed ears'],
            'eye_color' => ['dark, set wide apart under heavy eyebrows'],
            'tail' => ['straight tail of medium length carried jauntily, not over the back'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_condition', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-12 (docs/research/dog-data/data.json bernese_mountain_dog.appearance;
    // runbook rules 2026-10-10: standard colour only — tricolour).
    'bernese_mountain_dog' => [
        'display_name' => 'Bernese Mountain Dog',
        // One standard colour; the trait list itself is an unchecked draft → false.
        'verified' => false,
        'source' => 'FCI-Standard N° 45 (S150, https://www.fci.be/Nomenclature/Standards/045g02-en.pdf), RKC breed standard (S153), RKC breed page (S152) — for the traits listed in `sources`.',
        'sources' => [
            'size' => 'S152 "Size: Large"; S150 "Height at withers: for dogs: 64-70 cm," "for bitches: 58-66 cm,"',
            'build' => 'S150 "Longhaired, tricoloured, strong and agile working dog, of above medium size, with sturdily built limbs;"',
            'coat_length' => 'S150 "Long, shining, straight or slightly wavy."',
            'coat_color' => 'S150 "Jet black main colour with rich tan markings on the cheeks, above the eyes, on all four legs and on the chest,"; S153 "Any other colour or combination of colours unacceptable."',
            'ear_carriage' => 'S150 "Medium-sized, set high, triangular in shape, slightly rounded at the tips, in repose hanging flat and close to the head."',
            'eye_color' => 'S150 "Dark brown, almond-shaped, with close fitting eyelids."',
            'tail' => 'S150 "Bushy, reaching at least to the hocks; hanging straight down when at rest;"',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'strong, sturdy and agile, well-boned, balanced', 'weight' => 3], 'powerful but not heavy, harmonious'],
            'coat_length' => [
                ['value' => 'long, shining, straight coat', 'weight' => 3],
                'long, shining, slightly wavy coat',
            ],
            // Tricolour is the only standard colour (S150, S153).
            'coat_color' => ['tricolour: jet black with rich tan on the cheeks, above the eyes, on the legs and chest, a symmetrical white blaze, white muzzle band and white chest'],
            'ear_carriage' => ['medium-sized triangular ears hanging flat'],
            'eye_color' => ['dark brown, almond-shaped'],
            'tail' => ['bushy tail hanging down, reaching the hocks'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-13 (docs/research/dog-data/data.json siberian_husky.appearance; runbook rules
    // 2026-10-10: standard colours only — never merle or brindle; grey and white portrait).
    'siberian_husky' => [
        'display_name' => 'Siberian Husky',
        // Colour frequencies are in no source (siberian_husky.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 270 (S158, https://www.fci.be/Nomenclature/Standards/270g05-en.pdf), RKC breed standard (S161), RKC breed page (S160) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S160 "Size: Medium-Large"; S158 "Dogs: 53,5 - 60 cm." "Females: 50,5 - 56 cm."',
            'build' => 'S158 "The Siberian Husky is a medium-sized working dog, quick and light on his feet and free and graceful in action."; "Any appearance of excessive bone or weight should be penalized."',
            'coat_length' => 'S158 "The coat of the Siberian Husky is double and medium in length," "never so long as to obscure the cleancut outline of the dog."',
            'coat_color' => 'S158 "All ranges of the allowable colours which are black, grey, agouti, sable, red and white." "Merle or Brindle patterns are not allowable and are to be disqualified." (weights unsourced)',
            'ear_carriage' => 'S158 "They are thick, well furred, slightly arched at the back," "and strongly erect, with slightly rounded tips pointing straight up."; S161 "triangular in shape"',
            'eye_color' => 'S158 "Eyes may be brown or blue in colour; one of each or particoloured are acceptable."; S161 "Almond-shaped, moderately spaced and set obliquely."',
            'tail' => 'S158 "The well furred tail of fox-brush shape is set on just below the level of the topline," "usually carried over the back in a graceful sickle curve when the dog is at attention."',
        ],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'moderately compact, athletic, light on its feet, never heavy', 'weight' => 3], 'lean, graceful working build'],
            'coat_length' => ['double, medium-length, well-furred coat'],
            // Standard colours only (S158, S161); grey and white highest → the register portrait.
            'coat_color' => [
                ['value' => 'grey and white with a white face mask', 'weight' => 4],
                ['value' => 'black and white with a white face mask', 'weight' => 3],
                ['value' => 'red and white with a white face mask', 'weight' => 2],
                'agouti',
                'sable and white',
                'pure white',
            ],
            'ear_carriage' => ['triangular, thick, well-furred, strongly erect'],
            'eye_color' => [['value' => 'blue, almond-shaped', 'weight' => 3], ['value' => 'brown, almond-shaped', 'weight' => 2], 'one blue and one brown, almond-shaped'],
            'tail' => ['well-furred fox-brush tail'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-19 (docs/research/dog-data/data.json rottweiler.appearance; runbook rules
    // 2026-10-11: black and tan only, natural tail, moderate muzzle, sound hind legs).
    'rottweiler' => [
        'display_name' => 'Rottweiler',
        // One standard colour; shade wording and weights are unsourced (rottweiler.appearance.colour_weights) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 147 (S166, https://fci.be/Nomenclature/Standards/147g02-en.pdf), AKC standard via the American Rottweiler Club (S170), RKC breed page (S168), RKC Breed Watch (S173) — for the traits listed in `sources`. Weights are unsourced.',
        'sources' => [
            'size' => 'S168 "Size: Large"; S166 "Height at withers: For males is 61 - 68 cm." / "For bitches is 56 - 63 cm."',
            'build' => 'S166 "The Rottweiler is a medium to large size, stalwart dog, neither heavy nor light and neither leggy nor weedy."; S173 "Incorrect hind conformation and/or poor rear movement" (welfare rule: level back, sound hind legs)',
            'coat_length' => 'S166 "The top coat is of medium length, coarse, dense and flat." "The undercoat must not show through the top coat."',
            'coat_color' => 'S166 "Black with clearly defined markings of a rich tan on the cheeks, muzzle, throat, chest and legs," (also over both eyes and under the tail); fault "White markings."; S170 rust to mahogany markings (paraphrase)',
            'ear_carriage' => 'S166 "Medium-sized, pendant, triangular, wide apart, set on high."',
            'eye_color' => 'S166 "Of medium size, almond-shaped, dark brown in colour."',
            'tail' => 'S166 natural tail: strong, level with the topline, carried in a light curve when alert, hanging at ease (paraphrase)',
            'muzzle' => 'S166 "The ratio between the length of the muzzle and the length of the skull is about 1 to 1,5."; S173 "Excessively short muzzle" (welfare rule: moderate, never short)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'powerful and compact but not heavy, level back, sound well-angled hind legs', 'weight' => 3], 'athletic, muscular, standing square'],
            'coat_length' => ['short-to-medium, coarse, dense, flat-lying coat'],
            // One standard colour (S166, S170); the shade of tan varies within the standard wording.
            'coat_color' => [
                ['value' => 'black with clearly defined rich tan markings on the cheeks, muzzle, throat, chest, legs and above the eyes', 'weight' => 4],
                'black with clearly defined mahogany markings on the cheeks, muzzle, throat, chest, legs and above the eyes',
            ],
            'ear_carriage' => ['medium-sized, triangular, pendant, set high'],
            'eye_color' => ['dark brown, almond-shaped'],
            'tail' => ['natural, undocked tail, level with the back, hanging at rest'],
            // Welfare rule (S173): never an excessively short muzzle.
            'muzzle' => ['moderate muzzle, clearly shorter than the skull but never short, with a broad black nose'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-20 (docs/research/dog-data/data.json bulldog.appearance; runbook rules
    // 2026-10-11: standard colours only, moderate face with open nostrils, straight tail).
    'bulldog' => [
        'display_name' => 'Bulldog',
        // Colour frequencies are in no source (bulldog.appearance.colour_weights UNSOURCED) → all weights are a draft.
        'verified' => false,
        'source' => 'RKC breed standard (S178, https://thekennelclub.org.uk/breed-standards/utility/bulldog), FCI-Standard N° 149 (S175), AKC standard via the Bulldog Club of America (S179), RKC breed page (S177), RKC Breed Watch (S182) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S177 "Size: Medium"; PDSA S180 the same',
            'build' => 'S178 "Smooth-coated. Fairly thick set, rather low in stature, broad, powerful and compact."; S182 "Unsound movement" (welfare rule: sound, not exaggerated)',
            'coat_length' => 'S175 "Fine texture, short, close and smooth (hard only from shortness and closeness, not wiry)."',
            'coat_color' => 'S178 "Whole colours are red, fawn, white." "Red or fawn may have white markings i.e. red and white, fawn and white." "Any other colour or pattern highly undesirable" (weights unsourced; never black / black and tan S175, merle S179)',
            'coat_pattern' => 'S178 "Brindle – solid background colour marked with darker stripes: red brindle, fawn brindle." "Pied – white background with markings of any of the above listed colours."',
            'ear_carriage' => 'S178 "Small and thin." / "\'Rose ear\' correct, i.e. folding inwards back, upper or front inner edge curving outwards and backwards,"',
            'eye_color' => 'S178 "Round, of moderate size, neither sunken nor prominent, in colour very dark – almost black –"',
            'tail' => 'S175 "Moderate in length - rather short than long - thick at root, tapering quickly to a fine point."; S182 "Lack of tail, screw tail, inverted tail or a tight tail" (welfare rule: natural straight tail)',
            'muzzle' => 'S178 "Nostrils large wide and open, with well-defined vertical straight line between." "Pinched nostrils and heavy over nose roll are unacceptable"; S182 "Heavy overnose wrinkle (roll)" (welfare rule: moderate face, light wrinkle)',
        ],
        'traits' => [
            'size' => ['medium'],
            'build' => [['value' => 'thick-set, broad and compact but sound and not exaggerated, standing square', 'weight' => 3], 'low, powerful and muscular, moving freely'],
            'coat_length' => ['fine, short, close and smooth'],
            // Standard colours only (S178); red highest → the register portrait is red and white.
            'coat_color' => [
                ['value' => 'red', 'weight' => 4],
                ['value' => 'fawn', 'weight' => 3],
                'fawn brindle',
            ],
            'coat_pattern' => [
                ['value' => 'with white markings on the face, chest and legs', 'weight' => 4],
                'solid with a black mask',
                'pied — mostly white with large patches of that colour',
            ],
            'ear_carriage' => ['small, thin "rose" ears folded back, set high'],
            'eye_color' => ['very dark, round, moderate size, no white showing'],
            'tail' => ['natural, straight, low-set tail carried downward'],
            // Welfare rule (S182): never pinched nostrils or a heavy overnose roll.
            'muzzle' => ['short broad muzzle that is not exaggerated, with a black nose, large wide-open nostrils and only a light wrinkle that never covers the eyes or nose'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-21 (docs/research/dog-data/data.json german_shorthaired_pointer.appearance; runbook
    // rule: standard colours only; natural tail = Claude's proposal, open for David).
    'german_shorthaired_pointer' => [
        'display_name' => 'German Shorthaired Pointer',
        // Colour frequencies are in no source (german_shorthaired_pointer.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'AKC standard via the German Shorthaired Pointer Club of America (S186, https://www.gspca.org/?p=240302), FCI-Standard N° 119 (S184), RKC breed page (S187), PDSA (S188) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S188 "Size: Large" (PDSA key facts); S186 "Size – height of dogs, measured at the withers, 23 to 25 inches."',
            'build' => 'S184 "A dog of noble and balanced appearance, the conformation of which ensures strength, endurance and speed."',
            'coat_length' => 'S184 "Short and dense, rough and hard to the touch."',
            'coat_color' => 'S186 "The coat may be of solid liver or a combination of liver and white such as liver and white ticked" / "Or the coat may be of solid black or any combination of black and white such as black and white ticked"; "Any other color or color combination is a disqualification." (weights unsourced; never tan / red / lemon / solid white, S186)',
            'ear_carriage' => 'S184 "Moderately long, set on high and broad, flat and without twisting hanging down close to the head."',
            'eye_color' => 'S186 "The eye is almond shaped, not circular." "The preferred color is dark brown."',
            'tail' => 'S184 "About halfway docked for hunting purposes." — the game shows the natural, undocked tail (Claude\'s proposal 2026-10-11, open for David; appearance.tail notes)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'noble, athletic and balanced, lean and muscular, built for endurance and speed', 'weight' => 3], 'lean, deep-chested, standing alert'],
            'coat_length' => ['short, dense, flat-lying, rough and hard coat'],
            // Standard colours only (S186); liver roan with a solid liver head highest → the register portrait.
            'coat_color' => [
                ['value' => 'liver roan (liver and white ticked) with a solid liver head and liver patches', 'weight' => 4],
                ['value' => 'solid liver', 'weight' => 2],
                ['value' => 'liver and white ticked', 'weight' => 2],
                'black roan with a solid black head',
                'solid black',
            ],
            'ear_carriage' => ['broad, flat ears set high, hanging close to the head'],
            'eye_color' => ['dark brown, almond-shaped'],
            'tail' => ['natural, undocked tail carried level with the back'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-22 (docs/research/dog-data/data.json yorkshire_terrier.appearance; runbook
    // rules 2026-10-10: standard colour only).
    'yorkshire_terrier' => [
        'display_name' => 'Yorkshire Terrier',
        // One standard colour; the trait list itself is an unchecked draft → false.
        'verified' => false,
        'source' => 'FCI-Standard N° 86 (S190, https://dev.fci.be/Nomenclature/Standards/086g03-en.pdf), RKC breed standard (S193), RKC breed page (S192), PDSA (S194) — for the traits listed in `sources`.',
        'sources' => [
            'size' => 'S192 "Size: Small"; S190 "Weight: Up to 3,2 kgs."',
            'build' => 'S190 "Long-coated, coat hanging quite straight and evenly down each side, a parting extending from nose to end of tail."',
            'coat_length' => 'S190 "On body moderately long, perfectly straight (not wavy), glossy;" "fine silky texture, not woolly, must never impede movement."',
            'coat_color' => 'S190 "Dark steel blue (not silver blue), extending from occiput to root of tail, never mingled with fawn, bronze or dark hairs." "Hair on chest rich, bright tan."',
            'ear_carriage' => 'S190 "Small, V-shaped, carried erect, not too far apart, covered with short hair, colour very deep, rich tan."',
            'eye_color' => 'S190 "Medium, dark, sparkling, with sharp intelligent expression and placed to look directly forward."',
            'tail' => 'S190 "Previously customarily docked." — undocked: "Plenty of hair, darker blue in colour than rest of body, especially at end of tail." "As straight as possible."',
        ],
        'traits' => [
            'size' => ['tiny toy-sized'],
            'build' => [['value' => 'compact and upright, coat parted from nose to tail', 'weight' => 3], 'small, neat and alert'],
            'coat_length' => [
                ['value' => 'long, perfectly straight, glossy silky coat of natural pet length, not touching the floor', 'weight' => 3],
                'medium-long, straight, silky coat',
            ],
            // Dark steel blue and tan is the only standard colour (S190, S193).
            'coat_color' => ['dark steel blue body with rich bright tan head, chest and legs'],
            'ear_carriage' => ['small, erect, V-shaped tan ears'],
            'eye_color' => ['dark, sparkling, looking straight ahead'],
            'tail' => ['natural undocked tail with plenty of darker blue hair'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-23 (docs/research/dog-data/data.json cane_corso.appearance; runbook rules
    // 2026-10-10: standard colours only, moderate face; natural ears and tail per FCI 2023).
    'cane_corso' => [
        'display_name' => 'Cane Corso',
        // Colour frequencies are in no source (cane_corso.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 343 (S197, https://www.fci.be/Nomenclature/Standards/343g02-en.pdf), AKC standard via the Cane Corso Association of America (S200), PDSA (S199) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S199 "Size: Large" (PDSA key facts); S197 "Males: 64 cm – 68 cm." "Females: 60 cm – 64 cm."',
            'build' => 'S197 "Medium to large sized. Robust and sturdy dog, nevertheless with some elegance." "The dog is rectangular in outline and is slightly longer than tall."',
            'coat_length' => 'S197 "Hair: Short, shiny, very dense with a slight undercoat of vitreous texture."',
            'coat_color' => 'S197 "Black, lead-grey (blue), slate-grey (blue), light grey (blue), light fawn; dark fawn and stag red;" "dark wheat colour; black brindle, grey (blue) brindle" — "All colours not indicated in the standard; large white patches." disqualify; S200 disqualifies "Any color with tan pattern markings as seen in black-and-tan breeds." (weights unsourced)',
            'ear_carriage' => 'S197 "Ears are un-cropped."; S200 "If uncropped, they are medium size, triangular in shape, held tight to the cheeks"; ear cropping is illegal in the UK (S199)',
            'eye_color' => 'S197 "The colour of the iris is as dark as possible but according to the coat colour."',
            'tail' => 'S197 "Natural. Set on fairly high; very broad at the root."; tail docking is illegal in the UK (S199)',
            'muzzle' => 'S197 "Muzzle: Strong, square, noticeably shorter than the skull, ratio muzzle: skull approximately 1 : 2." "Nose: Black."; S199 eyelid problems (entropion, ectropion, cherry eye) → moderate face, no exaggerated loose skin (welfare rule)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'robust, sturdy and athletic with some elegance, slightly longer than tall', 'weight' => 3], 'muscular and agile, standing alert'],
            'coat_length' => ['short, shiny, very dense coat'],
            // FCI colours only (S197); solid black highest → the register portrait.
            'coat_color' => [
                ['value' => 'solid black', 'weight' => 4],
                ['value' => 'solid grey (blue)', 'weight' => 3],
                ['value' => 'black brindle', 'weight' => 2],
                ['value' => 'grey (blue) brindle', 'weight' => 2],
                ['value' => 'fawn with a black mask not beyond the eyes', 'weight' => 2],
                'stag red with a dark mask',
                'dark wheat',
            ],
            'ear_carriage' => ['natural uncropped medium triangular ears held close to the cheeks'],
            'eye_color' => ['dark, alert'],
            'tail' => ['natural undocked tail, broad at the root'],
            'muzzle' => ['strong square moderate muzzle with a black nose, tight lips and eyelids, no exaggerated loose skin'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-24 (docs/research/dog-data/data.json pembroke_welsh_corgi.appearance; runbook rules
    // 2026-10-10: standard colours only; natural undocked tail; moderate body length).
    'pembroke_welsh_corgi' => [
        'display_name' => 'Pembroke Welsh Corgi',
        // Colour frequencies are in no source (pembroke_welsh_corgi.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 39 (S202, https://fci.be/Nomenclature/Standards/039g01-en.pdf), RKC breed standard (S205), RKC breed page (S204), PDSA (S206) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S204 "Size: Small"; S202 "Approximately 25 – 30 cm."',
            'build' => 'S202 "Low set, strong, sturdily built, alert and active, giving impression of substance and stamina in small space."',
            'coat_length' => 'S202 "Medium length, straight with dense undercoat, never soft, wavy or wiry."',
            'coat_color' => 'S202 "Self colours in Red, Sable, Fawn, Black and Tan, with or without white markings on legs, brisket and neck." (weights unsourced)',
            'ear_carriage' => 'S202 "Pricked, medium sized, slightly rounded."',
            'eye_color' => 'S202 "Well set, round, medium size, brown, blending with colour of coat."',
            'tail' => 'S205 "Previously customarily docked short." undocked: "set in line with the topline."',
            'body' => 'S205 "Medium length, well-sprung ribs, not short coupled, slightly tapering, when viewed from above. Level topline."; precaution (not a welfare breed): PDSA S206 lists intervertebral disc disease → moderate length, enough ground clearance',
        ],
        'traits' => [
            'size' => ['small, low-set'],
            'build' => [['value' => 'strong, sturdily built, alert and active', 'weight' => 3], 'compact and sturdy'],
            'coat_length' => ['medium-length straight coat with a dense undercoat'],
            // Standard colours only (S202); red highest → the register portrait. Never merle or blue.
            'coat_color' => [
                ['value' => 'red with white markings on legs, chest and neck', 'weight' => 4],
                ['value' => 'sable with white markings', 'weight' => 3],
                ['value' => 'fawn with white markings', 'weight' => 2],
                'black and tan with white markings',
            ],
            'ear_carriage' => ['pricked, medium-sized, slightly rounded ears'],
            'eye_color' => ['round, brown'],
            'tail' => ['natural undocked tail set in line with the back'],
            'body' => ['body of moderate length with a level back and enough ground clearance, sturdy short legs'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'body'],
    ],
    // M5-R10-25 (docs/research/dog-data/data.json dobermann.appearance; runbook rules
    // 2026-10-10: standard colours only; natural ears and tail as in the FCI standard).
    'dobermann' => [
        'display_name' => 'Dobermann',
        // Colour frequencies are in no source (dobermann.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 143 (S207, https://fci.be/Nomenclature/Standards/143g02-en.pdf), RKC breed standard (S210), RKC breed page (S209) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S209 "Size: Large"; S207 "Height at the withers: Males: 68 – 72 cm."',
            'build' => 'S207 "The Dobermann is of medium size, strong and muscularly built."',
            'coat_length' => 'S207 "The hair is short, hard and dense. It lies tight and smooth and is equally distributed over the whole surface."',
            'coat_color' => 'S207 "The Dobermann is bred in two colour varieties: black or brown with rust red," (weights unsourced)',
            'ear_carriage' => 'S207 "The ears, are left natural and of an appropriate size;"; S210 "Small, neat, set high on head, dropped and lying close to the cheeks."',
            'eye_color' => 'S207 "Medium sized, oval and dark in colour. Lighter shades are permitted for brown dogs."',
            'tail' => 'S207 "The tail is left natural and is ideally carried high in a slight curve."',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'strong, muscular and elegant, square build', 'weight' => 3], 'athletic and elegant'],
            'coat_length' => ['short, hard, smooth, tight-lying shiny coat'],
            // Standard colours only (S207); black and rust highest → the register portrait. Never white markings.
            'coat_color' => [
                ['value' => 'black with clearly defined rust red tan markings', 'weight' => 3],
                'brown with clearly defined rust red tan markings',
            ],
            'ear_carriage' => ['natural uncropped ears, set high and dropped close to the cheeks'],
            'eye_color' => ['medium, oval, dark'],
            'tail' => ['natural undocked tail carried in a slight curve'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-26 (docs/research/dog-data/data.json boxer.appearance; runbook rules
    // 2026-10-10: standard colours only; natural ears and tail; welfare breed → moderate muzzle).
    'boxer' => [
        'display_name' => 'Boxer',
        // Colour frequencies are in no source (boxer.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'RKC breed standard (S217, https://thekennelclub.org.uk/breed-standards/working/boxer), FCI-Standard N° 144 (S213), RKC breed page (S215), RKC Breed Watch (S216) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S215 "Size: Large"; S217 "Height: dogs: 57-63 cms (22½-25 ins); bitches: 53-59 cms (21-23 ins)."',
            'build' => 'S217 "Great nobility, smooth-coated, medium-sized, square build, strong bone and evident, well developed muscles."',
            'coat_length' => 'S217 "Short, glossy, smooth and tight to body."',
            'coat_color' => 'S217 "Fawn or brindle." "White markings acceptable not exceeding one-third of ground colour." (weights unsourced)',
            'ear_carriage' => 'S217 "Moderate size, thin, set wide apart on highest part of skull lying flat and close to cheek in repose,"; FCI S213 natural ears',
            'eye_color' => 'S217 "Dark brown, forward looking, not too small, protruding or deeply set."',
            'tail' => 'S217 "Undocked: Set on high and carried gaily, of moderate thickness."',
            'muzzle' => 'S217 "Muzzle broad, deep and powerful, never narrow, pointed, short or shallow."; "Nose broad, black, slightly turned up, wide nostrils with well-defined line between."; S216 "Pinched nostrils", "Excessively short muzzle" (welfare rule: moderate, never extreme)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'square, athletic and muscular', 'weight' => 3], 'strong-boned and powerful'],
            'coat_length' => ['short, glossy, smooth coat tight to the body'],
            // Standard colours only (S217); fawn highest → the register portrait. White never over a third.
            'coat_color' => [
                ['value' => 'golden to deer-red fawn with a black mask', 'weight' => 3],
                'brindle (black stripes on fawn) with a black mask',
            ],
            'ear_carriage' => ['natural uncropped ears lying flat and close to the cheeks'],
            'eye_color' => ['dark brown'],
            'tail' => ['natural undocked tail set high'],
            // Welfare rule (S216, S220): never an extreme face — moderate muzzle, open nostrils.
            'muzzle' => ['broad, deep, moderate muzzle (never extremely short) with a black nose and wide open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-27 (docs/research/dog-data/data.json miniature_schnauzer.appearance; runbook rules
    // 2026-10-10: standard colours only — never a disqualifying one; natural ears and tail).
    'miniature_schnauzer' => [
        'display_name' => 'Miniature Schnauzer',
        // Colour frequencies are in no source (miniature_schnauzer.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 183 (S221, https://dev.fci.be/Nomenclature/Standards/183g02-en.pdf), RKC breed standard (S224, KUSA copy), AKC standard (S225, AMSC copy), RKC breed page (S223) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S223 "Size: Small"; S221 "Height at withers Dogs and bitches between 30 and 35 cm."',
            'build' => 'S221 "Small, strong, stocky rather than slim, rough coated, elegant."',
            'coat_length' => 'S221 "The coat should be wiry, harsh and dense."',
            'coat_color' => 'S221 "Pure black with black undercoat." "Pepper and Salt." "Black/Silver."; S225 "salt and pepper, black and silver and solid black." (white: FCI / RKC yes, AKC disqualifies — not drawn; weights unsourced)',
            'ear_carriage' => 'S221 "Drop ears, set high, V-shaped with inner edges lying close to the cheeks, evenly carried"',
            'eye_color' => 'S221 "Medium sized, oval, facing foreward, dark with lively expression."',
            'tail' => 'S221 "Natural; a sabre or sickle carriage is sought after."',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'sturdy, nearly square and compact', 'weight' => 3], 'small, strong and stocky'],
            'coat_length' => ['harsh, wiry, dense coat with bushy eyebrows and a beard'],
            // Standard colours only (S221, S225); pepper and salt highest → the register portrait. Never white.
            'coat_color' => [
                ['value' => 'pepper and salt (banded grey) with a dark facial mask', 'weight' => 3],
                'black and silver',
                'solid black',
            ],
            'ear_carriage' => ['natural uncropped V-shaped ears folding forward'],
            'eye_color' => ['dark'],
            'tail' => ['natural undocked tail carried high'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-28 (docs/research/dog-data/data.json shih_tzu.appearance; runbook rules
    // 2026-10-10: standard colours only — never merle; welfare breed → moderate face).
    'shih_tzu' => [
        'display_name' => 'Shih Tzu',
        // Colour frequencies are in no source (shih_tzu.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 208 (S229, https://dev.fci.be/Nomenclature/Standards/208g09-en.pdf), RKC breed standard (S232, KUSA copy), RKC breed page (S231), PDSA (S233), BVA (S236) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S231 "Size: Small"; S229 "Height at the withers: Not more than 27 cm"',
            'build' => 'S232 "Sturdy, abundantly, but not excessively, coated dog with distinctly arrogant carriage and \'chrysanthemum-like\' face."',
            'coat_length' => 'S229 "Hair: Outer coat long, dense, not curly, with moderate undercoat, not woolly."',
            'coat_color' => 'S232 "All colours permissible except merle, which is unacceptable."; S229 "white blaze on forehead and white tip to tail highly desirable in parti-colours" (weights unsourced)',
            'ear_carriage' => 'S229 "Ears: Large, with long leathers, carried drooping."',
            'eye_color' => 'S229 "Eyes: Large, dark, round, placed well apart but not prominent."',
            'tail' => 'S229 "TAIL: Heavily plumed carried gaily well over back. Set on high."',
            'muzzle' => 'S229 "Muzzle: Of ample width, square, short, not wrinkled; flat and hairy."; welfare rule (BVA S236, PDSA BOAS S233): drawn moderate with open nostrils',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'sturdy and compact', 'weight' => 3], 'small and sturdy with a proud carriage'],
            'coat_length' => ['long, dense, straight coat with long hair on the head'],
            // Parti-colours (S229, S232); gold and white highest → the register portrait. Never merle.
            'coat_color' => [
                ['value' => 'gold and white', 'weight' => 3],
                'black and white',
                'liver and white',
            ],
            'ear_carriage' => ['large drooping ears covered in long hair'],
            'eye_color' => ['dark, round, not prominent'],
            'tail' => ['heavily plumed tail carried over the back'],
            // Welfare rule: moderate, never an extreme flat face.
            'muzzle' => ['short square muzzle, moderate, with clearly open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-29 (docs/research/dog-data/data.json great_dane.appearance; runbook rules
    // 2026-10-10: colours both standards list — never merle; welfare (Breed Watch) → tight eyelids).
    'great_dane' => [
        'display_name' => 'Great Dane',
        // Colour frequencies are in no source (great_dane.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 235 (S239, https://WWW.FCI.BE/Nomenclature/Standards/235g02-en.pdf), RKC breed standard (S242), RKC breed page (S241), RKC Breed Watch (S244) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S241 "Size: Extra large"; S239 "Males: at least 80 cm, should not exceed 90 cm."',
            'build' => 'S239 "The Great Dane in his noble appearance combines a large, powerful well constructed body with pride, strength and elegance."; S244 "Incorrect hind conformation and/or poor rear movement" (welfare: a sound, natural build)',
            'coat_length' => 'S239 "Very short, dense, smooth and close lying, glossy."',
            'coat_color' => 'S239 "Fawn: Light gold fawn to deep gold fawn, evenly shaded throughout the body." / "Black mask desired."; RKC S242 brindles, fawns, blues, blacks, harlequins (weights unsourced)',
            'ear_carriage' => 'S239 "Naturally pendant set on high, but not lifted above skull or hanging low."',
            'eye_color' => 'S239 "Of medium size with lively friendly intelligent expression."; S242 "preferably dark"; S244 "Eyelids not in normal contact with the eyeball, they are turned inwards or outwards" (welfare: tight eyelids)',
            'tail' => 'S239 "Reaching to the hocks, must not be too long or too short."',
        ],
        'traits' => [
            'size' => ['very large'],
            'build' => [['value' => 'tall, powerful and elegant, sound and well balanced', 'weight' => 3], 'tall, muscular and noble'],
            'coat_length' => ['very short, smooth, glossy coat'],
            // Colours both standards share (S239, S242); fawn highest → the register portrait. Never merle.
            'coat_color' => [
                ['value' => 'fawn with a black mask', 'weight' => 3],
                ['value' => 'brindle with a black mask', 'weight' => 2],
                'harlequin (white with torn black patches)',
                'black',
                'steel blue',
            ],
            'ear_carriage' => ['natural uncropped ears, folded forward and set high'],
            // Welfare rule (Breed Watch S244): tight eyelids, never droopy or turned.
            'eye_color' => ['dark, medium-sized, with tight eyelids'],
            'tail' => ['natural tail reaching to the hocks, carried low'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-30 (docs/research/dog-data/data.json pomeranian.appearance; runbook rules
    // 2026-10-10: RKC whole colours the FCI allows — never merle, never white markings).
    'pomeranian' => [
        'display_name' => 'Pomeranian',
        // Colour frequencies are in no source (pomeranian.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'RKC breed standard (S249, KC standard hosted by KUSA), FCI-Standard N° 97 (S246, https://dev.fci.be/Nomenclature/Standards/097g05-en.pdf), RKC breed page (S247) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S247 "Size: Small"; S246 "German Toy Spitz/Pomeranian: 21 cm ± 3 cm."',
            'build' => 'S249 "Compact, short-coupled dog, well-knit in frame."',
            'coat_length' => 'S249 "Two coats, an undercoat and an outercoat." "very abundant round neck and fore part of shoulders and chest; forming frill, extending over shoulders."; S246 "Hair must not look like being modelled."',
            'coat_color' => 'S249 "Orange, which should be self-coloured and bright as possible." "Merles not permissible."; S246 "White, black, brown, orange, grey-shaded, other colours." (weights unsourced)',
            'ear_carriage' => 'S249 "Relatively small, not set too wide apart or too low down, but carried perfectly erect."',
            'eye_color' => 'S249 "Medium size, slightly oval, not full or set too wide apart; bright, dark, and showing great intelligence."',
            'tail' => 'S249 "High-set, turned over back and carried flat and straight, profusely covered with long, harsh, spreading hair."',
        ],
        'traits' => [
            'size' => ['tiny toy-sized'],
            'build' => [['value' => 'compact and short-backed, with a fox-like head and a fine muzzle', 'weight' => 3], 'small, dainty and lively'],
            'coat_length' => [
                ['value' => 'profuse natural double coat with a soft fluffy undercoat, a long straight outer coat and a full frill round the neck and chest, untrimmed', 'weight' => 3],
                'thick fluffy double coat with a frill round the neck, untrimmed',
            ],
            // RKC whole colours (S249) the FCI allows (S246, cream as 'other colours'); orange highest → the register portrait.
            // Never merle, never white markings on a coloured dog.
            'coat_color' => [
                ['value' => 'bright self-coloured orange', 'weight' => 3],
                ['value' => 'cream', 'weight' => 2],
                'white',
                'black',
                'brown',
            ],
            'ear_carriage' => ['small erect ears set high'],
            'eye_color' => ['dark, medium-sized, slightly oval'],
            'tail' => ['plumed tail set high, turned over the back and carried flat'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-31 (docs/research/dog-data/data.json boston_terrier.appearance; runbook rules
    // 2026-10-10: standard colours only, always with white markings; welfare breed → moderate muzzle,
    // open nostrils; natural ears and tail).
    'boston_terrier' => [
        'display_name' => 'Boston Terrier',
        // Colour frequencies are in no source (boston_terrier.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 140 (S254), RKC breed standard (S257, KUSA copy S258), RKC breed page (S255), PDSA (S259), RKC / University of Cambridge BOAS research (S261) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S255 "Size: Small"; PDSA S259 the same',
            'build' => 'S254 "The Boston Terrier is a lively, highly intelligent, smooth coated, short-headed, compactly built,"; FCI fault "Blocky or chunky in appearance."',
            'coat_length' => 'S254 "Hair: Short, smooth, bright and fine in texture."',
            'coat_color' => 'S254 "Colour: Brindle, seal or black with white markings. Brindle is preferred only if all other qualities are equal." (weights unsourced; never solid colours, grey or liver — FCI disqualifying)',
            'coat_pattern' => 'S254 "Required markings: White muzzle band, white blaze between the eyes, white forechest."',
            'ear_carriage' => 'S254 "Small, carried erect, either natural or cropped to conform to the shape of the head" (drawn natural — never cropped)',
            'eye_color' => 'S254 "Wide apart, large, round and dark in colour."; RKC S257 overly prominent eyes highly undesirable',
            'tail' => 'S254 "Set-on low, short, fine and tapering, straight or screw and must not be carried above the horizontal."; FCI disqualifying "Docked tail."',
            'muzzle' => 'S254 "Black and wide, with a well defined line between the nostrils." muzzle "free from wrinkles"; fault "Pinched nostrils."; RKC S257 "Nostrils open."; welfare rule (BOAS S261, BVA S92): drawn moderate with open nostrils',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'compact, well balanced and lively, never blocky', 'weight' => 3], 'small, sturdy and square'],
            'coat_length' => ['short, smooth, bright and fine'],
            // Standard colours only, always with white (S254, S257); brindle and white highest → the register portrait.
            // Never solid, grey / blue, liver, fawn or merle.
            'coat_color' => [
                ['value' => 'brindle and white', 'weight' => 45],
                ['value' => 'black and white', 'weight' => 30],
                ['value' => 'seal and white', 'weight' => 25],
            ],
            'coat_pattern' => ['with a white muzzle band, a white blaze between the eyes and a white forechest'],
            'ear_carriage' => ['small, natural, uncropped and erect'],
            'eye_color' => ['dark, large and round, set wide apart, not bulging'],
            'tail' => ['short, natural, undocked and straight, carried low'],
            // Welfare rule: moderate, never an extreme flat face.
            'muzzle' => ['moderate short square muzzle free from wrinkles, with a black wide nose and clearly open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-32 (docs/research/dog-data/data.json miniature_american_shepherd.appearance; runbook rules
    // 2026-10-10: standard colours only — merle is standard for this breed; natural undocked tail).
    'miniature_american_shepherd' => [
        'display_name' => 'Miniature American Shepherd',
        // Colour frequencies are in no source (miniature_american_shepherd.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 367 (S265), AKC standard (S266), PetMD (S269) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S265 "The Miniature American Shepherd is a small size herding dog that originated in the United States."; males 35.5–46 cm, females 33–43.5 cm',
            'build' => 'S265 slightly longer than tall, moderate bone, athletic (paraphrase as returned); PetMD S269 "They are athletic and have a lot of energy"',
            'coat_length' => 'S265 "Hair is of medium texture, straight to wavy, weather resistant, and of medium length."',
            'coat_color' => 'S265 "With no order of preference, the recognized colors are black, blue merle, red, liver and red or liver merle." (weights unsourced)',
            'coat_pattern' => 'S265 "The hairline of a white collar does not exceed the withers at the skin."; "White on the head does not predominate, and the eyes are fully surrounded by color and pigment."; disqualifying white body splashes',
            'ear_carriage' => 'S265 "Are triangular, of moderate size, set high on the head."; "Severe Fault - Prick ears and ears that hang with no lift."',
            'eye_color' => 'S265 "Acceptable in all coat colors, one or both eyes may be brown, blue, hazel, amber or any color combination thereof"',
            'tail' => 'S265 "The undocked tail when at rest may hang in a slight curve." (the game draws a natural undocked tail — no docking)',
        ],
        'traits' => [
            'size' => ['small to medium-sized'],
            'build' => [['value' => 'athletic, slightly longer than tall, moderate bone', 'weight' => 3], 'compact, agile herding build'],
            'coat_length' => ['medium-length, straight to wavy, weather-resistant coat'],
            // Standard colours only (S265, S266); blue merle highest → the register portrait is blue merle.
            'coat_color' => [
                ['value' => 'blue merle', 'weight' => 35],
                ['value' => 'black', 'weight' => 25],
                ['value' => 'red merle', 'weight' => 20],
                ['value' => 'red', 'weight' => 20],
            ],
            'coat_pattern' => [
                ['value' => 'white collar, chest and legs with copper points, eyes fully surrounded by colour', 'weight' => 3],
                'small white blaze, white chest and feet with copper points, eyes fully surrounded by colour',
            ],
            'ear_carriage' => ['triangular, set high, folding forward'],
            'eye_color' => [['value' => 'brown', 'weight' => 3], 'blue', 'amber', 'hazel'],
            'tail' => ['natural undocked tail hanging in a slight curve'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-33 (docs/research/dog-data/data.json chihuahua.appearance; runbook rules 2026-10-10:
    // standard colours only — never merle; Smooth Coat; moderate, non-exaggerated head).
    'chihuahua' => [
        'display_name' => 'Chihuahua',
        // Colour frequencies are in no source (chihuahua.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'KC breed standard (S274, hosted by KUSA), FCI-Standard N° 218 (S273, Italian text hosted by ENCI), RKC breed page Smooth Coat (S275) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S275 "Size: Small"; S274 "Up to 2.7kg, with 1.8kg – 2.7kg preferred."',
            'build' => 'S274 "Small, dainty, compact."',
            'coat_length' => 'S274 "Smooth Coat: smooth, of soft texture, close and glossy, with undercoat and ruff permissible."',
            'coat_color' => 'S274 "Any colour or mixture of colours." "Never merle (dapple)."; S273 all colours except merle (weights unsourced)',
            'ear_carriage' => 'S274 "Large, flaring, set on at an angle of approximately 45, giving breadth between ears."',
            'eye_color' => 'S274 "Large, round, but not protruding; set well apart; …" "dark or ruby. Light eyes in light colours permissible."',
            'tail' => 'S274 "Medium length, set high, carried up and over back (sickle tail)."',
            'muzzle' => 'S274 "Well-rounded \'apple dome\' skull, cheeks and jaws lean, muzzle moderately short, slightly pointed. Definite stop." (the game draws a moderate dome, eyes not protruding)',
        ],
        'traits' => [
            'size' => ['tiny toy-sized'],
            'build' => [['value' => 'small, dainty and compact', 'weight' => 3], 'small, compact and alert'],
            'coat_length' => ['short, smooth, soft, close and glossy coat'],
            // Every colour but merle (S274 / S273); fawn highest → the register portrait.
            'coat_color' => [
                ['value' => 'fawn', 'weight' => 4],
                ['value' => 'cream', 'weight' => 2],
                ['value' => 'black and tan', 'weight' => 2],
                'chocolate',
                'white',
                'fawn and white',
            ],
            'ear_carriage' => ['large, flaring, erect ears set at about 45 degrees'],
            'eye_color' => [['value' => 'dark, large and round, not protruding', 'weight' => 3], 'ruby, large and round, not protruding'],
            'tail' => ['medium-length sickle tail carried up over the back'],
            // Welfare: moderate head, never an exaggerated dome or bulging eyes.
            'muzzle' => ['moderately rounded apple-dome head with a moderately short, slightly pointed muzzle'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-34 (docs/research/dog-data/data.json english_springer_spaniel.appearance; runbook rules
    // 2026-10-10: KC standard colours only — never roan / lemon / red / orange; natural tail).
    'english_springer_spaniel' => [
        'display_name' => 'English Springer Spaniel',
        // Colour frequencies are in no source (english_springer_spaniel.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'KC breed standard (S282, hosted by KUSA), AKC standard (S283), RKC breed page (S284), PDSA (S285) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S284 "Size: Medium"; S282 "Approximate height: 51cm (approx. 20")."',
            'build' => 'S282 general appearance (symmetrically built, compact, strong, merry, active; highest on leg of the British land spaniels)',
            'coat_length' => 'S282 "Close, straight, and weather resisting, never coarse. Moderate feathering on ears, forelegs, body, and hindquarters."',
            'coat_color' => 'S282 "Liver and white" "Black and white" "Either of these colours with tan markings." "Any other colour or combination of colours unacceptable." (weights unsourced)',
            'ear_carriage' => 'S282 "Lobular, good length and width, fairly close to head, set in line with eye. Nicely feathered."',
            'eye_color' => 'S282 "Medium size, almond-shaped, not prominent nor sunken, well set in (not showing haw), alert, kind expression." "Dark hazel."',
            'tail' => 'S282 "Previously customarily docked." "Set low, never carried above level of back. Well-feathered with lively action." (the game draws the natural tail)',
        ],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'compact, strong and athletic, fairly high on the leg', 'weight' => 3], 'symmetrical, merry and active'],
            'coat_length' => ['close, straight, medium-length coat with moderate feathering on the ears, legs, chest and belly'],
            // KC colours only (S282); liver and white highest → the register portrait.
            'coat_color' => [
                ['value' => 'liver and white', 'weight' => 4],
                ['value' => 'black and white', 'weight' => 3],
                'liver and white with tan markings',
                'black and white with tan markings',
            ],
            'ear_carriage' => ['long, lobular, well-feathered ears hanging close to the head'],
            'eye_color' => ['dark hazel, medium-sized and almond-shaped, with a kind, alert expression'],
            'tail' => ['natural, undocked, feathered tail carried low, never above the back'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],
    // M5-R10-35 (docs/research/dog-data/data.json shetland_sheepdog.appearance; runbook rules
    // 2026-10-10: FCI standard colours only — never brindle, white body patches or a double merle).
    'shetland_sheepdog' => [
        'display_name' => 'Shetland Sheepdog',
        // Colour frequencies are in no source (shetland_sheepdog.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI standard (S290), RKC breed page (S291), PDSA (S293), AKC (S294) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S291 "Size: Small"; S290 ideal height males 37 cm, females 35.5 cm',
            'build' => 'S290 "Small, long-haired working dog of great beauty, free from cloddiness and coarseness, action lithe and graceful."',
            'coat_length' => 'S290 "Double; outer coat of long hair, harsh-textured and straight." "Undercoat soft, short and close."',
            'coat_color' => 'S290 sable, tricolour, blue merle, black and white, black and tan; "Patches of white on body highly undesirable." "Any other colour or combination of colours unacceptable." (weights unsourced)',
            'ear_carriage' => 'S290 "Small, moderately wide at base, placed fairly close together on top of skull." carried semi-erect with tips falling forward when alert',
            'eye_color' => 'S290 "Medium size obliquely set, almond-shape with black rims." dark brown (blue allowed in merles)',
            'tail' => 'S290 "Set low; tapering bone reaches to at least hock; with abundant hair and slight upward sweep."',
            'muzzle' => 'S290 the head "a long, blunt wedge, tapering from ear to nose"; skull "Flat, moderately wide between ears"',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'small, lithe and graceful, well balanced', 'weight' => 3], 'small, elegant working build'],
            'coat_length' => ['long, harsh, straight double coat with an abundant mane and frill and a soft undercoat'],
            // FCI colours only (S290); sable and white highest → the register portrait.
            'coat_color' => [
                ['value' => 'sable and white (golden sable with a white collar, frill, chest, legs and tail tip)', 'weight' => 4],
                ['value' => 'tricolour (black with tan markings and a white collar, frill, chest, legs and tail tip)', 'weight' => 3],
                ['value' => 'blue merle (silvery blue marbled with black, with white and tan markings)', 'weight' => 2],
                'black and white',
                'black and tan',
            ],
            'ear_carriage' => ['small ears carried semi-erect with the tips folding forward'],
            'eye_color' => ['dark brown, medium-sized, almond-shaped and obliquely set, with a gentle expression'],
            'tail' => ['natural tail set low with abundant hair, carried low with a slight upward sweep'],
            'muzzle' => ['refined head shaped like a long, blunt wedge tapering from the ears to the nose, with a moderate stop'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-36 (docs/research/dog-data/data.json brittany.appearance; runbook rules
    // 2026-10-10: standard colours only — never a solid colour).
    'brittany' => [
        'display_name' => 'Brittany',
        // Colour frequencies are in no source (brittany.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'RKC standard (S299), FCI standard (S297), RKC breed page (S298), AKC standard (S300) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S298 "Size: Medium"; S299 height dogs 48–51 cm, bitches 47–50 cm',
            'build' => 'S299 "Workmanlike. Substance without heaviness. Moderately well boned."; S297 the smallest of the pointing dogs',
            'coat_length' => 'S299 "Body coat dense but fairly fine. Rather flat or slightly wavy."; S298 "Coat length: Medium"',
            'coat_color' => 'S299 orange/white, liver/white, black/white, tricolour, or roan of these; "Any other colour or combination of colours unacceptable." (weights unsourced)',
            'ear_carriage' => 'S299 "Triangular in shape, slightly rounded at tip." set high, lying flat',
            'eye_color' => 'S299 "Expressive, brown to dark, in harmony with coat colour."',
            'tail' => 'S299 "Naturally tailless, short or previously customarily docked."; S298 often naturally bob-tailed',
            'muzzle' => 'S299 "Skull slightly rounded as seen from front, side or above."; muzzle tapered but not snipy',
        ],
        'traits' => [
            'size' => ['medium'],
            'build' => [['value' => 'compact, short-backed, leggy and athletic, moderately boned', 'weight' => 3], 'workmanlike pointing-dog build with substance without heaviness'],
            'coat_length' => ['dense, fairly fine, flat or slightly wavy medium-length coat with light feathering'],
            // RKC / FCI colours only (S299, S297); orange and white highest → the register portrait.
            'coat_color' => [
                ['value' => 'orange and white (clear white with bright orange patches)', 'weight' => 4],
                ['value' => 'liver and white (clear white with liver-brown patches)', 'weight' => 3],
                ['value' => 'orange roan (white finely ticked with orange)', 'weight' => 2],
                'liver roan',
                'black and white',
                'tricolour (black and white with orange markings over the eyes, on the muzzle, legs and chest)',
            ],
            'ear_carriage' => ['high-set triangular ears, slightly rounded at the tip, lying flat against the cheeks'],
            'eye_color' => ['expressive brown to dark eyes in harmony with the coat colour'],
            'tail' => ['naturally short bob tail set high, carried horizontally'],
            'muzzle' => ['slightly rounded skull with a tapered muzzle that is not snipy and a moderate stop'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-37 (docs/research/dog-data/data.json american_cocker_spaniel.appearance; runbook rules
    // 2026-10-10: standard colours only — never merle, never blue eyes).
    'american_cocker_spaniel' => [
        'display_name' => 'American Cocker Spaniel',
        // Colour frequencies are in no source (american_cocker_spaniel.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'AKC standard (S307), FCI standard (S304), RKC breed page (S305) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S305 "Size: Small"; S307 ideal height 15 in (dogs) / 14 in (bitches)',
            'build' => 'S307 "The Cocker Spaniel is the smallest member of the Sporting Group."; S304 compact and sturdy',
            'coat_length' => 'S307 "The coat is silky, flat or slightly wavy and of a texture which permits easy care."; S305 "Coat length: Long"',
            'coat_color' => 'S307 black, ASCOB "Any solid color other than black, ranging from lightest cream to darkest red, including brown and brown with tan points.", parti-color (weights unsourced)',
            'ear_carriage' => 'S307 "Lobular, long, of fine leather, well feathered, and placed no higher than a line to the lower part of the eye."',
            'eye_color' => 'S307 "Eyeballs are round and full and look directly forward."; S304 dark brown, blue eyes disqualify',
            'tail' => 'S307 "The docked tail is set on and carried on a line with the topline of the back, or slightly higher." — the game shows a natural undocked tail',
            'muzzle' => 'S307 "The muzzle is broad and deep, with square even jaws."; skull "Rounded but not exaggerated with no tendency toward flatness."',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'compact, sturdy and well balanced, the smallest sporting spaniel', 'weight' => 3], 'small, compact spaniel build with a sloping topline'],
            'coat_length' => ['silky, flat or slightly wavy coat with moderate feathering on the ears, chest, belly and legs (pet length, not a floor-length show coat)'],
            // AKC / FCI varieties only (S307, S304); buff highest → the register portrait.
            'coat_color' => [
                ['value' => 'buff (solid light golden cream)', 'weight' => 4],
                ['value' => 'black (solid black)', 'weight' => 3],
                ['value' => 'black and tan (black with small tan points over the eyes, on the muzzle, chest and legs)', 'weight' => 2],
                'red (solid deep red)',
                'chocolate (solid brown)',
                'black and white parti-colour',
                'red and white parti-colour',
                'tricolour (black and white with tan points)',
            ],
            'ear_carriage' => ['long lobular ears set low, well feathered with silky hair'],
            'eye_color' => ['round, full, dark brown eyes looking directly forward'],
            'tail' => ['natural undocked tail carried level with the back'],
            'muzzle' => ['rounded skull with a pronounced stop and a broad, deep muzzle with square jaws'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-38 (docs/research/dog-data/data.json belgian_malinois.appearance; runbook rules
    // 2026-10-10: standard colours only — never black, brindle, merle or white patches).
    'belgian_malinois' => [
        'display_name' => 'Belgian Malinois',
        // Colour frequencies are in no source (belgian_malinois.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI standard No. 15 (S313), AKC standard (S315), RKC breed page (S314) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S314 "Size: Medium-Large"; S313 ideal height 62 cm (males) / 58 cm (females)',
            'build' => 'S315 "The Belgian Malinois is a well-balanced square dog, elegant in appearance"; S313 "combining elegance and power"',
            'coat_length' => 'S315 "The coat should be comparatively short, straight, hard enough to be weather resistant, with dense undercoat."',
            'coat_color' => 'S313 "Malinois: Only fawn with black overlay and with black mask."; S315 "rich fawn to mahogany, with black tips" (weights unsourced)',
            'ear_carriage' => 'S313 "Rather small, set high, distinctly triangular appearance."; S315 stiff, upright and vertical when alert',
            'eye_color' => 'S315 "They are brown, preferably dark brown, with black rimmed upper and lower eyelids."',
            'tail' => 'S313 "at rest carried down, with tip curved backwards at level of hock."; S315 a cropped or stumped tail is a disqualification',
            'muzzle' => 'S315 "The mask must be pronounced and tends to encompass the top and bottom lip,"',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'square, well-balanced, elegant and powerful herding-dog build with a proud carriage of head and neck', 'weight' => 3], 'lean, athletic, square shepherd build'],
            'coat_length' => ['short, straight, hard coat with a dense undercoat, very short on the head and legs, fuller at the neck and tail'],
            // FCI / AKC colours only (S313, S315); fawn highest → the register portrait.
            'coat_color' => [
                ['value' => 'fawn with black overlay (black-tipped hairs) and a pronounced black mask', 'weight' => 4],
                'mahogany with black overlay (black-tipped hairs) and a pronounced black mask',
            ],
            'ear_carriage' => ['rather small, high-set, distinctly triangular ears carried stiffly erect'],
            'eye_color' => ['medium, almond-shaped, dark brown eyes with black-rimmed eyelids'],
            'tail' => ['natural full tail reaching the hock, carried low at rest with the tip curved slightly back'],
            'muzzle' => ['black mask covering the muzzle, lips and eyelids; moderately long, tapering muzzle'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],

    // M5-R10-39 (docs/research/dog-data/data.json basset_hound.appearance; runbook rules
    // 2026-10-10: standard colours only — never merle; welfare breed → moderate, non-exaggerated features).
    'basset_hound' => [
        'display_name' => 'Basset Hound',
        // Colour frequencies are in no source (basset_hound.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 163 (S320, https://WWW.FCI.BE/Nomenclature/Standards/163g06-en.pdf), RKC breed page (S321), RKC Breed Watch (S322) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S321 "Size: Medium-Large"; S320 "Height at the withers: 33 - 38 cm."',
            'build' => 'S320 "Short-legged hound of considerable substance, well balanced, full of quality."; S322 "Inadequate ground clearance - overly deep body and/ or presence of excessive skin" (welfare: adequate ground clearance)',
            'coat_length' => 'S320 "Smooth, short and close without being too fine."',
            'coat_color' => 'S320 "Generally black, white and tan (tri-colour); lemon and white (bi-colour); but any recognized hound colour acceptable." (weights unsourced)',
            'ear_carriage' => 'S320 "Narrow throughout their length and curling well inwards; very supple, fine and velvety in texture."; S322 "Ears that extend more than slightly beyond end of muzzle" (welfare: moderate length)',
            'eye_color' => 'S320 "Lozenge-shaped, neither prominent nor deep-set, dark but may shade to mid-brown in light-coloured hounds."; S322 "Eyelids not in normal contact with the eyeball, they are turned inwards or outwards" (welfare: tight eyelids)',
            'tail' => 'S320 "Well set-on, rather long, strong at base, tapering, with moderate amount of coarse hair underneath."',
            'skin' => 'S320 "Supple and elastic without any exaggeration."; S322 "Excessive skin on the head, body and/or legs" (welfare: little loose skin)',
        ],
        'traits' => [
            'size' => ['short-legged, medium-sized'],
            'build' => [['value' => 'long, sturdy, well-balanced short-legged hound build with adequate ground clearance', 'weight' => 3], 'sturdy, well-boned low hound build, sound and balanced'],
            'coat_length' => ['smooth, short, close coat'],
            // FCI colours only (S320); tricolour highest → the register portrait. Never merle.
            'coat_color' => [
                ['value' => 'black, white and tan tricolour', 'weight' => 3],
                'lemon and white bicolour',
            ],
            'ear_carriage' => ['long, low-set, velvety ears curling inwards, of moderate length (not far beyond the muzzle)'],
            // Welfare rule (Breed Watch S322): tight eyelids, never droopy or turned.
            'eye_color' => ['dark, lozenge-shaped eyes with tight eyelids'],
            'tail' => ['rather long, tapering tail carried with a gentle curve'],
            'skin' => ['supple skin with only a little wrinkle at the brow and beside the eyes, no heavy folds'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'skin'],
    ],

    // M5-R10-40 (docs/research/dog-data/data.json hungarian_vizsla.appearance; runbook rules
    // 2026-10-10: standard colours only — never brown, pale yellow or parti-coloured; natural tail).
    'hungarian_vizsla' => [
        'display_name' => 'Hungarian Vizsla',
        // Colour frequencies are in no source (hungarian_vizsla.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 57 (S327, https://fci.be/Nomenclature/Standards/057g07-en.pdf), AKC standard (S331), RKC breed page (S328) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S328 "Size: Large"; S327 "Dogs: 58 - 64 cm" (medium-sized per the FCI)',
            'build' => 'S327 "Medium sized, elegant gun dog of noble appearance with short russet gold coat."',
            'coat_length' => 'S327 "Short and dense, should be coarse and hard at the touch."',
            'coat_color' => 'S327 "Various shades of russet gold and dark sandy gold (semmelgelb)." (weights unsourced; never dark brown, pale yellow or parti-coloured)',
            'ear_carriage' => 'S327 "Leathers set on at medium height, a little backwards."',
            'eye_color' => 'S327 "Slightly oval, of medium size."',
            'tail' => 'S327 "Set on slightly low, strong at the base, then tapering." — the game shows the natural tail reaching the hock (FCI where docking is prohibited)',
            'nose' => 'S327 "Well developed and broad with nostrils as wide as possible."; S331 "Nose self-colored. Any other color is faulty."',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'elegant, lean and muscular gundog of noble bearing, light and dry build', 'weight' => 3], 'athletic, moderately boned, standing alert'],
            'coat_length' => ['short, dense, smooth, close-lying coat without undercoat'],
            // FCI colours only (S327); russet gold highest → the register portrait.
            'coat_color' => [
                ['value' => 'russet gold', 'weight' => 3],
                'dark sandy gold',
            ],
            'ear_carriage' => ['thin ears of medium length set at medium height, hanging close to the cheeks with rounded tips'],
            'eye_color' => ['slightly oval brown eyes in harmony with the coat colour'],
            'tail' => ['natural, undocked tapering tail reaching the hock'],
            'nose' => ['broad, self-coloured reddish-brown nose (never black)'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
    ],

    // M5-R10-41 (docs/research/dog-data/data.json english_cocker_spaniel.appearance; runbook rules
    // 2026-10-10: standard colours only — never merle; natural tail).
    'english_cocker_spaniel' => [
        'display_name' => 'English Cocker Spaniel',
        // Colour frequencies are in no source (english_cocker_spaniel.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 5 (S336, https://WWW.FCI.BE/Nomenclature/Standards/005g08-fr.pdf), AKC standard (S343), RKC breed page (S337) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S337 "Size: Small"; S343 "Height at withers: males 16 to 17 inches; females 15 to 16 inches."',
            'build' => 'S336 "Chien de chasse gai et vigoureux, harmonieux et compact."',
            'coat_length' => 'S336 "Plat. Texture soyeuse, jamais « fil de fer » ni ondulé, pas trop abondant et jamais bouclé."; S337 "Coat length: Medium"',
            'coat_color' => 'S343 "Solid colors are black, liver or shades of red."; parti-colours "clearly marked, ticked or roaned" with white (weights unsourced; never merle)',
            'ear_carriage' => 'S336 "Les oreilles sont en forme de lobe, attachées bas, au niveau des yeux."',
            'eye_color' => 'S336 "Ils sont bruns ou brun foncé. Ils ne sont jamais clairs."',
            'tail' => 'S336 "Elle doit frétiller en action. Elle est portée horizontalement et jamais relevée." — the game shows the natural undocked tail',
            'muzzle' => 'S336 "Suffisamment large pour permettre la finesse du flair." (nose); square muzzle with a distinct stop, not short-faced',
        ],
        'traits' => [
            'size' => ['small to medium-sized'],
            'build' => [['value' => 'merry, sturdy, compact and well-balanced sporting spaniel', 'weight' => 3], 'compact, athletic spaniel build with a level topline and good bone'],
            'coat_length' => ['flat, silky coat of medium length with moderate feathering on the ears, chest, legs and belly (pet length, never curly or wiry)'],
            // AKC / FCI colours only (S343, S336); golden highest → the register portrait.
            'coat_color' => [
                ['value' => 'golden (solid golden red)', 'weight' => 4],
                ['value' => 'black (solid black)', 'weight' => 3],
                ['value' => 'liver (solid chocolate brown)', 'weight' => 2],
                'black and tan (black with tan markings over the eyes, on the muzzle, chest and legs)',
                ['value' => 'blue roan (black and white roaned)', 'weight' => 2],
                'orange roan (orange and white roaned)',
                'black and white parti-colour',
                'tricolour (black and white with tan markings)',
            ],
            'ear_carriage' => ['long lobular ears set low, level with the eyes, with long silky feathering'],
            'eye_color' => ['brown or dark brown eyes (never light), gentle merry expression'],
            'tail' => ['natural undocked tail of moderate length carried level, never cocked up'],
            'muzzle' => ['square muzzle with a distinct stop and a broad nose, moderate head, not short-faced'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],

    // M5-R10-42 (docs/research/dog-data/data.json maltese.appearance; runbook rules 2026-10-10:
    // standard colour only — pure white).
    'maltese' => [
        'display_name' => 'Maltese',
        // One standard colour; the trait list itself is an unchecked draft → false.
        'verified' => false,
        'source' => 'FCI-Standard N° 65 (S346, https://WWW.FCI.BE/medias/065g09-en-20160101-2139.pdf), AKC standard (S349), RKC Breeds A–Z (S347) — for the traits listed in `sources`.',
        'sources' => [
            'size' => 'S347 "Size: Small"; S346 "Weight: 3–4 kg."',
            'build' => 'S346 "Of small size, longish body."',
            'coat_length' => 'S346 "Hair: Dense, shiny, glossy - falling heavily and of a silky texture, very long on the whole of the body"; S349 "The coat is single, that is, without undercoat."',
            'coat_color' => 'S346 "Colour: Pure white, a pale ivory tinge is permitted."; S349 "Color, pure white."',
            'ear_carriage' => 'S349 "The drop ears are rather low set and heavily feathered with long hair that hangs close to the head."',
            'eye_color' => 'S349 "Eyes are set not too far apart; they are very dark and round, their black rims enhancing the gentle yet alert expression."',
            'tail' => 'S349 "A long-haired plume carried gracefully over the back, its tip lying to the side over the quarter."',
            'nose' => 'S349 "The nose is black."; "The muzzle is of medium length, fine and tapered but not snipy."',
        ],
        'traits' => [
            'size' => ['tiny toy-sized'],
            'build' => [['value' => 'small, slightly longer than tall, proud head carriage', 'weight' => 3], 'small and dainty with a longish body'],
            'coat_length' => [
                ['value' => 'long, straight, silky single coat falling like a mantle (natural pet length, never curly or woolly)', 'weight' => 3],
                'silky coat kept at a medium pet length, straight and without curls',
            ],
            // Pure white is the only standard colour (S346, S349).
            'coat_color' => ['pure white'],
            'ear_carriage' => ['drop ears heavily feathered with long hair hanging close to the head'],
            'eye_color' => ['very dark, round eyes with black rims'],
            'tail' => ['long-haired plume carried over the back, tip lying to the side'],
            'nose' => ['black nose, fine tapered muzzle of medium length (not short-faced)'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
    ],

    // M5-R10-43 (docs/research/dog-data/data.json pug.appearance; runbook rules 2026-10-10:
    // standard colours only, welfare breed → moderate face, `muzzle` trait).
    'pug' => [
        'display_name' => 'Pug',
        // Colour frequencies are in no source (pug.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 253 (S354, https://www.fci.be/Nomenclature/Standards/253g09-en.pdf), AKC standard (S358), RKC Breeds A–Z (S355), RKC Breed Watch (S356) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S355 "Size: Small"; S354 "Ideal weight 6,3 – 8,1 kg."',
            'build' => 'S354 "Decidedly square and cobby, it is "multum in parvo" shown in compactness of form, well-knit proportions and hardness of muscle."; Breed Watch S356 "Too fat"',
            'coat_length' => 'S354 "Fine, smooth, soft, short and glossy, neither harsh, off standing nor woolly."',
            'coat_color' => 'S354 "Silver, apricot, fawn or black." (weights unsourced; never merle — RKC S355)',
            'ear_carriage' => 'S354 "Thin, small, soft like black velvet." (rose or button, button preferred)',
            'eye_color' => 'S354 "Dark, relatively large, round in shape, soft and solicitous in expression, very lustrous, and when excited, full of fire."; Breed Watch S356 "Protruding eyes"',
            'tail' => 'S354 "High-set, tightly curled over hip. Double curl highly desirable."',
            'muzzle' => 'S354 "Black with fairly large well opened nostrils."; "Relatively short, blunt, square, not upfaced."; "Pinched nostrils and heavy over nose wrinkle is unacceptable."; Breed Watch S356 "Pinched nostrils", "Excessive skin on the head or body"; welfare rule (BVA S92): drawn moderate with open nostrils',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'square, cobby and compact with firm muscle, fit and not overweight', 'weight' => 3], 'small, sturdy and square'],
            'coat_length' => ['fine, smooth, short and glossy'],
            // Standard colours only (S354); fawn highest → the register portrait. Never merle, brindle, white or pied.
            'coat_color' => [
                ['value' => 'fawn with a black mask and black ears', 'weight' => 60],
                ['value' => 'black', 'weight' => 25],
                ['value' => 'apricot with a black mask and black ears', 'weight' => 10],
                ['value' => 'silver with a black mask and black ears', 'weight' => 5],
            ],
            'ear_carriage' => ['small, thin, soft rose or button ears'],
            'eye_color' => ['dark, round and lustrous, not bulging'],
            'tail' => ['tail tightly curled over the hip'],
            // Welfare rule: moderate, never an extreme flat face.
            'muzzle' => ['moderate short, blunt, square muzzle with a black nose, clearly open nostrils and light forehead wrinkles, no heavy over-nose wrinkle'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],

    // M5-R10-44 (docs/research/dog-data/data.json rough_collie.appearance; runbook rules 2026-10-10:
    // standard colours only — sable and white, tricolour, blue merle).
    'rough_collie' => [
        'display_name' => 'Rough Collie',
        // Colour frequencies are in no source (rough_collie.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 156 (S364, https://fci.be/Nomenclature/Standards/156g01-en.pdf), RKC breed standard (S366), RKC breed page (S365) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S365 "Size: Large"; S364 height males "56 – 61 cm"',
            'build' => 'S364 "Appears as a dog of great beauty, standing with impassive dignity, with no part out of proportion to whole."',
            'coat_length' => 'S364 "Fits outline of body, very dense. Outer coat straight and harsh to touch,"; S366 "mane and frill very abundant, mask and face smooth,"',
            'coat_color' => 'S364 "Any other colour or combination of colours unacceptable." (sable, tricolour, blue merle); S366 "All should carry typical white Collie markings to a greater or lesser degree." (weights unsourced; never a double merle — RKC S365)',
            'ear_carriage' => 'S364 "In repose carried thrown back, but on alert brought forward and carried semi-erect,"',
            'eye_color' => 'S364 "Medium size (never very small) set somewhat obliquely, of almond-shape and dark brown colour,"',
            'tail' => 'S364 "Long with bone reaching at least to hock joint. Carried low when quiet but with slight upward swirl at tip."',
            'nose' => 'S364 "Always black."; "End of smooth, well rounded muzzle blunt, never square."',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'balanced, dignified and elegant, no part out of proportion', 'weight' => 3], 'lithe and athletic with a deep chest'],
            'coat_length' => ['very dense, long, straight and harsh outer coat with a soft undercoat, very abundant mane and frill, smooth face'],
            // Standard colours only (S364, S366); sable and white highest → the register portrait.
            'coat_color' => [
                ['value' => 'sable and white', 'weight' => 60],
                ['value' => 'tricolour (black with tan markings and white)', 'weight' => 25],
                ['value' => 'blue merle and white', 'weight' => 15],
            ],
            'ear_carriage' => ['semi-erect ears with the tips tipping forward'],
            'eye_color' => ['medium, almond-shaped, dark brown eyes set somewhat obliquely'],
            'tail' => ['long tail carried low with a slight upward swirl at the tip, profusely haired'],
            'nose' => ['black nose, long, smooth, well-rounded blunt muzzle (never square, not short-faced)'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
    ],

    // M5-R10-45 (docs/research/dog-data/data.json mastiff.appearance; runbook rules 2026-10-10:
    // standard colours only — fawn, apricot, brindle, always with the dark mask; welfare: moderate skin).
    'mastiff' => [
        'display_name' => 'Mastiff',
        // Colour frequencies are in no source (mastiff.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 264 (S371, https://dev.fci.be/Nomenclature/Standards/264g02-en.pdf), AKC standard (S373), PDSA (S370), Breed Watch points via the breed club (S375) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S370 "Size | Large"; S373 "Dogs, minimum, 30 inches at the shoulder."',
            'build' => 'S373 "The Mastiff is a large, massive, symmetrical dog with a well-knit frame."; S371 "Body broad, deep, long, powerfully built, on legs wide apart and set."',
            'coat_length' => 'S371 "Short and close-lying, but coarse over neck and shoulders."',
            'coat_color' => 'S371 "Apricot, fawn or brindle."; S373 "Muzzle, ears, and nose must be dark in color, the blacker the better," (weights unsourced; never excessive white)',
            'ear_carriage' => 'S371 "Small, thin to touch, wide apart, set on at highest points of sides of skull,"',
            'eye_color' => 'S371 "Moderate size, wide apart. Colour hazel brown, darker the better, showing no haw."; S375 "Excessive amounts of loose facial skin with conformational defects of the upper and/or lower eyelids" (welfare: tight eyelids)',
            'tail' => 'S371 "Set on high, and reaching to hocks, or a little below them, wide at its root and tapering to end,"',
            'skin' => 'S371 "Skull broad between ears, forehead flat, but wrinkled when attention is excited."; S375 "Excessive wrinkle" (welfare: moderate skin)',
            'muzzle' => 'S371 "Short, broad under eyes, and keeping nearly parallel in width to end of nose;"; "Broad, with widely spreading nostrils when viewed from front," (welfare: open nostrils)',
        ],
        'traits' => [
            'size' => ['very large, massive'],
            'build' => [['value' => 'large, massive and symmetrical with a broad, deep, powerful body, sound and well balanced', 'weight' => 3], 'powerful, broad-chested and dignified, standing square on wide-set legs'],
            'coat_length' => ['short, dense, close-lying coat, slightly coarser over the neck and shoulders'],
            // Standard colours only (S371, S373); fawn highest → the register portrait.
            'coat_color' => [
                ['value' => 'fawn with a dark mask, dark ears and a black nose', 'weight' => 50],
                ['value' => 'apricot with a dark mask, dark ears and a black nose', 'weight' => 30],
                ['value' => 'brindle with a dark mask, dark ears and a black nose', 'weight' => 20],
            ],
            'ear_carriage' => ['small, thin, dark ears set wide apart, lying flat and close to the cheeks'],
            // Welfare (Breed Watch points via S375): eyelids in normal contact, no haw, no drooping.
            'eye_color' => ['moderate, wide-set hazel brown eyes with tight eyelids, no haw'],
            'tail' => ['natural tail set high, wide at the root and tapering, reaching the hocks'],
            'skin' => ['moderate skin with only a lightly wrinkled forehead, no heavy wrinkles or loose jowls'],
            'muzzle' => ['short, broad muzzle with a black nose and clearly open nostrils'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'skin', 'muzzle'],
    ],

    // M5-R10-46 (docs/research/dog-data/data.json shiba_inu.appearance; runbook rules 2026-10-10:
    // standard colours only — red, black and tan, sesame, always with urajiro).
    'shiba_inu' => [
        'display_name' => 'Shiba Inu',
        // Colour frequencies are in no source (shiba_inu.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 257 (S377, https://dev.fci.be/Nomenclature/Standards/257g05-en.pdf), AKC standard (S380), RKC breed page (S378) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S378 "Small-Medium"; S377 "Height at withers: Males 39.5 cm."',
            'build' => 'S377 "Small-sized dog, well balanced, well boned with well developed muscles."',
            'coat_length' => 'S377 "Outer coat harsh and straight, undercoat soft and dense; hair on tail slightly long and standing off."',
            'coat_color' => 'S377 "Colour: Red, black and tan, sesame, black sesame, red sesame."; S380 "Bright orange-red with urajiro" (weights unsourced; never cream, white or pinto)',
            'ear_carriage' => 'S377 "Relatively small, triangular, slightly inclining forward and firmly pricked."',
            'eye_color' => 'S377 "Triangular, not too small, and dark brown in colour."',
            'tail' => 'S377 "Set on high, thick, carried vigorously curled or curved as a sickle, the tip nearly reaching hocks when let down."',
        ],
        'traits' => [
            'size' => ['small to medium-sized, compact'],
            'build' => [['value' => 'small, well balanced and well boned spitz with well developed muscles', 'weight' => 3], 'compact, agile spitz with a slightly longer-than-tall body'],
            'coat_length' => ['short double coat with a harsh, straight outer coat and a soft, dense undercoat, slightly longer hair on the tail'],
            // Standard colours only (S377, S380); red highest → the register portrait.
            'coat_color' => [
                ['value' => 'red with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'weight' => 60],
                ['value' => 'black and tan with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'weight' => 25],
                ['value' => 'sesame (black-tipped hairs on a red background) with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'weight' => 15],
            ],
            'ear_carriage' => ['small, triangular, firmly pricked ears slightly inclined forward'],
            'eye_color' => ['triangular, dark brown eyes with slightly upturned outer corners'],
            'tail' => ['thick tail set high and carried curled over the back'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],

    // M5-R10-47 (docs/research/dog-data/data.json rhodesian_ridgeback.appearance; runbook rules 2026-10-10:
    // standard colours only — light wheaten to red wheaten; the ridge always visible; natural ears and tail).
    'rhodesian_ridgeback' => [
        'display_name' => 'Rhodesian Ridgeback',
        // Colour frequencies are in no source (rhodesian_ridgeback.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 146 (S385), RKC standard (S388), AKC standard (S389), RKC breed page (S387) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S387 "Size: Large"; S385 "Height at the withers: Males: 63 cm to 69 cm"',
            'build' => 'S385 "should represent a well balanced, strong, muscular, agile and active dog," "with no tendency towards massiveness."',
            'coat_length' => 'S385 "Should be short and dense, sleek and glossy in appearance, but neither woolly nor silky."',
            'coat_color' => 'S385 "Light wheaten to red wheaten."; S388 "Head, body, legs and tail of uniform colour." "Little white on chest and toes permissible" (weights unsourced; never black, black and tan or brindle)',
            'ear_carriage' => 'S385 "Should be set rather high, of medium size, rather wide at base, and gradually tapering to a rounded point." "They should be carried close to the head."',
            'eye_color' => 'S385 "Should be moderately well apart, round, bright and sparkling, with intelligent expression," "A black nose should be accompanied by dark eyes"',
            'tail' => 'S385 "should be carried with a slight curve upwards, never curled." "It should be of moderate length."',
            'nose' => 'S385 "The nose should be black or brown." "A black nose should be accompanied by dark eyes, a brown nose by amber eyes." (portrait: black nose, the first-named option)',
            'ridge' => 'S388 "A distinctive feature is the ridge on the back formed by hair growing in opposite direction to the remainder of coat."; "Ridge clearly defined, tapering and symmetrical, starting immediately behind shoulders and continuing to haunch,"; "and containing two identical crowns only, opposite each other,"; S385 "Dogs without ridge." (disqualifying)',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'well balanced, strong, muscular, agile and athletic hound, elegant and never massive', 'weight' => 3], 'lean, athletic hound with a deep chest, standing alert'],
            'coat_length' => ['short, dense, sleek and glossy'],
            // Standard colours only (S385, S388, S389); red wheaten highest → the register portrait.
            'coat_color' => [
                ['value' => 'red wheaten, uniform colour, at most a little white on the chest and toes', 'weight' => 60],
                ['value' => 'light wheaten, uniform colour, at most a little white on the chest and toes', 'weight' => 40],
            ],
            'ear_carriage' => ['medium-sized, set rather high, wide at the base and tapering to a rounded point, carried close to the head'],
            'eye_color' => ['round, bright, dark'],
            'tail' => ['strong, tapering, moderate-length, carried with a slight upward curve and never curled'],
            'nose' => ['black nose'],
            'ridge' => ['clearly defined ridge of hair growing in the opposite direction along the spine, from just behind the shoulders to the haunch, with two identical crowns opposite each other'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose', 'ridge'],
    ],

    // M5-R10-48 (docs/research/dog-data/data.json papillon.appearance; runbook rules 2026-10-10:
    // standard colours only — white with patches of any colour; the erect-eared Papillon variety).
    'papillon' => [
        'display_name' => 'Papillon',
        // Colour frequencies are in no source (papillon.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 77 (S396), AKC-approved standard (S399), RKC breed page (S397) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S397 "Size: Small"; S396 "Height at the withers: About 28 cm."',
            'build' => 'S396 "Small de luxe Toy spaniel, of a normal and harmonious build, with long hair,"',
            'coat_length' => 'S399 "Abundant, long, fine, silky, flowing, straight with resilient quality, flat on back and sides of body." "A profuse frill on chest. There is no undercoat."',
            'coat_color' => 'S396 "All colours are admitted on a coat with a white background."; S399 "Always parti-color or white with patches of any color(s)." "On the head, color(s) other than white must cover both ears, back and front," (weights unsourced; never all white or without white)',
            'ear_carriage' => 'S399 "Ears of the erect type are carried obliquely and move like the spread wings of a butterfly." "The ears of either the erect or drop type should be large with rounded tips,"',
            'eye_color' => 'S399 "dark, round, not bulging, of medium size and alert in expression." "Eye rims black."',
            'tail' => 'S399 "Tail long, set high and carried well arched over the body." "The tail is covered with a long, flowing plume."',
            'nose' => 'S399 "Nose black, small, rounded and slightly flat on top."',
        ],
        'traits' => [
            'size' => ['tiny toy-sized'],
            'build' => [['value' => 'small, dainty and elegant, slightly longer than tall, fine-boned', 'weight' => 3], 'small and graceful with a proud carriage'],
            'coat_length' => ['long, fine, silky, straight single coat with a profuse chest frill, feathered legs and short hair on the face'],
            // Standard: white with patches of any colour (S396, S399); white with red patches highest → the register portrait.
            'coat_color' => [
                ['value' => 'white with red patches, colour covering both ears and over both eyes, white blaze', 'weight' => 35],
                ['value' => 'white with sable patches, colour covering both ears and over both eyes, white blaze', 'weight' => 25],
                ['value' => 'tricolour: white with black patches and tan markings, colour covering both ears and over both eyes, white blaze', 'weight' => 20],
                ['value' => 'white with black patches, colour covering both ears and over both eyes, white blaze', 'weight' => 15],
                ['value' => 'white with lemon patches, colour covering both ears and over both eyes, white blaze', 'weight' => 5],
            ],
            'ear_carriage' => ['large erect ears with rounded tips, well fringed, carried obliquely at about 45 degrees like the spread wings of a butterfly'],
            'eye_color' => ['dark, round, not bulging, with black rims'],
            'tail' => ['long, set high, carried arched over the back with a long flowing plume'],
            'nose' => ['small black rounded nose (not short-faced)'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
    ],

    // M5-R10-49 (docs/research/dog-data/data.json portuguese_water_dog.appearance; runbook rules 2026-10-10:
    // standard colours only — black, white, brown, black and white, brown and white).
    'portuguese_water_dog' => [
        'display_name' => 'Portuguese Water Dog',
        // Colour frequencies are in no source (portuguese_water_dog.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'RKC breed standard (S403), RKC breed page (S404) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S404 "Size: Medium"; S403 "Height: dogs: 50-57 cms (19½-22½ ins)" "bitches: 43-52 cms (17-20½ ins)"',
            'build' => 'S403 "Robust, well balanced, of almost square shape, very strongly muscled on shoulders."',
            'coat_length' => 'S403 "Profuse, covering whole body except under forelegs and thigh." (two coat types without undercoat — paraphrase)',
            'clip' => 'S403 coat section: muzzle and hindquarters from the last rib clipped, two-thirds of the tail clipped (paraphrase); S404 the traditional lion trim (paraphrase)',
            'coat_color' => 'S403 "Black, white, various shades of brown, black and white, brown and white." "Any other colour or combination of colours unacceptable." (weights unsourced; nose black or liver with the coat colour — paraphrase)',
            'ear_carriage' => 'S403 "Heart shaped, dropped, thin, set well above eye level, held close to head, except at back."',
            'eye_color' => 'S403 "Medium, round, set well apart. Black or dark brown with dark eye rims."',
            'tail' => 'S403 "The tail is a distinct feature of the breed and should be medium set, thick at base and tapering." (carried in a ring when alert — paraphrase)',
        ],
        'traits' => [
            'size' => ['medium-sized'],
            'build' => [['value' => 'robust, well-balanced, almost square, strongly muscled shoulders', 'weight' => 3], 'sturdy and athletic working water dog, almost square'],
            'coat_length' => [['value' => 'profuse single coat of compact curls without undercoat', 'weight' => 3], 'profuse single coat, fairly long and loosely waved with a slight sheen, without undercoat'],
            'clip' => ['traditional lion clip: full coat over the front half of the body, muzzle and hindquarters clipped short, plume left at the tip of the tail'],
            // Standard: black, white, various shades of brown, black and white, brown and white (S403); black highest → the register portrait.
            'coat_color' => [
                ['value' => 'solid black with a black nose', 'weight' => 35],
                ['value' => 'black with white on the chin, chest and feet, black nose', 'weight' => 25],
                ['value' => 'brown with a liver nose', 'weight' => 20],
                ['value' => 'brown with white on the chin, chest and feet, liver nose', 'weight' => 12],
                ['value' => 'white with a black nose', 'weight' => 8],
            ],
            'ear_carriage' => ['heart-shaped, thin drop ears set well above eye level, held close to the head'],
            'eye_color' => ['dark brown, round, set well apart, with dark eye rims'],
            'tail' => ['medium set, thick at the base and tapering, carried in a ring when alert, with a plume at the tip'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'clip', 'coat_color', 'ear_carriage', 'eye_color', 'tail'],
    ],

    // M5-R10-50 (docs/research/dog-data/data.json bichon_frise.appearance; runbook rules 2026-10-10:
    // standard colour only — pure white on an adult).
    'bichon_frise' => [
        'display_name' => 'Bichon Frise',
        // One standard colour; the trait list itself is an unchecked draft → false.
        'verified' => false,
        'source' => 'FCI-Standard N° 215 (S410), RKC breed standard (S412), RKC breed page (S411) — for the traits listed in `sources`. Colour weight unsourced.',
        'sources' => [
            'size' => 'S411 "Size: Small"; S410 "Height at the withers: 25 to 29 cm."',
            'build' => 'S412 "Well-balanced dog of smart appearance, closely coated with handsome plume carried over the back."',
            'coat_length' => 'S412 "Fine, silky with soft corkscrew curls, neither flat nor corded and measuring 7-10 cms (3-4 ins) in length."; S410 "The hair of the outer coat forms loose spiral curls"',
            'coat_color' => 'S410 "Pure white."; S412 "White, but cream or apricot markings acceptable up to 18 months." (adult → white only)',
            'ear_carriage' => 'S410 "The ears are drooping and are well-furnished with profuse hair."',
            'eye_color' => 'S410 "Very dark, medium-sized, rather round in shape, neither almond-shaped nor protruding."; S412 "Dark, round with black eye rims, surrounded by dark haloes, consisting of well-pigmented skin."',
            'tail' => 'S412 "Normally carried raised and curved gracefully over the back but not tightly curled."',
            'nose' => 'S410 "Rounded, black, finely grained and glossy."',
        ],
        'traits' => [
            'size' => ['small'],
            'build' => [['value' => 'small, well-balanced and sturdy, slightly longer than tall', 'weight' => 3], 'small and compact with a lively, proud carriage'],
            'coat_length' => [
                ['value' => 'loose, soft corkscrew-curled coat in a natural rounded trim', 'weight' => 3],
                'loose, soft corkscrew-curled coat, slightly fuller around the head',
            ],
            // Pure white is the only adult colour (S410, S412).
            'coat_color' => ['pure white'],
            'ear_carriage' => ['drooping ears well covered with curly hair'],
            'eye_color' => ['very dark, round, with black rims and dark haloes'],
            'tail' => ['plumed tail carried gracefully curved over the back, not tightly curled'],
            'nose' => ['round black glossy nose (not short-faced)'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
    ],
    // M5-R10-51 (docs/research/dog-data/data.json newfoundland.appearance; runbook rules 2026-10-10:
    // standard colours shared by FCI and AKC only — black, white and black (Landseer), brown; never gray;
    // welfare: closely fitting eyelids).
    'newfoundland' => [
        'display_name' => 'Newfoundland',
        // Colour frequencies are in no source (newfoundland.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 50 (S420, Spanish version — the English PDF redirects to itself), AKC standard (S421), PDSA (S423), Kennel Club Breed Health and Conservation Plan (S425) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S423 "Size: Large"; S421 "Average height for adult dogs is 28 inches, for adult bitches 26 inches."',
            'build' => 'S421 "The Newfoundland is a sweet-dispositioned dog that acts neither dull nor ill-tempered."; S422 large, deep-bodied and muscular (paraphrase)',
            'coat_length' => 'S421 "The adult Newfoundland has a flat, water-resistant, double coat that tends to fall back into place"',
            'coat_color' => 'S420 "Color: Negro, blanco y negro y castaño."; S421 "Recognized Newfoundland colors are black, brown, gray, and white and black." (gray AKC only — not used; weights unsourced)',
            'ear_carriage' => 'S421 "Ears are relatively small and triangular with rounded tips."',
            'eye_color' => 'S421 "They are relatively small, deep-set, and spaced wide apart."; S425 "Conformational defects of the upper and lower eyelids (loose eyelids)" (welfare: closely fitting eyelids)',
            'tail' => 'S421 "The tail is broad at the base and strong."; "It has no kinks, and the distal bone reaches to the hock."',
            'muzzle' => 'S421 "The muzzle is clean-cut, broad throughout its length, and deep."',
        ],
        'traits' => [
            'size' => ['very large, heavy-boned'],
            'build' => [['value' => 'large, deep-bodied and muscular, heavily boned yet well balanced, with a dignified, gentle bearing', 'weight' => 3], 'massive but balanced, slightly longer than tall, standing calmly'],
            'coat_length' => ['flat, water-resistant double coat, moderately long and straight with a dense soft undercoat'],
            // Colours shared by FCI (S420) and AKC (S421); black highest → the register portrait.
            'coat_color' => [
                ['value' => 'solid black', 'weight' => 70],
                ['value' => 'white and black (Landseer): white body with a black head and white blaze, black saddle and black rump', 'weight' => 20],
                ['value' => 'solid brown (chocolate to bronze)', 'weight' => 10],
            ],
            'ear_carriage' => ['relatively small triangular ears with rounded tips, lying close to the head'],
            // Welfare (Breed Watch point via S425): eyelids in normal contact, no drooping, no haw.
            'eye_color' => ['dark brown, relatively small, deep-set eyes, wide apart, with closely fitting eyelids (no drooping, no haw)'],
            'tail' => ['natural tail, broad and strong at the base, hanging straight to the hock'],
            'muzzle' => ['broad, deep, clean-cut muzzle of moderate length with a black nose and open nostrils, no loose jowls'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'muzzle'],
    ],
    // M5-R10-52 (docs/research/dog-data/data.json dalmatian.appearance; runbook rules 2026-10-10:
    // standard colours only — black-spotted, liver-spotted; never patches, tricolour or blue eyes).
    'dalmatian' => [
        'display_name' => 'Dalmatian',
        // Colour frequencies are in no source (dalmatian.appearance.colour_weights UNSOURCED) → draft.
        'verified' => false,
        'source' => 'FCI-Standard N° 153 (S428), RKC breed page (S429), PDSA (S430) — for the traits listed in `sources`. Colour weights are unsourced.',
        'sources' => [
            'size' => 'S429 "Size: Large"; S428 "Height at the withers: Males 56 – 62 cms. Females 54 – 60 cms."',
            'build' => 'S428 "The body is rectangular, strong, muscled and distinctively spotted."; "The movement must be elegant."',
            'coat_length' => 'S428 "Short, shiny, hard and dense all over the body."',
            'coat_color' => 'S428 "The ground colour is pure white."; "Black spotted variety with black spots, liver spotted variety with brown spots." (weights unsourced)',
            'ear_carriage' => 'S428 "It is very important that the ears are spotted"',
            'eye_color' => 'S428 "The eye colour is in accordance with the spotting."; "Eyelids are close to the eyeball and not loose."',
            'tail' => 'S428 "Reaching approximately to the hock or slightly longer."; "Carried sabre-shaped."',
            'nose' => 'S428 "Large with wide open nostrils, it must be completely pigmented."',
        ],
        'traits' => [
            'size' => ['medium-large, athletic'],
            'build' => [['value' => 'rectangular, strong and muscular, elegant and well balanced', 'weight' => 3], 'lean, athletic and strong, standing alert'],
            'coat_length' => ['short, shiny, hard and dense coat'],
            // FCI (S428) colours; black-spotted highest → the register portrait.
            'coat_color' => [
                ['value' => 'pure white with round, clearly defined black spots', 'weight' => 75],
                ['value' => 'pure white with round, clearly defined liver (brown) spots', 'weight' => 25],
            ],
            'ear_carriage' => ['hanging ears, spotted, set fairly high'],
            'eye_color' => ['oval eyes matching the spotting (dark brown, amber with liver), closely fitting eyelids, pigmented eye rims'],
            'tail' => ['tail reaching about to the hock, carried in a slight sabre curve, spotted'],
            'nose' => ['large nose with open nostrils, fully pigmented to match the spots'],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'ear_carriage', 'eye_color', 'tail', 'nose'],
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
    'british_shorthair' => [
        'display_name' => 'British Shorthair cat',
        // Colour weights are not in the standard (unsourced) — same rule as the Maine Coon.
        'verified' => false,
        'source' => 'FIFe breed standard British Longhair / British Shorthair (BLH / BSH), 01.07.2026 (C26, https://fifeweb.org/dnld/std/BLH-BSH.pdf) — for the traits listed in `sources`; weight / maturity C27 (CFA). Colour weights are unsourced.',
        'sources' => [
            'size' => 'C26 "Large to medium"; C27 CFA males 4.1–7.7 kg, females 3.2–5.4 kg',
            'build' => 'C26 "Muscular, cobby. Broad chest, shoulders and back strong and solid."',
            'coat_length' => 'C26 BSH "Short, dense, not lying flat and with a good undercoat. Texture should be crisp to touch."',
            'coat_color' => 'C26 recognised varieties (solid incl. blue, black, cream, red, lilac, white; tortie; tabby; bicolour); blue best known (C27, C28) — weights unsourced',
            'ear_carriage' => 'C26 ears "Small and slightly rounded at the tips", "Set wide apart"',
            'eye_color' => 'C26 eye colour by variety (copper / deep orange; green with silver; deep blue / odd eyes with white)',
            'tail' => 'C26 tail "Short and thick, slightly rounded at the tip"',
            'features' => 'C26 head "Round and massive with a broad skull"; nose "Short, broad and straight with a slight indentation, but not a stop as in the \"Exotic\""; chin "Strong"; eyes "Large, round, wide opened"',
            'stage_notes' => 'C27 "do not reach their full size until three years of age"; C28 "up to five years to reach physical maturity"; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'muscular, cobby, broad-chested', 'weight' => 2], 'compact, solid, broad-chested'],
            'coat_length' => ['short, dense, plush'],
            // FIFe varieties only (C26); blue first and highest (portrait default).
            'coat_color' => [
                ['value' => 'blue-grey', 'weight' => 4],
                'black',
                'cream',
                'lilac',
                'white',
                'red',
                ['value' => 'silver', 'weight' => 2],
                'tortoiseshell black and red',
            ],
            'coat_pattern' => [
                ['value' => 'solid', 'weight' => 3, 'only_with' => ['coat_color' => ['blue-grey', 'black', 'cream', 'lilac', 'white']]],
                ['value' => 'with a white chest, belly and paws', 'only_with' => ['coat_color' => ['blue-grey', 'black', 'cream', 'red']]],
                ['value' => 'with classic tabby markings', 'weight' => 2, 'only_with' => ['coat_color' => ['silver', 'red', 'cream']]],
                ['value' => 'with mottled patches', 'only_with' => ['coat_color' => ['tortoiseshell black and red']]],
            ],
            'ear_carriage' => ['small, slightly rounded, set wide apart'],
            'eye_color' => [
                ['value' => 'deep copper', 'weight' => 3, 'only_with' => ['coat_color' => ['blue-grey', 'black', 'cream', 'lilac', 'white', 'red', 'tortoiseshell black and red']]],
                ['value' => 'deep orange', 'only_with' => ['coat_color' => ['blue-grey', 'black', 'cream', 'lilac', 'white', 'red', 'tortoiseshell black and red']]],
                ['value' => 'green', 'only_with' => ['coat_color' => ['silver']]],
                ['value' => 'deep blue', 'only_with' => ['coat_color' => ['white']]],
            ],
            'tail' => ['short, thick, rounded at the tip'],
        ],
        'features' => [
            'a round, massive head with a broad skull and full cheeks',
            'a short, broad, straight nose with only a slight dip — not a flat face',
            'large, round, wide-open eyes',
            'a strong chin',
        ],
        // CAT_SPEC §8 (D): the young British Shorthair is not fully grown yet (full size at three to five years, C27 / C28).
        'stage_notes' => [
            'puppy' => 'As a British Shorthair kitten it is round and plush, with a short, thick coat and a short tail',
            'young' => 'As a young British Shorthair it is not fully grown yet: a little less broad and heavy than an adult (the breed reaches full size at three to five years)',
        ],
        // A growing British Shorthair: no adult "massive head" / "cobby" wording next to the stage note.
        'stage_overrides' => [
            'puppy' => [
                'build' => 'compact, solid, broad-chested',
                'features' => ['a round head with a short, broad nose', 'large, round, wide-open eyes'],
            ],
            'young' => [
                'build' => 'compact, solid, broad-chested',
                'features' => ['a round head with a broad skull', 'a short, broad, straight nose with only a slight dip — not a flat face', 'large, round, wide-open eyes'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    'ragdoll' => [
        'display_name' => 'Ragdoll cat',
        // Colour / pattern weights are not in the standard (unsourced) — same rule as the Maine Coon.
        'verified' => false,
        'source' => 'FIFe breed standard Ragdoll (RAG), 01.01.2026 (C29, https://www.fifeweb.org/wp-content/uploads/2023/10/RAG.pdf) — for the traits listed in `sources`; weight / maturity C30 (CFA). Colour and pattern weights are unsourced.',
        'sources' => [
            'size' => 'C29 "a firm, large cat"; C30 CFA males 6.8–9.1 kg, females 4.5–6.8 kg',
            'build' => 'C29 "medium to strongly boned muscular body"; "Long body, medium to strong bone structure, muscular."',
            'coat_length' => 'C29 "Medium long, dense, soft and silky texture, lying with the body."; longest at the neck (bib)',
            'coat_color' => 'C29 colours seal, blue, chocolate, lilac, red, cream (points) — weights unsourced',
            'coat_pattern' => 'C29 patterns colourpoint, mitted, bicolour (bicolour: inverted white V on the mask, white chest / belly / legs; mitted: white chin, belly stripe, front mittens) — weights unsourced',
            'ear_carriage' => 'C29 ears "Medium size, broad at the base, with rounded tips.", "Set wide on the skull with a slight tilt forward."',
            'eye_color' => 'C29 eyes "Blue, the more intensive the better."; any other eye colour is a disqualification',
            'tail' => 'C29 tail long, well furnished and bushy',
            'features' => 'C29 head "Medium size, broad modified wedge."; broad rounded muzzle; eyes "Large and oval."; paws "Large, round and compact, tufted between the toes."',
            'stage_notes' => 'C30 "reaching full coat color at two years, and full size and weight at four"; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['large'],
            'build' => [['value' => 'long-bodied, muscular, broad-chested', 'weight' => 2], 'long-bodied, sturdy, solid-boned'],
            'coat_length' => ['semi-long, soft, silky'],
            // FIFe colours only (C29); seal first and highest (portrait default).
            'coat_color' => [
                ['value' => 'seal point (a pale cream body with dark seal-brown face mask, ears, legs and tail)', 'weight' => 4],
                ['value' => 'blue point (a pale bluish-white body with grey-blue face mask, ears, legs and tail)', 'weight' => 2],
                'chocolate point (an ivory body with milk-chocolate face mask, ears, legs and tail)',
                'lilac point (a white body with pinkish-grey face mask, ears, legs and tail)',
                'red point (a creamy white body with reddish-orange face mask, ears, legs and tail)',
                'cream point (a white body with soft cream face mask, ears, legs and tail)',
            ],
            // FIFe patterns only (C29); no van (CFA C30 only).
            'coat_pattern' => [
                ['value' => 'in the colourpoint pattern, with no white anywhere', 'weight' => 3],
                ['value' => 'in the mitted pattern: white front paws, white hind feet, a white chin and a white stripe along the belly', 'weight' => 2],
                ['value' => 'in the bicolour pattern: an inverted white V on the face, a white chest, belly and legs', 'weight' => 2],
            ],
            'ear_carriage' => ['medium, broad-based, round-tipped, slightly forward-tilted'],
            // Blue only — any other eye colour disqualifies (C29).
            'eye_color' => ['deep blue'],
            'tail' => ['long, bushy, plume-like'],
        ],
        'features' => [
            'a broad, modified-wedge head with full cheeks and a broad, rounded muzzle',
            'large, oval eyes',
            'a full bib of longer fur around the neck',
            'large, round paws with tufts of fur between the toes',
        ],
        // CAT_SPEC §8 (D): the Ragdoll is slow to mature — full coat colour at two years,
        // full size at four (C30).
        'stage_notes' => [
            'puppy' => 'As a Ragdoll kitten it is fluffy and pale, with soft blue eyes and its darker points only faintly showing yet',
            'young' => 'As a young Ragdoll it is not fully grown yet: longer-legged and less heavy than an adult, its points still darkening (the breed reaches full coat colour at about two years and full size at about four)',
        ],
        // A growing Ragdoll: no adult "full bib" / "plume" wording next to the stage note.
        'stage_overrides' => [
            'puppy' => [
                'build' => 'small, soft, sturdy',
                'tail' => 'fluffy',
                'features' => ['a broad, rounded head with a short muzzle', 'large, oval eyes'],
            ],
            'young' => [
                'build' => 'long-bodied, still slender',
                'tail' => 'long, bushy',
                'features' => ['a broad, modified-wedge head with a rounded muzzle', 'large, oval eyes', 'a bib of longer fur that is still filling out'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    'siberian_cat' => [
        'display_name' => 'Siberian cat',
        // Colour / pattern weights are not in the standard (unsourced) — same rule as the Maine Coon.
        'verified' => false,
        'source' => 'FIFe breed standard Siberian / Neva Masquerade (SIB / NEM), 01.07.2026 (C33, https://www.fifeweb.org/wp-content/uploads/2023/10/SIB-NEM.pdf) — for the traits listed in `sources`; weight / maturity C34 (GCCF). Colour and pattern weights are unsourced.',
        'sources' => [
            'size' => 'C33 "Medium to large, females mostly smaller than males"; C34 GCCF males 7.7–11.8 kg, females 5.9–7.7 kg',
            'build' => 'C33 "Well boned and muscled, powerful neck, broad chest, body in proportion to create a rectangular appearance."',
            'coat_length' => 'C33 "Semi-long, well developed, very dense, undercoat not lying flat, overcoat water repellent, slightly hard to touch."; frill and knickerbockers in the winter coat',
            'coat_color' => 'C33 "All colour varieties are permitted, including all colour varieties with white;" — not pointed, chocolate, lilac, cinnamon or fawn; weights unsourced',
            'coat_pattern' => 'C33 tabby, solid, tortie, silver and with-white varieties — weights unsourced',
            'ear_carriage' => 'C33 ears "Medium size, well open at the base, tips are rounded with well-developed hairs inside and tufts."; slight forward tilt',
            'eye_color' => 'C33 eyes "Large, slightly oval shaped"; all eye colours except blue (blue only with white)',
            'tail' => 'C33 tail "Of medium length, thick, rounded tip."; densely covered',
            'features' => 'C33 head "A little bit longer than broad, softly rounded, massive."; nose "Medium length, broad, in profile shows a slight indentation, but without stop."; paws "Large, round, well tufted between toes."',
            'stage_notes' => 'C34 "full development can take four to five years"; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'powerful, well-muscled, broad-chested, rectangular', 'weight' => 2], 'sturdy, solid-boned, rectangular'],
            'coat_length' => ['semi-long, very dense, with a full neck ruff'],
            // FIFe colours only (C33): never pointed, chocolate, lilac, cinnamon or fawn. Brown tabby first and highest (portrait default).
            'coat_color' => [
                ['value' => 'brown', 'weight' => 4],
                ['value' => 'silver', 'weight' => 2],
                'red',
                'black',
                'blue-grey',
                'cream',
                'white',
                'tortoiseshell black and red',
            ],
            'coat_pattern' => [
                ['value' => 'with mackerel tabby stripes', 'weight' => 3, 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'blue-grey', 'cream']]],
                ['value' => 'with classic tabby markings', 'weight' => 2, 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'blue-grey', 'cream']]],
                ['value' => 'solid', 'only_with' => ['coat_color' => ['black', 'blue-grey', 'white']]],
                // Never plain brown with white: an unstriped brown coat would read as chocolate (not a FIFe colour, C33).
                ['value' => 'with a white chest, belly and paws', 'only_with' => ['coat_color' => ['black', 'red', 'blue-grey']]],
                ['value' => 'with mottled patches', 'only_with' => ['coat_color' => ['tortoiseshell black and red']]],
            ],
            'ear_carriage' => ['medium, round-tipped, tufted, slightly forward-tilted'],
            // Any eye colour except blue; blue only on a white cat (C33).
            'eye_color' => [
                ['value' => 'green-gold', 'weight' => 3, 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'black', 'blue-grey', 'cream', 'tortoiseshell black and red']]],
                ['value' => 'green', 'only_with' => ['coat_color' => ['brown', 'silver', 'black', 'blue-grey']]],
                ['value' => 'amber', 'only_with' => ['coat_color' => ['brown', 'red', 'black', 'blue-grey', 'cream', 'white', 'tortoiseshell black and red']]],
                ['value' => 'deep blue', 'only_with' => ['coat_color' => ['white']]],
            ],
            'tail' => ['thick, medium-length, densely furred, rounded at the tip'],
        ],
        'features' => [
            'a softly rounded, massive head a little longer than broad',
            'a medium-length, broad nose with a gentle dip and no stop',
            'large, slightly oval eyes set wide apart',
            'large, round paws with tufts of fur between the toes',
        ],
        // CAT_SPEC §8 (D): the Siberian is slow to mature — full development at four to five years (C34).
        'stage_notes' => [
            'puppy' => 'As a Siberian kitten it is a fluffy, sturdy ball of fur with a softer coat than an adult',
            'young' => 'As a young Siberian it is not fully grown yet: lighter and less massive than an adult, its ruff still filling out (the breed reaches full development at four to five years)',
        ],
        // A growing Siberian: no adult "massive head" / "full neck ruff" wording next to the stage note.
        'stage_overrides' => [
            'puppy' => [
                'build' => 'small, sturdy, fluffy',
                'coat_length' => 'soft, fluffy, medium-long',
                'tail' => 'fluffy',
                'features' => ['a rounded head with a short, broad nose', 'large, slightly oval eyes'],
            ],
            'young' => [
                'build' => 'sturdy, rectangular, not yet heavy',
                'coat_length' => 'semi-long, dense, the ruff still filling out',
                'features' => ['a softly rounded head a little longer than broad', 'a medium-length, broad nose with a gentle dip and no stop', 'large, slightly oval eyes set wide apart'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    'norwegian_forest_cat' => [
        'display_name' => 'Norwegian Forest Cat',
        // Colour / pattern weights are not in the standard (unsourced) — same rule as the Maine Coon.
        'verified' => false,
        'source' => 'WCF breed standard Norwegian Forest Cat (NFO), actualized 2021-12-08 (C36, https://wcf.de/pdf-fr/breed/NFO_en_2021-12-08.pdf) — for the traits listed in `sources`; FIFe colour list via World Cat Congress (C40); weight / maturity C37 (GCCF). Colour and pattern weights are unsourced.',
        'sources' => [
            'size' => 'C36 "The medium to large sized cat has a solid, elongated body with a massive bone structure."; C37 GCCF males 5.4–7.3 kg, females 4.1–5.4 kg',
            'build' => 'C36 "solid, elongated body with a massive bone structure"; "The legs are long and muscular, the hind legs are higher than the front legs"',
            'coat_length' => 'C36 "The woolly undercoat is covered by long, glossy, firm and water repellent hair."; fuller in winter (CFA C39)',
            'coat_color' => 'C40 FIFe: all colours incl. with white, except pointed, chocolate, lilac, cinnamon and fawn; C36 "Any amount of white is permitted." — weights unsourced',
            'coat_pattern' => 'C36 / C40 tabby, solid, tortie, silver and with-white varieties — weights unsourced; a brown cat is always tabby',
            'ear_carriage' => 'C36 "The ears are very large with a broad base and high on the head"',
            'eye_color' => 'C36 "The eyes are large, open and slightly oval."; "Eye colour shall be even and corresponds to the coat colour."',
            'tail' => 'C36 "The tail is very long (at least as long as the body) and bushy."',
            'features' => 'C36 "The head has the shape of an equilateral triangle with a long, straight profile."; "Slightly rounded forehead."; "The chin is firm."; paws "broad and round, with tufts between the toes"; faults "Curved profile", "Roman nose", "Round eyes"',
            'stage_notes' => 'C37 "not considered fully grown until they are around 4-5 years of age"; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'solid, long-bodied, strong-boned, with long muscular legs', 'weight' => 2], 'sturdy, elongated, well-muscled'],
            'coat_length' => ['long, glossy, water-repellent top coat over a woolly undercoat'],
            // FIFe colours only (C40): never pointed, chocolate, lilac, cinnamon or fawn. Brown tabby first and highest (portrait default).
            'coat_color' => [
                ['value' => 'brown', 'weight' => 4],
                ['value' => 'silver', 'weight' => 2],
                'red',
                'black',
                'blue-grey',
                'cream',
                'white',
                'tortoiseshell black and red',
            ],
            // Brown only with a tabby pattern (a plain brown cat would read as chocolate, which the standard excludes).
            'coat_pattern' => [
                ['value' => 'with mackerel tabby stripes and a white chest, belly and paws', 'weight' => 3, 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'blue-grey', 'cream']]],
                ['value' => 'with mackerel tabby stripes', 'weight' => 2, 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'blue-grey', 'cream']]],
                ['value' => 'with classic tabby markings', 'only_with' => ['coat_color' => ['brown', 'silver', 'red', 'blue-grey', 'cream']]],
                ['value' => 'solid', 'only_with' => ['coat_color' => ['black', 'blue-grey', 'white']]],
                ['value' => 'with a white chest, belly and paws', 'only_with' => ['coat_color' => ['black', 'red', 'blue-grey']]],
                ['value' => 'with mottled patches', 'only_with' => ['coat_color' => ['tortoiseshell black and red']]],
            ],
            'ear_carriage' => ['very large, broad-based, set high'],
            // Even eye colour that goes with the coat (C36); no blue (not needed for a typical look).
            'eye_color' => [
                ['value' => 'green-gold', 'weight' => 3],
                ['value' => 'green', 'only_with' => ['coat_color' => ['brown', 'silver', 'black', 'blue-grey', 'white']]],
                ['value' => 'gold', 'only_with' => ['coat_color' => ['brown', 'red', 'black', 'blue-grey', 'cream', 'white', 'tortoiseshell black and red']]],
            ],
            'tail' => ['very long and bushy, at least as long as the body'],
        ],
        'features' => [
            'a triangular head with a long, straight profile and a firm chin',
            'large, open, slightly oval eyes',
            'broad, round paws with tufts of fur between the toes',
        ],
        // CAT_SPEC §8 (D): the Norwegian Forest Cat is slow to mature — fully grown at four to five years (C37).
        'stage_notes' => [
            'puppy' => 'As a Norwegian Forest Cat kitten it is a fluffy, leggy ball of fur with a softer coat than an adult',
            'young' => 'As a young Norwegian Forest Cat it is not fully grown yet: lighter and less massive than an adult, its coat still filling out (the breed is fully grown at four to five years)',
        ],
        // A growing Norwegian Forest Cat: no adult "strong-boned" / "massive" wording next to the stage note.
        'stage_overrides' => [
            'puppy' => [
                'build' => 'small, leggy, fluffy',
                'coat_length' => 'soft, fluffy, medium-long',
                'tail' => 'fluffy, fairly long',
                'features' => ['a triangular head with a straight profile', 'large, slightly oval eyes'],
            ],
            'young' => [
                'build' => 'long-bodied and leggy, not yet heavy',
                'coat_length' => 'long, glossy, still filling out',
                'features' => ['a triangular head with a long, straight profile', 'large, open, slightly oval eyes', 'broad, round paws with tufts of fur between the toes'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
    'bengal' => [
        'display_name' => 'Bengal',
        // Colour / pattern weights are not in the standard (unsourced) — same rule as the Maine Coon.
        'verified' => false,
        'source' => 'WCF breed standard Bengal (BEN), actualized 2024-08-01 (C42, https://wcf.de/pdf-en/breed/BEN_en_2024-08-01.pdf) and FIFe BEN 01.01.2026 (C43) — for the traits listed in `sources`; FIFe colour list via World Cat Congress (C47); weight C44 (PDSA), maturity C46 (VCA). Colour and pattern weights are unsourced.',
        'sources' => [
            'size' => 'C42 "The Bengal is a medium to large-sized cat, characterized by a muscular, elongated, and robust body."; C44 PDSA average weight 5.5 kg',
            'build' => 'C42 / C43 long, substantial body, robust bone, very muscular; hindquarters slightly higher than the shoulders (paraphrase)',
            'coat_length' => 'C42 / C43 short, dense, close-lying, unusually soft, preferably glittering (paraphrase)',
            'coat_color' => 'C47 FIFe "spotted and marbled pattern in brown (black), seal sepia, seal mink point and snow" (+ silver) — weights unsourced',
            'coat_pattern' => 'C42 / C43 spotted (rosettes preferred) or marbled, belly spotted; faults: mackerel-like elongated spots, white patches (paraphrase) — weights unsourced',
            'ear_carriage' => 'C42 / C43 medium to small, wide at the base, rounded tips; C43 furnishings acceptable but no tufts (paraphrase)',
            'eye_color' => 'C43 green, gold, brown, blue-green or blue by variety; C42 "For Snow Bengals, the eye colour is an intense, pure blue."',
            'tail' => 'C43 thick, tapering to a rounded tip, medium length (paraphrase)',
            'features' => 'C42 / C43 broad modified wedge head with rounded contours, high cheekbones, full broad muzzle with large whisker pads, large wide nose leather; C42 "The nose line ranges from slightly concave to nearly straight."',
            'stage_notes' => 'C46 "Bengals grow slow, taking up to two years before reaching physical maturity."; kitten look (D) — CAT_SPEC §8',
        ],
        'traits' => [
            'size' => ['medium-large'],
            'build' => [['value' => 'sleek, long-bodied and very muscular, with robust bone', 'weight' => 2], 'athletic, muscular, hindquarters slightly higher than the shoulders'],
            'coat_length' => ['short, dense, close-lying, soft, pelt-like, slightly glittering'],
            // FIFe colours only (C47): brown (black), seal sepia / seal mink, snow, and silver. Brown first and highest (portrait default).
            'coat_color' => [
                ['value' => 'golden-brown with black markings', 'weight' => 4],
                ['value' => 'silver with black markings', 'weight' => 2],
                'pale ivory snow with seal-brown markings',
            ],
            // Always patterned (C42 / C43): spotted with rosettes or marbled, belly spotted; never white patches or vertical stripes.
            'coat_pattern' => [
                ['value' => 'with random rosettes and spots and a spotted belly', 'weight' => 3],
                ['value' => 'with horizontal flowing marbled markings and a spotted belly', 'weight' => 1],
            ],
            'ear_carriage' => ['medium to small, wide-based, with rounded tips, tilted slightly forward'],
            // Variety-dependent eye colour (C42 / C43); blue only with snow.
            'eye_color' => [
                ['value' => 'green-gold', 'weight' => 3, 'only_with' => ['coat_color' => ['golden-brown with black markings', 'silver with black markings']]],
                ['value' => 'green', 'only_with' => ['coat_color' => ['golden-brown with black markings', 'silver with black markings']]],
                ['value' => 'intense blue', 'only_with' => ['coat_color' => ['pale ivory snow with seal-brown markings']]],
            ],
            'tail' => ['thick, medium length, tapering to a rounded tip'],
        ],
        'features' => [
            'a broad, rounded wedge-shaped head, slightly small for the body, with high cheekbones and a full, broad muzzle',
            'large oval eyes, not bulging',
            'a domestic cat, not a wild cat',
        ],
        // CAT_SPEC §8 (D): the Bengal is fully grown at up to two years (C46).
        'stage_notes' => [
            'puppy' => 'As a Bengal kitten it is small and playful, its spots or marbling already visible on a slightly fuzzier coat',
            'young' => 'As a young Bengal it is not fully grown yet: lighter and less muscular than an adult (the breed is fully grown at up to two years)',
        ],
        // A growing Bengal: no adult "very muscular, robust bone" wording next to the stage note.
        'stage_overrides' => [
            'puppy' => [
                'build' => 'small, lithe, playful',
                'coat_length' => 'short, slightly fuzzy kitten',
                'tail' => 'short and thick, with a rounded tip',
                'features' => ['a small rounded wedge-shaped head', 'large oval eyes', 'a domestic cat, not a wild cat'],
            ],
            'young' => [
                'build' => 'long-bodied and lithe, not yet fully muscled',
                'features' => ['a broad, rounded wedge-shaped head with high cheekbones', 'large oval eyes, not bulging', 'a domestic cat, not a wild cat'],
            ],
        ],
        'prompt_order' => ['size', 'build', 'coat_length', 'coat_color', 'coat_pattern', 'ear_carriage', 'eye_color', 'tail'],
    ],
];
