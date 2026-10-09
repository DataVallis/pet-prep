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

    // M3-12 (David 7. 10. 2026): opomnik nikoli ne zahteva dejanja, ki ga aplikacija
    // trenutno zavrne — namesto »nahrani zdaj« pove, kdaj bo mogoče, ali »najprej počisti«.
    'wait' => [
        'hunger' => 'Tvoj kuža postaja lačen. Naslednji obrok je ob :time — ne pozabi nanj.',
        'thirst' => 'Tvoj kuža je žejen. Vodo mu lahko spet daš ob :time — ne pozabi nanj.',
    ],

    'clean_first' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej je treba počistiti nered. Potem ga lahko nahraniš.',
        'thirst' => 'Tvoj kuža je žejen, a najprej je treba počistiti nered. Potem mu lahko daš vodo.',
    ],

    // M5-R06-06b (David 9. 10. 2026): hrana in voda sta zavrnjeni, dokler je odprt pregrizen
    // predmet, a čiščenje ga ne razreši — pospravi se z igračo (»najprej pospravi«).
    'tidy_first' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej pospravi, kar je pregriznil, in mu daj igračo. Potem ga lahko nahraniš.',
        'thirst' => 'Tvoj kuža je žejen, a najprej pospravi, kar je pregriznil, in mu daj igračo. Potem mu lahko daš vodo.',
    ],

    'clean_and_tidy_first' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej počisti nered, pospravi, kar je pregriznil, in mu daj igračo. Potem ga lahko nahraniš.',
        'thirst' => 'Tvoj kuža je žejen, a najprej počisti nered, pospravi, kar je pregriznil, in mu daj igračo. Potem mu lahko daš vodo.',
    ],

    // M5-R06-06b (David 9. 10. 2026, M3-12): isti prvi korak, a hrana / voda je tudi po čiščenju
    // mogoča šele kasneje danes — čas namesto »potem ga lahko nahraniš«.
    'clean_first_wait' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej je treba počistiti nered. Naslednji obrok je ob :time.',
        'thirst' => 'Tvoj kuža je žejen, a najprej je treba počistiti nered. Vodo mu lahko spet daš ob :time.',
    ],

    'tidy_first_wait' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej pospravi, kar je pregriznil, in mu daj igračo. Naslednji obrok je ob :time.',
        'thirst' => 'Tvoj kuža je žejen, a najprej pospravi, kar je pregriznil, in mu daj igračo. Vodo mu lahko spet daš ob :time.',
    ],

    'clean_and_tidy_first_wait' => [
        'hunger' => 'Tvoj kuža je lačen, a najprej počisti nered, pospravi, kar je pregriznil, in mu daj igračo. Naslednji obrok je ob :time.',
        'thirst' => 'Tvoj kuža je žejen, a najprej počisti nered, pospravi, kar je pregriznil, in mu daj igračo. Vodo mu lahko spet daš ob :time.',
    ],

    // M3-12: odprt pregrizen predmet se ne počisti z drgnjenjem, ampak pospravi z igračo.
    'tidy' => [
        'soft' => 'Tvoj kuža je nekaj pregriznil. Pospravi in mu daj igračo.',
        'critical' => 'Kuža je pregriznil copat! Pospravi in mu daj igračo čim prej, sicer bo zbolel.',
    ],

    'clean_and_tidy' => [
        'soft' => 'Tvoj kuža te čaka: počisti nered, pospravi pregrizeno in mu daj igračo.',
        'critical' => 'Počisti nered, pospravi pregrizeno in kužku daj igračo čim prej, sicer bo zbolel.',
    ],

    'walk_reminder' => 'Tvoj kuža danes še ni bil na sprehodu in te čaka s povodcem. Gremo ven?',

    // David 9. 10. 2026: staršem vikamo (brand/README, glas) — pri obeh vrstah.
    'parent_alarm' => 'Vaš otrok danes ni poskrbel za psa.',

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
        'parent_no_trial' => 'Kuža varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.',
    ],

    /*
     * M5-R06-06 (načrt T8, CAT_SPEC §6 / §9): mačja besedila. »Muca« je ženskega spola
     * (»muca je lačna«, »bo zbolela«, »jo / ji«) — zato ločeni ključi, ne zamenjava besede.
     * PushCopy za mačko izbere `cat.<ključ>`, na pasji ključ pade le pri ključih samo za psa
     * (sprehod, grizenje). Osnutek Claude, čaka Davidov pregled pred vklopom mačk (R06-09) —
     * docs/product/CAT_TEXTS_REVIEW.md. En samostalnik za vse faze, kot pri psu.
     */
    'cat' => [
        'soft' => [
            'hunger' => 'Tvoja muca te milo gleda in sedi ob prazni posodi za hrano.',
            'thirst' => 'Tvoja muca te milo gleda in sedi ob prazni posodi za vodo.',
            'hygiene' => 'Tvoja muca te milo gleda — zraven peska je nered, ki ga je treba počistiti.',
        ],

        'critical' => [
            'hunger' => 'Če muce ne nahraniš v 30 minutah, bo zbolela.',
            'thirst' => 'Če muci ne daš vode v 30 minutah, bo zbolela.',
            'hygiene' => 'Muca je naredila nered zraven peska! Počisti ga čim prej, sicer bo zbolela.',
        ],

        // M3-12: naslednji obrok / voda je mogoča kasneje danes.
        'wait' => [
            'hunger' => 'Tvoja muca postaja lačna. Naslednji obrok je ob :time — ne pozabi nanj.',
            'thirst' => 'Tvoja muca je žejna. Vodo ji lahko spet daš ob :time — ne pozabi nanjo.',
        ],

        // M3-12: hrana in voda sta zavrnjeni, dokler je zraven peska odprt nered.
        'clean_first' => [
            'hunger' => 'Tvoja muca je lačna, a najprej je treba počistiti nered. Potem jo lahko nahraniš.',
            'thirst' => 'Tvoja muca je žejna, a najprej je treba počistiti nered. Potem ji lahko daš vodo.',
        ],

        // M3-12 (QA m3, R06-05): odprto je samo praskanje — čiščenje ga ne razreši, praskalnik ga.
        'scratcher_first' => [
            'hunger' => 'Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš.',
            'thirst' => 'Tvoja muca je žejna, a najprej jo odnesi na praskalnik in jo pohvali. Potem ji lahko daš vodo.',
        ],

        // M3-12: praskanje in nered zraven peska.
        'clean_and_scratcher_first' => [
            'hunger' => 'Tvoja muca je lačna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš.',
            'thirst' => 'Tvoja muca je žejna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Potem ji lahko daš vodo.',
        ],

        // M3-12 (QA R06-06 m1): isti prvi koraki, a hrana / voda je tudi po čiščenju mogoča šele
        // kasneje danes — brez »potem jo lahko nahraniš«, namesto tega čas.
        'clean_first_wait' => [
            'hunger' => 'Tvoja muca je lačna, a najprej je treba počistiti nered. Naslednji obrok je ob :time.',
            'thirst' => 'Tvoja muca je žejna, a najprej je treba počistiti nered. Vodo ji lahko spet daš ob :time.',
        ],

        'scratcher_first_wait' => [
            'hunger' => 'Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Naslednji obrok je ob :time.',
            'thirst' => 'Tvoja muca je žejna, a najprej jo odnesi na praskalnik in jo pohvali. Vodo ji lahko spet daš ob :time.',
        ],

        'clean_and_scratcher_first_wait' => [
            'hunger' => 'Tvoja muca je lačna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Naslednji obrok je ob :time.',
            'thirst' => 'Tvoja muca je žejna, a najprej počisti nered, nato jo odnesi na praskalnik in jo pohvali. Vodo ji lahko spet daš ob :time.',
        ],

        // M5-R06-05: muca je opraskala kavč — razreši se z odnosom na praskalnik in pohvalo, ne s čiščenjem.
        'scratcher' => [
            'soft' => 'Tvoja muca je opraskala kavč. Odnesi jo na praskalnik in jo pohvali.',
            'critical' => 'Muca je opraskala kavč! Čim prej jo odnesi na praskalnik in jo pohvali, sicer bo zbolela.',
        ],

        'clean_and_scratcher' => [
            'soft' => 'Tvoja muca te čaka: počisti nered, nato jo odnesi na praskalnik in jo pohvali.',
            'critical' => 'Čim prej počisti nered, muco odnesi na praskalnik in jo pohvali, sicer bo zbolela.',
        ],

        // M5-R06-04: dnevni opomnik za igro (namesto sprehoda).
        'play_reminder' => 'Tvoja muca se danes še ni igrala in čaka na palico s peresom. Se greva igrat?',

        // M5-R06-05: pesek je treba počistiti v naslednji uri.
        'litter_reminder' => 'Tvoja muca je bila na pesku. Počisti ga čim prej, preden začne smrdeti.',

        'parent_alarm' => 'Vaš otrok danes ni poskrbel za muco.',

        'parent_alarm_detail' => [
            'hunger' => 'Muca je že več kot uro brez hrane.',
            'thirst' => 'Muca je že več kot uro brez vode.',
            'hygiene' => 'Za nered že več kot uro ni nihče poskrbel.',
        ],

        // Muca zaradi zamujenega sprehoda nikoli ne zboli (CAT_SPEC Q2) — razloga `walk` ni.
        'illness' => [
            'child' => [
                'hygiene' => 'Muca je predolgo živela v neredu in je zbolela. 12 ur bo na opazovanju pri veterinarju.',
                'other' => 'Muca je zbolela. 12 ur bo na opazovanju pri veterinarju.',
            ],
            'parent' => [
                'hygiene' => 'Muca je zbolela, ker za nered ni nihče poskrbel. 12 ur bo na opazovanju pri veterinarju.',
                'other' => 'Muca je zbolela. 12 ur bo na opazovanju pri veterinarju.',
            ],
        ],

        'game_over' => [
            'child' => 'Muca je odšla v zavetišče, ker zanjo predolgo ni nihče poskrbel. Pogovori se s starši.',
            'parent' => 'Muca je odšla v zavetišče, ker 24 ur ni dobila nujne skrbi. V aplikaciji izberite, kako naprej.',
        ],

        'payment_required' => [
            'child' => 'Igra počaka na starša. Tvoja muca je na varnem in počiva.',
            'parent' => 'Brezplačni preizkus je končan. Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.',
            'parent_no_trial' => 'Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva.',
        ],
    ],
];
