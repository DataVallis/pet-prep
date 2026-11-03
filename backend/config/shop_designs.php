<?php

/*
|--------------------------------------------------------------------------
| »Želje« item designs (M6-W03, David 2026-11-03)
|--------------------------------------------------------------------------
| Every durable item of the catalogue (`shop_items`: bed, bowls, toy_1, toy_2)
| comes in THREE designs per species. The child picks one when wishing, the
| parent buys exactly that design, and the pet's AI content shows that object.
|
| - `key`: stable, unique over the whole file (stored in pet_wishes.design,
|   pet_items.design, item_media.design). NEVER rename or reuse a key — add a
|   new one instead (an owned item keeps its key forever).
| - `name`: what the apps show (EN + SL; Accept-Language picks it).
| - `description`: the object, precisely, in English — used VERBATIM in every
|   prompt (the catalogue picture AND the pet-with-item picture / clip), so both
|   show the same object.
| - The FIRST design of an item is the default: items owned before designs
|   existed (backfill migration 2027_03_07_120000) and the welcome bundle (S12)
|   when the parent picks nothing.
|
| `treats` has no designs (one pack, WISHES_API): `treats.<species>.description`
| is the object of the treat content (one generation per look + stage).
|
| Each design gets ONE catalogue picture (the object alone), made once by
| `media:item-media` (ItemMediaService) — 24 pictures × 0.15 $ = 3.60 $ once.
*/

return [

    'dog' => [
        'bed' => [
            [
                'key' => 'dog_bed_round_grey',
                'name' => ['en' => 'Round grey bed', 'sl' => 'Okrogla siva postelja'],
                'description' => 'a round plush donut-shaped dog bed with a thick, raised, soft rim, covered in light grey fleece, with a slightly darker grey round cushion in the middle',
            ],
            [
                'key' => 'dog_bed_navy_bolster',
                'name' => ['en' => 'Navy cushion bed', 'sl' => 'Modra blazina'],
                'description' => 'a low rectangular dog bed with soft bolsters on three sides, navy-blue canvas cover with thin white piping along the edges, and a flat grey cushion',
            ],
            [
                'key' => 'dog_bed_wicker_checked',
                'name' => ['en' => 'Wicker basket', 'sl' => 'Pletena košara'],
                'description' => 'an oval natural-coloured wicker dog basket with a low front opening and a thick red-and-white checked cushion inside',
            ],
        ],
        'bowls' => [
            [
                'key' => 'dog_bowls_steel_stand',
                'name' => ['en' => 'Steel bowls', 'sl' => 'Kovinski skledi'],
                'description' => 'two shiny stainless-steel dog bowls side by side in a low black non-slip rubber stand, one bowl filled with brown dry kibble and one with clear water',
            ],
            [
                'key' => 'dog_bowls_blue_ceramic',
                'name' => ['en' => 'Blue ceramic bowls', 'sl' => 'Modri keramični skledi'],
                'description' => 'two glossy light-blue ceramic dog bowls standing side by side, each with a small white paw print on the outside, one filled with brown dry kibble and one with clear water',
            ],
            [
                'key' => 'dog_bowls_bamboo_stand',
                'name' => ['en' => 'Bowls in a wooden stand', 'sl' => 'Skledi v leseni stojali'],
                'description' => 'two cream-white bowls set into a low light bamboo-wood stand, one bowl filled with brown dry kibble and one with clear water',
            ],
        ],
        'toy_1' => [
            [
                'key' => 'dog_rope_red_white',
                'name' => ['en' => 'Red and white rope', 'sl' => 'Rdeče-bela vrv'],
                'description' => 'a thick braided cotton rope tug toy about 30 cm long with a big knot at each end, twisted in red and white stripes',
            ],
            [
                'key' => 'dog_rope_blue_ring',
                'name' => ['en' => 'Blue rope ring', 'sl' => 'Modri obroč iz vrvi'],
                'description' => 'a round braided cotton rope ring tug toy about 20 cm across, in navy blue and light grey',
            ],
            [
                'key' => 'dog_rope_rainbow',
                'name' => ['en' => 'Rainbow rope', 'sl' => 'Mavrična vrv'],
                'description' => 'a short braided cotton rope tug toy with a knot at each end, braided in bright rainbow colours: red, yellow, green and blue',
            ],
        ],
        'toy_2' => [
            [
                'key' => 'dog_squeaky_duck',
                'name' => ['en' => 'Yellow duck', 'sl' => 'Rumena raca'],
                'description' => 'a bright yellow rubber squeaky toy duck with an orange beak, about the size of a fist',
            ],
            [
                'key' => 'dog_squeaky_bone',
                'name' => ['en' => 'Plush bone', 'sl' => 'Plišasta kost'],
                'description' => 'a soft light-blue plush squeaky toy shaped like a cartoon bone, about 20 cm long, with white stitched seams',
            ],
            [
                'key' => 'dog_squeaky_ball',
                'name' => ['en' => 'Green squeaky ball', 'sl' => 'Zelena piskajoča žoga'],
                'description' => 'a bright green rubber squeaky ball the size of a tennis ball, covered in small soft rounded bumps',
            ],
        ],
    ],

    'cat' => [
        'bed' => [
            [
                'key' => 'cat_basket_wicker_cream',
                'name' => ['en' => 'Wicker basket', 'sl' => 'Pletena košara'],
                'description' => 'a small round natural-coloured wicker cat basket with low sides and a soft cream-white cushion inside',
            ],
            [
                'key' => 'cat_bed_grey_cave',
                'name' => ['en' => 'Grey cat cave', 'sl' => 'Siva hiška'],
                'description' => 'a dome-shaped heather-grey felt cat cave bed with a round entrance at the front and a soft grey cushion inside',
            ],
            [
                'key' => 'cat_bed_pink_plush',
                'name' => ['en' => 'Pink plush bed', 'sl' => 'Rožnata plišasta postelja'],
                'description' => 'a small round cat bed with a high soft rim, covered in dusty-pink fluffy faux fur',
            ],
        ],
        'bowls' => [
            [
                'key' => 'cat_bowls_white_gold',
                'name' => ['en' => 'White bowls', 'sl' => 'Bela skledi'],
                'description' => 'two shallow white ceramic cat bowls with a thin gold rim standing side by side, one filled with brown cat food and one with clear water',
            ],
            [
                'key' => 'cat_bowls_steel_wood',
                'name' => ['en' => 'Bowls in a wooden stand', 'sl' => 'Skledi v leseni stojali'],
                'description' => 'two small stainless-steel bowls set into a slightly raised light-wood stand, one filled with brown cat food and one with clear water',
            ],
            [
                'key' => 'cat_bowls_teal_fish',
                'name' => ['en' => 'Teal fish bowls', 'sl' => 'Turkizni skledi z ribico'],
                'description' => 'two shallow glossy teal ceramic cat bowls, each with a small white fish outline painted on the outside, one filled with brown cat food and one with clear water',
            ],
        ],
        'toy_1' => [
            [
                'key' => 'cat_mouse_grey_plush',
                'name' => ['en' => 'Grey plush mouse', 'sl' => 'Siva plišasta miška'],
                'description' => 'a small grey plush toy mouse with round pink ears, a pink nose and a long thin grey string tail',
            ],
            [
                'key' => 'cat_mouse_red_felt',
                'name' => ['en' => 'Red felt mouse', 'sl' => 'Rdeča miška iz filca'],
                'description' => 'a small bright red felt toy mouse with a yellow string tail and tiny black bead eyes',
            ],
            [
                'key' => 'cat_mouse_white_knit',
                'name' => ['en' => 'White knitted mouse', 'sl' => 'Bela pletena miška'],
                'description' => 'a small hand-knitted white wool toy mouse with a pink nose, pink ears and a short knitted tail',
            ],
        ],
        'toy_2' => [
            [
                'key' => 'cat_wand_rainbow_feathers',
                'name' => ['en' => 'Rainbow feather wand', 'sl' => 'Palica z mavričnimi peresi'],
                'description' => 'a cat teaser wand: a thin light wooden stick with a short string ending in a bunch of colourful feathers, red, blue and yellow',
            ],
            [
                'key' => 'cat_wand_pink_bell',
                'name' => ['en' => 'Pink feather wand with a bell', 'sl' => 'Palica z rožnatimi peresi in zvončkom'],
                'description' => 'a cat teaser wand: a thin white stick with a short string ending in fluffy pink feathers and a small golden bell',
            ],
            [
                'key' => 'cat_wand_peacock',
                'name' => ['en' => 'Peacock feather wand', 'sl' => 'Palica s pavjim peresom'],
                'description' => 'a cat teaser wand: a thin black stick with a short string ending in a long green-blue peacock feather with its round eye spot',
            ],
        ],
    ],

    'treats' => [
        'dog' => [
            'key' => 'dog_treats',
            'description' => 'a small light-brown bone-shaped dog biscuit treat',
        ],
        'cat' => [
            'key' => 'cat_treats',
            'description' => 'a small brown fish-shaped cat treat',
        ],
    ],
];
