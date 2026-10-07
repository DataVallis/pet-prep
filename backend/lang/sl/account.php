<?php

/*
 * Account deletion + data export (M2-08) — Slovenian (M1-18).
 * Parents are addressed formally ("vi").
 */

return [
    'deletion' => [
        // The word the parent types to unlock a deletion (the app shows the word of its language).
        'confirm_word' => 'IZBRIŠI',
        'confirm_word_invalid' => 'Za potrditev izbrisa vpišite :word.',
        'confirm_required' => 'Potrdite izbris.',
        'password_required' => 'Vpišite svoje geslo.',
    ],

    'export' => [
        'about' => 'Izvoz podatkov družine iz aplikacije PetPrep (GDPR čl. 15 in 20). '
            .'Časi so v UTC (ISO 8601), datumi (local_date) v časovnem pasu družine. '
            .'Povezave do slik in videov psov veljajo omejen čas (media[].expires_at, growth[] do growth_expires_at).',
        // Download file name: <prefix>-<family-local date>.json (ASCII only).
        'filename_prefix' => 'petprep-izvoz',
    ],
];
