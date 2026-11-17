<?php

// M6-S09: e-mails and the voucher PDF of the website shop (WEBSITE_SALES_SPEC §3.6).
// One file per website language; keep the keys identical in all 15 (ShopEmailLangTest).

return [
    'gift_subject' => 'Ditt presentkort från PetPrep',
    'gift_preheader' => 'Koden till en 12 veckor lång PetPrep-utmaning, redo att ge bort.',
    'gift_intro' => 'Tack för din beställning. Här är ditt presentkort för en 12 veckor lång PetPrep-utmaning.',
    'code_label' => 'Presentkortskod',
    'valid_until' => 'Giltigt till :date',
    'how_title' => 'Så ger du bort det',
    'step_print' => 'Skriv ut presentkortet som bifogas i mejlet och fyll i vem det är till och från.',
    'step_redeem' => 'Föräldern loggar in på :url med samma konto som i PetPrep-appen och anger koden.',
    'step_start' => 'I appen väljer föräldern vilket husdjur utmaningen gäller, och de 12 veckorna börjar.',
    'redeem_button' => 'Lös in presentkortet',
    'kit_title' => 'Ingår: Husdjurslöftet',
    'kit_text' => 'Ett utskrivbart familjekit för samtalet om att skaffa ett husdjur. Ladda ner det här:',
    'kit_subject' => 'Ditt Husdjurslöfte-kit',
    'kit_preheader' => 'Ditt utskrivbara familjekit är klart att ladda ner.',
    'kit_intro' => 'Tack för din beställning. Ditt utskrivbara Husdjurslöfte-kit är klart att ladda ner.',
    'download' => 'Ladda ner kitet (PDF)',
    'download_lang' => 'PDF på :language',
    'links_valid' => 'Nedladdningslänkarna fungerar i :days dagar. Om de har gått ut svarar du bara på det här mejlet.',
    'help' => 'Tappat bort koden eller har en fråga? Svara på det här mejlet eller skriv till :email och ange beställning :number.',
    // The same without the lost-code question (orders without a code: the promise kit).
    'help_kit' => 'Har du en fråga? Svara på det här mejlet eller skriv till :email och ange beställning :number.',
    'hello' => 'Hej!',
    'signoff' => 'Vänliga hälsningar',
    'signature' => 'Curio och PetPrep-teamet',
    'receipt' => 'Kvitto och faktura får du separat från Link, som hanterar betalningen åt PetPrep.',
    'order' => 'Beställning :number',
    'pdf_title' => 'Presentkort',
    'pdf_product' => '12 veckor lång PetPrep-utmaning',
    'pdf_tagline' => 'Ge inte bort en valp. Ge bort en provperiod.',
    'pdf_for' => 'Till',
    'pdf_from' => 'Från',
    'pdf_code' => 'Kod',
    'pdf_valid' => 'Giltigt till',
    'pdf_redeem' => 'Lös in på',
    'pdf_file' => 'petprep-presentkort',
    // Sender name of the shop e-mails in this language (David 2026-11-17: translated).
    'from_name' => 'Curio från PetPrep',
    'language_name' => 'svenska',
];
