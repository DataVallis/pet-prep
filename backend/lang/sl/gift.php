<?php

// M6-S01: besedila `message` za POST /api/gift/redeem (dnevniki / razhroščevanje — spletna stran preslika `error`).
return [
    'invalid_request' => 'Pošljite kodo bona in veljaven e-naslov.',
    'invalid_code' => 'Te kode bona ne prepoznamo.',
    'expired' => 'Ta bon je potekel (boni veljajo 12 mesecev od nakupa).',
    'refunded' => 'Ta bon ni več veljaven (vrnjen ali preklican).',
    'no_account' => 'S tem e-naslovom ni računa starša. Koda ni bila porabljena.',
    'already_redeemed' => 'Ta bon je že unovčen v drugem družinskem računu.',
    'rate_limited' => 'Preveč poskusov. Poskusite znova pozneje.',
    'upstream_error' => 'Bona trenutno ne moremo preveriti. Nič ni bilo porabljeno.',
    'unavailable' => 'Unovčitev bonov trenutno ni na voljo.',
];
