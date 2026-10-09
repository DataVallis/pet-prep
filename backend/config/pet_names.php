<?php

/*
|--------------------------------------------------------------------------
| Pet names (M5-R08, David 2026-10-09)
|--------------------------------------------------------------------------
| A parent may give a pet an optional name (PATCH /api/parent/pets/{pet}/name).
| Characters / length are checked in UpdatePetNameRequest; this file holds
| the short filter of inappropriate words (English + Slovenian) that
| PetNameService::isAllowed() applies.
|
| Matching (PetNameService::foldForFilter): lower case, diacritics removed
| (č → c, š → s, ž → z, ä → a …), then
|  - `words`: equal to one word of the name (words split at space / hyphen /
|    apostrophe) OR to the whole name with those separators removed
|    ("F-u-c-k", "pi zda");
|  - `fragments`: contained anywhere in the name with the separators removed
|    — only unambiguous roots go here (no short strings that occur inside
|    ordinary names).
| Entries are written in the folded form (lower case, no diacritics).
| Keep the list modest; it is a guard rail for a family app, not a moderation
| system.
*/

return [
    'max_length' => 20,

    'words' => [
        // English
        'ass', 'arse', 'asshole', 'arsehole', 'bastard', 'bitch', 'bollocks', 'boob', 'boobs',
        'butthole', 'cock', 'crap', 'cunt', 'dick', 'dildo', 'dumbass', 'fag', 'faggot',
        'fuck', 'fucker', 'hitler', 'idiot', 'moron', 'nazi', 'nigga', 'nigger', 'penis',
        'piss', 'porn', 'pussy', 'retard', 'sex', 'sexy', 'shit', 'slut',
        'tits', 'twat', 'vagina', 'wanker', 'whore',
        // Slovenian
        'debil', 'drek', 'fafati', 'fukat', 'fuk', 'jebem', 'jebi', 'jebiga',
        'jebo', 'joski', 'kreten', 'kurac', 'kurba', 'kurc', 'kurcek', 'lulek', 'nacist',
        'peder', 'picka', 'pizda', 'prasica', 'scat', 'scanje', 'seks', 'srat', 'sranje',
        'zajebat',
    ],

    'fragments' => [
        'fuck', 'shit', 'cunt', 'bitch', 'whore', 'nigg', 'fagg', 'hitler', 'porn',
        'pizd', 'kurac', 'kurb', 'sranj', 'jebem', 'jebig', 'zajeb',
    ],
];
