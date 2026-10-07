<?php

/*
 * Push texts — Slovenian (M3-02, PRODUCT_SPEC §6/§7, DECISIONS 2026-10-05;
 * moved here from PushCopy in M1-18, byte-identical). No child or pet names
 * (Expo / APNs / FCM are third parties, lock screens are public).
 * Rendered per device in `device_push_tokens.locale` (App\Services\Push\PushCopy).
 */

return [
    'title' => 'PetPrep',

    'soft' => [
        'hunger' => 'Tvoj kuža te milo gleda in kaže na posodo s hrano.',
        'thirst' => 'Tvoj kuža te milo gleda in kaže na prazno posodo za vodo.',
        'hygiene' => 'Tvoj kuža te milo gleda in kaže na nered, ki ga je treba počistiti.',
    ],

    'critical' => [
        'hunger' => 'Če ga ne nahraniš v 30 minutah, bo zbolel.',
        'thirst' => 'Če mu ne daš vode v 30 minutah, bo zbolel.',
        'hygiene' => 'Kuža je naredil nered! Počisti ga čim prej, sicer bo zbolel.',
    ],

    'walk_reminder' => 'Tvoj kuža danes še ni bil na sprehodu in te čaka s povodcem. Gremo ven?',

    'parent_alarm' => 'Tvoj otrok danes ni poskrbel za psa.',

    'parent_alarm_detail' => [
        'hunger' => 'Kuža je že več kot uro brez hrane.',
        'thirst' => 'Kuža je že več kot uro brez vode.',
        'hygiene' => 'Nered že več kot uro ni počiščen.',
    ],

    'illness' => [
        'child' => [
            'hygiene' => 'Kuža je predolgo živel v neredu in je zbolel. 12 ur bo na opazovanju pri veterinarju.',
            'walk' => 'Kuža včeraj ni bil na sprehodu in je zbolel. 12 ur bo na opazovanju pri veterinarju.',
            'other' => 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.',
        ],
        'parent' => [
            'hygiene' => 'Kuža je zbolel, ker nered ni bil počiščen. 12 ur bo na opazovanju pri veterinarju.',
            'walk' => 'Kuža je zbolel, ker včeraj ni bil na sprehodu. 12 ur bo na opazovanju pri veterinarju.',
            'other' => 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.',
        ],
    ],

    'game_over' => [
        'child' => 'Kuža je odšel v zavetišče, ker zanj predolgo ni nihče poskrbel. Pogovori se s starši.',
        'parent' => 'Kuža je odšel v zavetišče, ker 24 ur ni dobil nujne skrbi. V aplikaciji izberite, kako naprej.',
    ],

    // M3-11 (PAYMENTS_SPEC): 6. dan preizkusa (samo starši) in zaklep do plačila.
    'trial_ending' => 'Preizkus se izteče jutri. Odklenite 12-tedenski izziv v aplikaciji, da se igra nadaljuje.',

    'payment_required' => [
        'child' => 'Igra počaka na starša. Tvoj kuža je na varnem in počiva.',
        'parent' => 'Brezplačni preizkus je končan. Kuža varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.',
    ],
];
