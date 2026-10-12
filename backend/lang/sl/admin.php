<?php

/*
 * Superadmin panel (/admin) strings — Slovenian. Same keys as
 * lang/en/admin.php (English is the default and the fallback).
 */
return [
    'locale' => [
        'switch_to' => 'English',
        'current' => 'Slovenščina',
        'en' => 'English',
        'sl' => 'Slovenščina',
    ],

    'navigation' => [
        'configuration' => 'Nastavitve',
        'payments' => 'Plačila',
        'management' => 'Upravljanje',
        'audit' => 'Revizija',
        'ai_media' => 'AI mediji',
        'users_label' => 'Uporabniki',
        'user_model' => 'uporabnik',
        'pets_label' => 'Ljubljenčki',
        'pet_model' => 'ljubljenček',
        'breed_configs' => 'Nastavitve pasem',
        'breed_config_model' => 'nastavitev pasme',
        'stage_data' => 'Podatki življenjskih obdobij',
        'stage_value_model' => 'vrednost obdobja',
        'credits' => 'Krediti izzivov',
        'credit_model' => 'kredit izziva',
        'purchase_events' => 'Dogodki nakupov',
        'purchase_event_model' => 'dogodek nakupa',
        'activity_log' => 'Dnevnik aktivnosti',
        'activity_model' => 'aktivnost',
        'ai_lab' => 'AI Lab',
    ],

    'dashboard' => [
        'title' => 'Nadzorna plošča',
        'period' => 'Obdobje',
        'periods' => [
            '7' => 'Zadnjih 7 dni',
            '30' => 'Zadnjih 30 dni',
            '90' => 'Zadnjih 90 dni',
            '365' => 'Zadnjih 12 mesecev',
        ],
        'species_filter' => 'Vrsta',
        'all' => 'Vse',
        'dog' => 'Psi',
        'cat' => 'Mačke',

        'stats' => [
            'families' => 'Družine',
            'families_desc' => '+:count novih v obdobju',
            'parents' => 'Starši',
            'parents_desc' => '+:count novih v obdobju',
            'children' => 'Otroci',
            'children_desc' => '+:count novih v obdobju',
            'pets' => 'Aktivni ljubljenčki',
            'pets_desc' => ':dogs psov · :cats mačk · :new novih v obdobju',
            'challenges' => 'Plačani izzivi',
            'challenges_desc' => ':period v obdobju · :sandbox sandbox / :production pravih',
            'care' => 'Skrb (dejanja)',
            'care_desc' => 'Zabeležena dejanja otrok in staršev v obdobju',
        ],

        'attention' => [
            'heading' => 'Potrebuje pozornost',
            'sick' => 'Bolni ljubljenčki',
            'sick_desc' => 'Trenutno v stanju bolezni',
            'locked' => 'Čakajo na plačilo',
            'locked_desc' => 'Izziv je ustavljen, dokler starš ne kupi',
            'game_over' => 'Konec igre',
            'game_over_desc' => ':hard trdih ustavitev · :total skupaj',
            'cats' => 'Stikalo za mačke',
            'cats_desc' => 'Kdo lahko ustvari novo mačko — klikni za spremembo',
        ],

        'growth' => [
            'heading' => 'Rast',
            'description' => 'Novi starši, otroci in ljubljenčki na dan',
            'parents' => 'Novi starši',
            'children' => 'Novi otroci',
            'pets' => 'Novi ljubljenčki',
        ],

        'care_chart' => [
            'heading' => 'Aktivnost skrbi',
            'description' => 'Zabeležena dejanja na dan, po skupinah',
            'feeding' => 'Hrana in voda',
            'outside' => 'Sprehodi in potrebe',
            'hygiene' => 'Čiščenje in nega',
            'fun' => 'Igra, crkljanje in učenje',
            'incidents' => 'Nezgode',
        ],

        'breeds' => [
            'heading' => 'Ljubljenčki po pasmah',
            'description' => 'Aktivni ljubljenčki, ves čas',
            'pets' => 'Ljubljenčki',
        ],

        'plans' => [
            'heading' => 'Brezplačni in plačani',
            'description' => 'Aktivni ljubljenčki po paketu',
            'free' => 'Brezplačni',
            'challenge_paid' => 'Izziv (plačan)',
            'challenge_unpaid' => 'Izziv (neplačan)',
        ],

        'latest_pets' => [
            'heading' => 'Zadnji ljubljenčki',
            'id' => 'ID',
            'name' => 'Ime',
            'breed' => 'Pasma',
            'species' => 'Vrsta',
            'plan' => 'Paket',
            'state' => 'Stanje',
            'family' => 'Družina',
            'created' => 'Ustvarjen',
            'unnamed' => '—',
            'species_dog' => 'Pes',
            'species_cat' => 'Mačka',
            'states' => [
                'idle' => 'Mirno',
                'sleeping' => 'Spi',
                'low_energy' => 'Malo energije',
                'hungry' => 'Lačen',
                'sick' => 'Bolan',
                'playing' => 'Se igra',
            ],
        ],

        'latest_purchases' => [
            'heading' => 'Zadnji nakupi',
            'purchased' => 'Kupljeno',
            'store' => 'Trgovina',
            'environment' => 'Okolje',
            'family' => 'Družina',
            'pet' => 'Dodeljen ljubljenček',
            'status' => 'Status',
            'active' => 'Aktiven',
            'revoked' => 'Preklican',
            'view_all' => 'Prikaži vse',
            'unassigned' => 'Še ni dodeljen',
        ],

        'ai' => [
            'pets_today' => 'AI stroški ljubljenčkov danes (ocena)',
            'pets_month' => 'Ta mesec $:month / $:cap · slike $:images · videi $:videos (:tz)',
            'lab_today' => 'AI Lab stroški danes (ocena)',
            'lab_month' => 'Ta mesec $:month / $:cap — ločeno od proračuna ljubljenčkov',
            'balance' => 'Stanje fal.ai',
            'exhausted' => 'PORABLJENO',
            'ok' => 'OK',
            'exhausted_desc' => 'Od :at — doplačaj na fal.ai/dashboard/billing',
            'errors_desc' => ':count ljubljenčkov brez medijev (proračun / stanje / napake)',
        ],
    ],

    'features' => [
        'navigation' => 'Funkcije',
        'title' => 'Funkcije (stikala)',
        'cats_section' => 'Mačke',
        'cats_description' => 'Kdo lahko ustvari NOVO mačko. Obstoječe mačke delujejo v vsakem načinu (izklop ustavi samo nove mačke).',
        'cats_mode' => 'Mačke',
        'modes' => [
            'off' => 'Izklopljeno',
            'test_families' => 'Samo testne družine',
            'everyone' => 'Vsi',
        ],
        'env_override' => '<strong>Strežnik ima PETPREP_CATS_ENABLED=true</strong> — mačke so vklopljene za vse ne glede na to izbiro. Za testne družine odstrani vrstico iz /opt/petprep/.env in ponovno zaženi PHP vsebnike.',
        'test_parents' => 'Testni starši (družina je testna, če je v njej kateri od teh staršev)',
        'test_parents_help' => 'Poišči starševski račun po e-pošti ali imenu.',
        'help' => 'Kaj se zgodi po shranjevanju: nikjer drugje ni treba ničesar vklopiti. Mačke prikaže samo aplikacija v3.1.0 ali novejša (pošlje <code>species_cat</code>) — tudi otrokov telefon jo mora imeti, sicer otrok dobi poziv za posodobitev. Po spremembi naj uporabnik aplikacijo popolnoma zapre in znova odpre (katalog pasem je v aplikaciji shranjen 1 uro); strežnik novo nastavitev upošteva najkasneje v 1 minuti.',
        'save' => 'Shrani',
        'saved' => 'Shranjeno',
        'saved_body' => 'Mačke: :mode',
        'not_saved' => 'Ni shranjeno',
        'history' => 'Zgodovina sprememb',
        'history_desc' => 'Zadnjih 20 sprememb (kdo, kdaj, prej → potem).',
        'history_empty' => 'Še ni sprememb (privzeto: izklopljeno).',
        'system' => 'sistem',
    ],

    // M5-F11: BreedConfigResource (only the new field is localized; the older fields are English).
    'breed_configs' => [
        'water_refill_below' => 'Voda dovoljena pri žeji ≤',
        'water_refill_below_help' => 'Voda je dovoljena, ko žeja, ki jo otrok vidi, pade na ta odstotek ali nižje IN je minil najmanjši razmik. Dnevne omejitve ni (M5-F11).',
        'water_refill_below_column' => 'Voda pri ≤',
    ],
];
