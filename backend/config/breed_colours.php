<?php

/*
|--------------------------------------------------------------------------
| Breed colours for the parent's colour picker (M5-R21, David 2026-10-14)
|--------------------------------------------------------------------------
|
| Per PAID breed (keyed by App\Enums\BreedType value) the coat colours a parent
| may pick for a new pet: `key` (stable id sent by the app, unique per breed),
| `value` (the exact `coat_color` option of config/breed_appearance.php — the
| DNA trait the AI media are generated from) and the picker labels `en` / `sl`.
|
| The list is EXACTLY the breed's `coat_color` options in breed_appearance.php
| (only colours the breed standard allows — never a colour the standard
| excludes); BreedColourSharedMediaTest keeps the two files in sync. Order = the
| appearance order (the most common colour first). Labels are short picker
| names, not breed facts. Free breeds (mutt, domestic cat) have no picker: they
| keep the shared look pool (M4-10).
|
*/

return [
    'border_collie' => [
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'tricolour', 'value' => 'black, white and tan tricolour', 'en' => 'Tricolour (black, white, tan)', 'sl' => 'Trobarven (črn, bel, rjavkast)'],
        ['key' => 'red-white', 'value' => 'red and white', 'en' => 'Red and white', 'sl' => 'Rdeče-bel'],
        ['key' => 'blue-white', 'value' => 'blue and white', 'en' => 'Blue and white', 'sl' => 'Modro-bel'],
        ['key' => 'blue-merle-white', 'value' => 'blue merle and white', 'en' => 'Blue merle and white', 'sl' => 'Modri merle z belo'],
        ['key' => 'red-merle-white', 'value' => 'red merle and white', 'en' => 'Red merle and white', 'sl' => 'Rdeči merle z belo'],
        ['key' => 'sable-white', 'value' => 'sable and white', 'en' => 'Sable and white', 'sl' => 'Sobolji z belo'],
    ],
    'labrador_retriever' => [
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'yellow', 'value' => 'yellow', 'en' => 'Yellow', 'sl' => 'Rumen'],
        ['key' => 'light-cream', 'value' => 'light cream yellow', 'en' => 'Light cream', 'sl' => 'Svetlo kremast'],
        ['key' => 'fox-red', 'value' => 'fox red yellow', 'en' => 'Fox red', 'sl' => 'Lisičje rdeč'],
        ['key' => 'chocolate', 'value' => 'chocolate brown', 'en' => 'Chocolate', 'sl' => 'Čokoladno rjav'],
        ['key' => 'light-liver', 'value' => 'light liver brown', 'en' => 'Light liver', 'sl' => 'Svetlo jetrno rjav'],
    ],
    'golden_retriever' => [
        ['key' => 'rich-gold', 'value' => 'rich gold', 'en' => 'Rich gold', 'sl' => 'Temno zlat'],
        ['key' => 'light-gold', 'value' => 'light gold', 'en' => 'Light gold', 'sl' => 'Svetlo zlat'],
        ['key' => 'deep-gold', 'value' => 'deep gold', 'en' => 'Deep gold', 'sl' => 'Globoko zlat'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'pale-cream', 'value' => 'pale cream', 'en' => 'Pale cream', 'sl' => 'Bledo kremast'],
    ],
    'french_bulldog' => [
        ['key' => 'fawn', 'value' => 'fawn', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'brindle', 'value' => 'brindle', 'en' => 'Brindle', 'sl' => 'Tigrast'],
        ['key' => 'light-fawn', 'value' => 'light fawn', 'en' => 'Light fawn', 'sl' => 'Svetlo plavo rjav'],
    ],
    'german_shepherd' => [
        ['key' => 'black-tan', 'value' => 'black and tan', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'black-gold', 'value' => 'black and gold', 'en' => 'Black and gold', 'sl' => 'Črn z zlatimi oznakami'],
        ['key' => 'sable', 'value' => 'sable', 'en' => 'Sable', 'sl' => 'Sobolji'],
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
    ],
    'cavalier_king_charles_spaniel' => [
        ['key' => 'blenheim', 'value' => 'Blenheim (rich chestnut and pearly white)', 'en' => 'Blenheim (chestnut and white)', 'sl' => 'Blenheim (kostanjev z belo)'],
        ['key' => 'tricolour', 'value' => 'tricolour (black, white and tan)', 'en' => 'Tricolour (black, white, tan)', 'sl' => 'Trobarven (črn, bel, rjavkast)'],
        ['key' => 'ruby', 'value' => 'ruby (whole rich red)', 'en' => 'Ruby (rich red)', 'sl' => 'Rubin (temno rdeč)'],
        ['key' => 'black-tan', 'value' => 'black and tan', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
    ],
    'beagle' => [
        ['key' => 'tricolour', 'value' => 'tricolour (black, tan and white)', 'en' => 'Tricolour (black, tan, white)', 'sl' => 'Trobarven (črn, rjavkast, bel)'],
        ['key' => 'tan-white', 'value' => 'tan and white', 'en' => 'Tan and white', 'sl' => 'Rjavkasto-bel'],
        ['key' => 'lemon-white', 'value' => 'lemon and white', 'en' => 'Lemon and white', 'sl' => 'Limonasto-bel'],
        ['key' => 'red-white', 'value' => 'red and white', 'en' => 'Red and white', 'sl' => 'Rdeče-bel'],
    ],
    'standard_poodle' => [
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'white', 'value' => 'solid white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'brown', 'value' => 'solid brown', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'grey', 'value' => 'solid grey', 'en' => 'Grey', 'sl' => 'Siv'],
        ['key' => 'fawn', 'value' => 'solid fawn', 'en' => 'Fawn (apricot)', 'sl' => 'Plavo rjav (marelični)'],
    ],
    'dachshund' => [
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'black-tan', 'value' => 'black and tan', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'chocolate-tan', 'value' => 'chocolate and tan', 'en' => 'Chocolate and tan', 'sl' => 'Čokoladen z rjavimi oznakami'],
    ],
    'australian_shepherd' => [
        ['key' => 'blue-merle', 'value' => 'blue merle', 'en' => 'Blue merle', 'sl' => 'Modri merle'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'red-merle', 'value' => 'red merle', 'en' => 'Red merle', 'sl' => 'Rdeči merle'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
    ],
    'havanese' => [
        ['key' => 'fawn', 'value' => 'fawn', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'havana-brown', 'value' => 'havana brown', 'en' => 'Havana brown', 'sl' => 'Havansko rjav'],
        ['key' => 'tobacco', 'value' => 'tobacco', 'en' => 'Tobacco', 'sl' => 'Tobačno rjav'],
        ['key' => 'reddish-brown', 'value' => 'reddish brown', 'en' => 'Reddish brown', 'sl' => 'Rdečkasto rjav'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
    ],
    'west_highland_white_terrier' => [
        ['key' => 'white', 'value' => 'pure white', 'en' => 'White', 'sl' => 'Bel'],
    ],
    'bernese_mountain_dog' => [
        ['key' => 'tricolour', 'value' => 'tricolour: jet black with rich tan on the cheeks, above the eyes, on the legs and chest, a symmetrical white blaze, white muzzle band and white chest', 'en' => 'Tricolour (black, rust, white)', 'sl' => 'Trobarven (črn, rjav, bel)'],
    ],
    'siberian_husky' => [
        ['key' => 'grey-white', 'value' => 'grey and white with a white face mask', 'en' => 'Grey and white', 'sl' => 'Sivo-bel'],
        ['key' => 'black-white', 'value' => 'black and white with a white face mask', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'red-white', 'value' => 'red and white with a white face mask', 'en' => 'Red and white', 'sl' => 'Rdeče-bel'],
        ['key' => 'agouti', 'value' => 'agouti', 'en' => 'Agouti (wild grey)', 'sl' => 'Agouti (divje siv)'],
        ['key' => 'sable-white', 'value' => 'sable and white', 'en' => 'Sable and white', 'sl' => 'Sobolji z belo'],
        ['key' => 'white', 'value' => 'pure white', 'en' => 'White', 'sl' => 'Bel'],
    ],
    'rottweiler' => [
        ['key' => 'black-tan', 'value' => 'black with clearly defined rich tan markings on the cheeks, muzzle, throat, chest, legs and above the eyes', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'black-mahogany', 'value' => 'black with clearly defined mahogany markings on the cheeks, muzzle, throat, chest, legs and above the eyes', 'en' => 'Black and mahogany', 'sl' => 'Črn z mahagonijevimi oznakami'],
    ],
    'bulldog' => [
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'fawn', 'value' => 'fawn', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'fawn-brindle', 'value' => 'fawn brindle', 'en' => 'Fawn brindle', 'sl' => 'Plavo rjav tigrast'],
    ],
    'german_shorthaired_pointer' => [
        ['key' => 'liver-roan', 'value' => 'liver roan (liver and white ticked) with a solid liver head and liver patches', 'en' => 'Liver roan', 'sl' => 'Jetrno rjav pikčast'],
        ['key' => 'liver', 'value' => 'solid liver', 'en' => 'Liver', 'sl' => 'Jetrno rjav'],
        ['key' => 'liver-white-ticked', 'value' => 'liver and white ticked', 'en' => 'Liver and white ticked', 'sl' => 'Jetrno-bel pikčast'],
        ['key' => 'black-roan', 'value' => 'black roan with a solid black head', 'en' => 'Black roan', 'sl' => 'Črn pikčast'],
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
    ],
    'yorkshire_terrier' => [
        ['key' => 'blue-tan', 'value' => 'dark steel blue body with rich bright tan head, chest and legs', 'en' => 'Steel blue and tan', 'sl' => 'Jekleno moder z rjavo'],
    ],
    'cane_corso' => [
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'grey', 'value' => 'solid grey (blue)', 'en' => 'Grey (blue)', 'sl' => 'Siv (moder)'],
        ['key' => 'black-brindle', 'value' => 'black brindle', 'en' => 'Black brindle', 'sl' => 'Črn tigrast'],
        ['key' => 'grey-brindle', 'value' => 'grey (blue) brindle', 'en' => 'Grey brindle', 'sl' => 'Siv tigrast'],
        ['key' => 'fawn', 'value' => 'fawn with a black mask not beyond the eyes', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'stag-red', 'value' => 'stag red with a dark mask', 'en' => 'Stag red', 'sl' => 'Jelenje rdeč'],
        ['key' => 'dark-wheat', 'value' => 'dark wheat', 'en' => 'Dark wheat', 'sl' => 'Temno pšeničen'],
    ],
    'pembroke_welsh_corgi' => [
        ['key' => 'red-white', 'value' => 'red with white markings on legs, chest and neck', 'en' => 'Red and white', 'sl' => 'Rdeče-bel'],
        ['key' => 'sable-white', 'value' => 'sable with white markings', 'en' => 'Sable and white', 'sl' => 'Sobolji z belo'],
        ['key' => 'fawn-white', 'value' => 'fawn with white markings', 'en' => 'Fawn and white', 'sl' => 'Plavo rjav z belo'],
        ['key' => 'tricolour', 'value' => 'black and tan with white markings', 'en' => 'Tricolour (black, tan, white)', 'sl' => 'Trobarven (črn, rjavkast, bel)'],
    ],
    'dobermann' => [
        ['key' => 'black-rust', 'value' => 'black with clearly defined rust red tan markings', 'en' => 'Black and rust', 'sl' => 'Črn z rjastimi oznakami'],
        ['key' => 'brown-rust', 'value' => 'brown with clearly defined rust red tan markings', 'en' => 'Brown and rust', 'sl' => 'Rjav z rjastimi oznakami'],
    ],
    'boxer' => [
        ['key' => 'fawn', 'value' => 'golden to deer-red fawn with a black mask', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'brindle', 'value' => 'brindle (black stripes on fawn) with a black mask', 'en' => 'Brindle', 'sl' => 'Tigrast'],
    ],
    'miniature_schnauzer' => [
        ['key' => 'pepper-salt', 'value' => 'pepper and salt (banded grey) with a dark facial mask', 'en' => 'Pepper and salt', 'sl' => 'Poper in sol'],
        ['key' => 'black-silver', 'value' => 'black and silver', 'en' => 'Black and silver', 'sl' => 'Črno-srebrn'],
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
    ],
    'shih_tzu' => [
        ['key' => 'gold-white', 'value' => 'gold and white', 'en' => 'Gold and white', 'sl' => 'Zlato-bel'],
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'liver-white', 'value' => 'liver and white', 'en' => 'Liver and white', 'sl' => 'Jetrno-bel'],
    ],
    'great_dane' => [
        ['key' => 'fawn', 'value' => 'fawn with a black mask', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'brindle', 'value' => 'brindle with a black mask', 'en' => 'Brindle', 'sl' => 'Tigrast'],
        ['key' => 'harlequin', 'value' => 'harlequin (white with torn black patches)', 'en' => 'Harlequin', 'sl' => 'Harlekin'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'blue', 'value' => 'steel blue', 'en' => 'Steel blue', 'sl' => 'Jekleno moder'],
    ],
    'pomeranian' => [
        ['key' => 'orange', 'value' => 'bright self-coloured orange', 'en' => 'Orange', 'sl' => 'Oranžen'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'brown', 'value' => 'brown', 'en' => 'Brown', 'sl' => 'Rjav'],
    ],
    'boston_terrier' => [
        ['key' => 'brindle-white', 'value' => 'brindle and white', 'en' => 'Brindle and white', 'sl' => 'Tigrasto-bel'],
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'seal-white', 'value' => 'seal and white', 'en' => 'Seal and white', 'sl' => 'Tjulnje rjav z belo'],
    ],
    'miniature_american_shepherd' => [
        ['key' => 'blue-merle', 'value' => 'blue merle', 'en' => 'Blue merle', 'sl' => 'Modri merle'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'red-merle', 'value' => 'red merle', 'en' => 'Red merle', 'sl' => 'Rdeči merle'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
    ],
    'chihuahua' => [
        ['key' => 'fawn', 'value' => 'fawn', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'black-tan', 'value' => 'black and tan', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'chocolate', 'value' => 'chocolate', 'en' => 'Chocolate', 'sl' => 'Čokoladen'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'fawn-white', 'value' => 'fawn and white', 'en' => 'Fawn and white', 'sl' => 'Plavo rjav z belo'],
    ],
    'english_springer_spaniel' => [
        ['key' => 'liver-white', 'value' => 'liver and white', 'en' => 'Liver and white', 'sl' => 'Jetrno-bel'],
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'liver-white-tan', 'value' => 'liver and white with tan markings', 'en' => 'Liver, white and tan', 'sl' => 'Jetrno-bel z rjavimi oznakami'],
        ['key' => 'black-white-tan', 'value' => 'black and white with tan markings', 'en' => 'Black, white and tan', 'sl' => 'Črno-bel z rjavimi oznakami'],
    ],
    'shetland_sheepdog' => [
        ['key' => 'sable-white', 'value' => 'sable and white (golden sable with a white collar, frill, chest, legs and tail tip)', 'en' => 'Sable and white', 'sl' => 'Sobolji z belo'],
        ['key' => 'tricolour', 'value' => 'tricolour (black with tan markings and a white collar, frill, chest, legs and tail tip)', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
        ['key' => 'blue-merle', 'value' => 'blue merle (silvery blue marbled with black, with white and tan markings)', 'en' => 'Blue merle', 'sl' => 'Modri merle'],
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'black-tan', 'value' => 'black and tan', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
    ],
    'brittany' => [
        ['key' => 'orange-white', 'value' => 'orange and white (clear white with bright orange patches)', 'en' => 'Orange and white', 'sl' => 'Oranžno-bel'],
        ['key' => 'liver-white', 'value' => 'liver and white (clear white with liver-brown patches)', 'en' => 'Liver and white', 'sl' => 'Jetrno-bel'],
        ['key' => 'orange-roan', 'value' => 'orange roan (white finely ticked with orange)', 'en' => 'Orange roan', 'sl' => 'Oranžni roan'],
        ['key' => 'liver-roan', 'value' => 'liver roan', 'en' => 'Liver roan', 'sl' => 'Jetrni roan'],
        ['key' => 'black-white', 'value' => 'black and white', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'tricolour', 'value' => 'tricolour (black and white with orange markings over the eyes, on the muzzle, legs and chest)', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
    ],
    'american_cocker_spaniel' => [
        ['key' => 'buff', 'value' => 'buff (solid light golden cream)', 'en' => 'Buff', 'sl' => 'Svetlo zlato kremast'],
        ['key' => 'black', 'value' => 'black (solid black)', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'black-tan', 'value' => 'black and tan (black with small tan points over the eyes, on the muzzle, chest and legs)', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'red', 'value' => 'red (solid deep red)', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'chocolate', 'value' => 'chocolate (solid brown)', 'en' => 'Chocolate', 'sl' => 'Čokoladno rjav'],
        ['key' => 'black-white', 'value' => 'black and white parti-colour', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'red-white', 'value' => 'red and white parti-colour', 'en' => 'Red and white', 'sl' => 'Rdeče-bel'],
        ['key' => 'tricolour', 'value' => 'tricolour (black and white with tan points)', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
    ],
    // M5-R10-38: FCI S313 "Only fawn with black overlay and with black mask."; AKC S315 "rich fawn to mahogany".
    'belgian_malinois' => [
        ['key' => 'fawn', 'value' => 'fawn with black overlay (black-tipped hairs) and a pronounced black mask', 'en' => 'Fawn with black mask', 'sl' => 'Plav s črno masko'],
        ['key' => 'mahogany', 'value' => 'mahogany with black overlay (black-tipped hairs) and a pronounced black mask', 'en' => 'Mahogany with black mask', 'sl' => 'Mahagonij s črno masko'],
    ],
    // M5-R10-39: FCI S320 "Generally black, white and tan (tri-colour); lemon and white (bi-colour)".
    'basset_hound' => [
        ['key' => 'tricolour', 'value' => 'black, white and tan tricolour', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
        ['key' => 'lemon-white', 'value' => 'lemon and white bicolour', 'en' => 'Lemon and white', 'sl' => 'Limonasto-bel'],
    ],
    // M5-R10-40: FCI S327 "Various shades of russet gold and dark sandy gold (semmelgelb)."
    'hungarian_vizsla' => [
        ['key' => 'russet-gold', 'value' => 'russet gold', 'en' => 'Russet gold', 'sl' => 'Rdečkasto zlat'],
        ['key' => 'dark-sandy-gold', 'value' => 'dark sandy gold', 'en' => 'Dark sandy gold', 'sl' => 'Temno peščeno zlat'],
    ],
    // M5-R10-41: AKC S343 "Solid colors are black, liver or shades of red." + parti-colours / roans with white
    // (AKC S343, FCI S336). Never merle.
    'english_cocker_spaniel' => [
        ['key' => 'golden', 'value' => 'golden (solid golden red)', 'en' => 'Golden', 'sl' => 'Zlat'],
        ['key' => 'black', 'value' => 'black (solid black)', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'liver', 'value' => 'liver (solid chocolate brown)', 'en' => 'Liver', 'sl' => 'Čokoladno rjav'],
        ['key' => 'black-tan', 'value' => 'black and tan (black with tan markings over the eyes, on the muzzle, chest and legs)', 'en' => 'Black and tan', 'sl' => 'Črn z rjavimi oznakami'],
        ['key' => 'blue-roan', 'value' => 'blue roan (black and white roaned)', 'en' => 'Blue roan', 'sl' => 'Modro mešan'],
        ['key' => 'orange-roan', 'value' => 'orange roan (orange and white roaned)', 'en' => 'Orange roan', 'sl' => 'Oranžno mešan'],
        ['key' => 'black-white', 'value' => 'black and white parti-colour', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'tricolour', 'value' => 'tricolour (black and white with tan markings)', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
    ],
    // M5-R10-42: FCI S346 "Colour: Pure white, a pale ivory tinge is permitted."; AKC S349 "Color, pure white."
    'maltese' => [
        ['key' => 'white', 'value' => 'pure white', 'en' => 'White', 'sl' => 'Bel'],
    ],
    // M5-R10-43: FCI S354 "Silver, apricot, fawn or black." (fawn / apricot / silver with the black mask).
    'pug' => [
        ['key' => 'fawn', 'value' => 'fawn with a black mask and black ears', 'en' => 'Fawn', 'sl' => 'Plav'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'apricot', 'value' => 'apricot with a black mask and black ears', 'en' => 'Apricot', 'sl' => 'Marelični'],
        ['key' => 'silver', 'value' => 'silver with a black mask and black ears', 'en' => 'Silver', 'sl' => 'Srebrn'],
    ],
    // M5-R10-44: FCI S364 sable / tricolour / blue merle; RKC S366 "Sable and white, Tricolour and Blue Merle."
    'rough_collie' => [
        ['key' => 'sable-white', 'value' => 'sable and white', 'en' => 'Sable and white', 'sl' => 'Sobolji z belo'],
        ['key' => 'tricolour', 'value' => 'tricolour (black with tan markings and white)', 'en' => 'Tricolour', 'sl' => 'Trobarven'],
        ['key' => 'blue-merle-white', 'value' => 'blue merle and white', 'en' => 'Blue merle and white', 'sl' => 'Modri merle z belo'],
    ],
    // M5-R10-45: FCI S371 "Apricot, fawn or brindle."; AKC S373 dark mask.
    'mastiff' => [
        ['key' => 'fawn', 'value' => 'fawn with a dark mask, dark ears and a black nose', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'apricot', 'value' => 'apricot with a dark mask, dark ears and a black nose', 'en' => 'Apricot', 'sl' => 'Marelični'],
        ['key' => 'brindle', 'value' => 'brindle with a dark mask, dark ears and a black nose', 'en' => 'Brindle', 'sl' => 'Tigrast'],
    ],
    // M5-R10-46: FCI S377 "Colour: Red, black and tan, sesame, black sesame, red sesame."; AKC S380 (always with urajiro).
    'shiba_inu' => [
        ['key' => 'red', 'value' => 'red with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'black-tan', 'value' => 'black and tan with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'en' => 'Black and tan', 'sl' => 'Črn s porjavelimi oznakami'],
        ['key' => 'sesame', 'value' => 'sesame (black-tipped hairs on a red background) with cream-white urajiro on the muzzle sides, cheeks, throat, chest, belly and inner legs', 'en' => 'Sesame', 'sl' => 'Sezamov'],
    ],
    // M5-R10-47: FCI S385 / RKC S388 / AKC S389 "Light wheaten to red wheaten." (uniform colour; red wheaten = portrait).
    'rhodesian_ridgeback' => [
        ['key' => 'red-wheaten', 'value' => 'red wheaten, uniform colour, at most a little white on the chest and toes', 'en' => 'Red wheaten', 'sl' => 'Rdeče pšenični'],
        ['key' => 'light-wheaten', 'value' => 'light wheaten, uniform colour, at most a little white on the chest and toes', 'en' => 'Light wheaten', 'sl' => 'Svetlo pšenični'],
    ],
    // M5-R10-48: FCI S396 "All colours are admitted on a coat with a white background." / AKC-approved S399 "white with patches of any color(s)" (red = portrait).
    'papillon' => [
        ['key' => 'red-white', 'value' => 'white with red patches, colour covering both ears and over both eyes, white blaze', 'en' => 'White and red', 'sl' => 'Belo-rdeč'],
        ['key' => 'sable-white', 'value' => 'white with sable patches, colour covering both ears and over both eyes, white blaze', 'en' => 'White and sable', 'sl' => 'Belo-sobolj'],
        ['key' => 'tricolour', 'value' => 'tricolour: white with black patches and tan markings, colour covering both ears and over both eyes, white blaze', 'en' => 'Tricolour', 'sl' => 'Tribarven'],
        ['key' => 'black-white', 'value' => 'white with black patches, colour covering both ears and over both eyes, white blaze', 'en' => 'White and black', 'sl' => 'Belo-črn'],
        ['key' => 'lemon-white', 'value' => 'white with lemon patches, colour covering both ears and over both eyes, white blaze', 'en' => 'White and lemon', 'sl' => 'Belo-limonast'],
    ],
    // M5-R10-49: RKC standard S403 "Black, white, various shades of brown, black and white, brown and white." (black = portrait).
    'portuguese_water_dog' => [
        ['key' => 'black', 'value' => 'solid black with a black nose', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'black-white', 'value' => 'black with white on the chin, chest and feet, black nose', 'en' => 'Black and white', 'sl' => 'Črno-bel'],
        ['key' => 'brown', 'value' => 'brown with a liver nose', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'brown-white', 'value' => 'brown with white on the chin, chest and feet, liver nose', 'en' => 'Brown and white', 'sl' => 'Rjavo-bel'],
        ['key' => 'white', 'value' => 'white with a black nose', 'en' => 'White', 'sl' => 'Bel'],
    ],
    // M5-R10-50: FCI S410 "Pure white." (adult).
    'bichon_frise' => [
        ['key' => 'white', 'value' => 'pure white', 'en' => 'White', 'sl' => 'Bel'],
    ],
    // M5-R10-51: FCI S420 "Color: Negro, blanco y negro y castaño." (AKC S421 gray not used).
    'newfoundland' => [
        ['key' => 'black', 'value' => 'solid black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'landseer', 'value' => 'white and black (Landseer): white body with a black head and white blaze, black saddle and black rump', 'en' => 'Landseer (white and black)', 'sl' => 'Landseer (belo-črn)'],
        ['key' => 'brown', 'value' => 'solid brown (chocolate to bronze)', 'en' => 'Brown', 'sl' => 'Rjav'],
    ],
    // M5-R10-52: FCI S428 "Black spotted variety with black spots, liver spotted variety with brown spots."
    'dalmatian' => [
        ['key' => 'black-spotted', 'value' => 'pure white with round, clearly defined black spots', 'en' => 'Black-spotted', 'sl' => 'Črno pikast'],
        ['key' => 'liver-spotted', 'value' => 'pure white with round, clearly defined liver (brown) spots', 'en' => 'Liver-spotted', 'sl' => 'Rjavo pikast'],
    ],
    // M5-R10-53: KC standard S436 — Blue (speckled / mottled, head markings) and Red Speckle.
    'australian_cattle_dog' => [
        ['key' => 'blue-speckled', 'value' => 'blue speckled with black and tan markings on the head', 'en' => 'Blue speckled', 'sl' => 'Modro pegast'],
        ['key' => 'red-speckle', 'value' => 'even red speckle with darker red markings on the head', 'en' => 'Red speckle', 'sl' => 'Rdeče pegast'],
    ],
    // M5-R10-54: KC standard S443 "Any colour or mixture of colours, except merle." — common standard colours.
    'whippet' => [
        ['key' => 'fawn', 'value' => 'fawn', 'en' => 'Fawn', 'sl' => 'Plavo rjav'],
        ['key' => 'brindle', 'value' => 'brindle', 'en' => 'Brindle', 'sl' => 'Tigrast'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'blue', 'value' => 'blue (grey)', 'en' => 'Blue', 'sl' => 'Moder (siv)'],
        ['key' => 'fawn-white', 'value' => 'fawn and white parti-colour', 'en' => 'Fawn and white', 'sl' => 'Plavo rjav z belo'],
        ['key' => 'brindle-white', 'value' => 'white with brindle patches', 'en' => 'Brindle and white', 'sl' => 'Tigrasto-bel'],
    ],
    // M5-R10-55: FCI standard S449 — steel grey with liver patches (preferred), liver roan, solid liver, white and
    // liver, white and orange; never black (AKC S451, UKC S452).
    'wirehaired_pointing_griffon' => [
        ['key' => 'steel-grey-liver', 'value' => 'steel grey with brown (liver) patches', 'en' => 'Steel grey with liver', 'sl' => 'Jekleno siv z jetrno rjavimi lisami'],
        ['key' => 'liver-roan', 'value' => 'liver roan', 'en' => 'Liver roan', 'sl' => 'Jetrni roan'],
        ['key' => 'liver', 'value' => 'solid liver (brown)', 'en' => 'Liver', 'sl' => 'Jetrno rjav'],
        ['key' => 'liver-white', 'value' => 'white and brown (liver)', 'en' => 'Liver and white', 'sl' => 'Jetrno-bel'],
        ['key' => 'orange-white', 'value' => 'white and orange', 'en' => 'Orange and white', 'sl' => 'Oranžno-bel'],
    ],
    // M5-R10-56: FCI standard No. 263 S458 — brown, sedge, deadgrass (self-coloured preferred, no colour preferred over
    // another); never black, no white except a small spot on the chest or toes.
    'chesapeake_bay_retriever' => [
        ['key' => 'brown', 'value' => 'solid brown', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'sedge', 'value' => 'solid sedge (reddish gold)', 'en' => 'Sedge', 'sl' => 'Rdečkasto zlat'],
        ['key' => 'deadgrass', 'value' => 'solid deadgrass (straw to dull tan)', 'en' => 'Deadgrass', 'sl' => 'Barve suhe trave'],
    ],
    // M5-R10-57: FCI standard No. 212 S464 — pure white, cream, white and biscuit (never beige, no other colour).
    'samoyed' => [
        ['key' => 'white', 'value' => 'pure white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Krem'],
        ['key' => 'white-biscuit', 'value' => 'white and biscuit', 'en' => 'White and biscuit', 'sl' => 'Belo-piškotni'],
    ],
    // M5-R10-58: Kennel Club standard S473 — black, wheaten, brindle of any shade (no other colour).
    'scottish_terrier' => [
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'brindle', 'value' => 'brindle', 'en' => 'Brindle', 'sl' => 'Tigrast'],
        ['key' => 'wheaten', 'value' => 'wheaten', 'en' => 'Wheaten', 'sl' => 'Pšenično rumen'],
    ],
    'maine_coon' => [
        ['key' => 'brown', 'value' => 'brown', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'silver', 'value' => 'silver', 'en' => 'Silver', 'sl' => 'Srebrn'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'blue-grey', 'value' => 'blue-grey', 'en' => 'Blue-grey', 'sl' => 'Modro siv'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'tortoiseshell', 'value' => 'tortoiseshell black and red', 'en' => 'Tortoiseshell', 'sl' => 'Želvovinast'],
    ],
    'british_shorthair' => [
        ['key' => 'blue-grey', 'value' => 'blue-grey', 'en' => 'Blue-grey', 'sl' => 'Modro siv'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'lilac', 'value' => 'lilac', 'en' => 'Lilac', 'sl' => 'Lila'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'silver', 'value' => 'silver', 'en' => 'Silver', 'sl' => 'Srebrn'],
        ['key' => 'tortoiseshell', 'value' => 'tortoiseshell black and red', 'en' => 'Tortoiseshell', 'sl' => 'Želvovinast'],
    ],
    'ragdoll' => [
        ['key' => 'seal-point', 'value' => 'seal point (a pale cream body with dark seal-brown face mask, ears, legs and tail)', 'en' => 'Seal point', 'sl' => 'Seal point (temne točke)'],
        ['key' => 'blue-point', 'value' => 'blue point (a pale bluish-white body with grey-blue face mask, ears, legs and tail)', 'en' => 'Blue point', 'sl' => 'Blue point (modre točke)'],
        ['key' => 'chocolate-point', 'value' => 'chocolate point (an ivory body with milk-chocolate face mask, ears, legs and tail)', 'en' => 'Chocolate point', 'sl' => 'Chocolate point (čokoladne točke)'],
        ['key' => 'lilac-point', 'value' => 'lilac point (a white body with pinkish-grey face mask, ears, legs and tail)', 'en' => 'Lilac point', 'sl' => 'Lilac point (lila točke)'],
        ['key' => 'red-point', 'value' => 'red point (a creamy white body with reddish-orange face mask, ears, legs and tail)', 'en' => 'Red point', 'sl' => 'Red point (rdeče točke)'],
        ['key' => 'cream-point', 'value' => 'cream point (a white body with soft cream face mask, ears, legs and tail)', 'en' => 'Cream point', 'sl' => 'Cream point (kremaste točke)'],
    ],
    'siberian_cat' => [
        ['key' => 'brown', 'value' => 'brown', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'silver', 'value' => 'silver', 'en' => 'Silver', 'sl' => 'Srebrn'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'blue-grey', 'value' => 'blue-grey', 'en' => 'Blue-grey', 'sl' => 'Modro siv'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'tortoiseshell', 'value' => 'tortoiseshell black and red', 'en' => 'Tortoiseshell', 'sl' => 'Želvovinast'],
    ],
    'norwegian_forest_cat' => [
        ['key' => 'brown', 'value' => 'brown', 'en' => 'Brown', 'sl' => 'Rjav'],
        ['key' => 'silver', 'value' => 'silver', 'en' => 'Silver', 'sl' => 'Srebrn'],
        ['key' => 'red', 'value' => 'red', 'en' => 'Red', 'sl' => 'Rdeč'],
        ['key' => 'black', 'value' => 'black', 'en' => 'Black', 'sl' => 'Črn'],
        ['key' => 'blue-grey', 'value' => 'blue-grey', 'en' => 'Blue-grey', 'sl' => 'Modro siv'],
        ['key' => 'cream', 'value' => 'cream', 'en' => 'Cream', 'sl' => 'Kremast'],
        ['key' => 'white', 'value' => 'white', 'en' => 'White', 'sl' => 'Bel'],
        ['key' => 'tortoiseshell', 'value' => 'tortoiseshell black and red', 'en' => 'Tortoiseshell', 'sl' => 'Želvovinast'],
    ],
    'bengal' => [
        ['key' => 'brown-spotted', 'value' => 'golden-brown with black markings', 'en' => 'Brown spotted', 'sl' => 'Rjavo lisast'],
        ['key' => 'silver-spotted', 'value' => 'silver with black markings', 'en' => 'Silver spotted', 'sl' => 'Srebrno lisast'],
        ['key' => 'snow', 'value' => 'pale ivory snow with seal-brown markings', 'en' => 'Snow', 'sl' => 'Snežni'],
    ],
];
