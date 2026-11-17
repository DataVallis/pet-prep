<?php

// M6-S09: e-mails and the voucher PDF of the website shop (WEBSITE_SALES_SPEC §3.6).
// One file per website language; keep the keys identical in all 15 (ShopEmailLangTest).

return [
    'gift_subject' => 'Vaš PetPrep poklon-bon',
    'gift_preheader' => 'Kod za 12-tjedni PetPrep izazov, spreman za poklanjanje.',
    'gift_intro' => 'Hvala na narudžbi. Evo vašeg poklon-bona za jedan 12-tjedni PetPrep izazov.',
    'code_label' => 'Kod bona',
    'valid_until' => 'Vrijedi do :date',
    'how_title' => 'Kako ga pokloniti',
    'step_print' => 'Ispišite bon priložen ovoj e-poruci i upišite kome je namijenjen i od koga je.',
    'step_redeem' => 'Roditelj se na :url prijavi istim računom kao u aplikaciji PetPrep i upiše kod.',
    'step_start' => 'U aplikaciji roditelj odabere ljubimca za kojeg je izazov i 12 tjedana počinje.',
    'redeem_button' => 'Iskoristi bon',
    'kit_title' => 'Uključeno: komplet Obećanje za ljubimca',
    'kit_text' => 'Obiteljski komplet za ispis koji pomaže u razgovoru o ljubimcu. Preuzmite ga ovdje:',
    'kit_subject' => 'Vaš komplet Obećanje za ljubimca',
    'kit_preheader' => 'Vaš obiteljski komplet za ispis spreman je za preuzimanje.',
    'kit_intro' => 'Hvala na narudžbi. Vaš komplet Obećanje za ljubimca za ispis spreman je za preuzimanje.',
    'download' => 'Preuzmi komplet (PDF)',
    'download_lang' => 'PDF na jeziku: :language',
    'links_valid' => 'Poveznice za preuzimanje rade :days dana. Ako isteknu, samo odgovorite na ovu e-poruku.',
    'help' => 'Izgubili ste kod ili imate pitanje? Odgovorite na ovu e-poruku ili pišite na :email i navedite narudžbu :number.',
    // The same without the lost-code question (orders without a code: the promise kit).
    'help_kit' => 'Imate pitanje? Odgovorite na ovu e-poruku ili pišite na :email i navedite narudžbu :number.',
    'hello' => 'Pozdrav,',
    'signoff' => 'Srdačan pozdrav,',
    'signature' => 'Curio i tim PetPrep',
    'receipt' => 'Potvrdu o plaćanju i račun primit ćete zasebno od tvrtke Link, koja za PetPrep obrađuje plaćanje.',
    'order' => 'Narudžba :number',
    'pdf_title' => 'Poklon-bon',
    'pdf_product' => '12-tjedni PetPrep izazov',
    'pdf_tagline' => 'Ne poklanjajte štene. Poklonite probu.',
    'pdf_for' => 'Za',
    'pdf_from' => 'Od',
    'pdf_code' => 'Kod',
    'pdf_valid' => 'Vrijedi do',
    'pdf_redeem' => 'Iskoristite na',
    'pdf_file' => 'petprep-poklon-bon',
    // Sender name of the shop e-mails in this language (David 2026-11-17: translated).
    'from_name' => 'Curio iz PetPrep',
    'language_name' => 'hrvatski',
];
