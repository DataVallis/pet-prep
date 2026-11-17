<?php

// M6-S09: e-mails and the voucher PDF of the website shop (WEBSITE_SALES_SPEC §3.6).
// One file per website language; keep the keys identical in all 15 (ShopEmailLangTest).

return [
    'gift_subject' => 'Ihr PetPrep-Geschenkgutschein',
    'gift_preheader' => 'Der Code für eine 12-wöchige PetPrep-Challenge, bereit zum Verschenken.',
    'gift_intro' => 'Vielen Dank für Ihre Bestellung. Hier ist Ihr Geschenkgutschein für eine 12-wöchige PetPrep-Challenge.',
    'code_label' => 'Gutscheincode',
    'valid_until' => 'Gültig bis :date',
    'how_title' => 'So verschenken Sie ihn',
    'step_print' => 'Drucken Sie den Gutschein aus, der dieser E-Mail beiliegt, und tragen Sie ein, für wen er ist und von wem.',
    'step_redeem' => 'Ein Elternteil meldet sich auf :url mit demselben Konto wie in der PetPrep-App an und gibt den Code ein.',
    'step_start' => 'In der App wählt das Elternteil das Haustier für die Challenge aus, und die 12 Wochen beginnen.',
    'redeem_button' => 'Gutschein einlösen',
    'kit_title' => 'Inklusive: das Haustier-Versprechen-Set',
    'kit_text' => 'Ein druckbares Familienset für das Gespräch über ein Haustier. Hier können Sie es herunterladen:',
    'kit_subject' => 'Ihr Haustier-Versprechen-Set',
    'kit_preheader' => 'Ihr druckbares Familienset steht zum Download bereit.',
    'kit_intro' => 'Vielen Dank für Ihre Bestellung. Ihr druckbares Haustier-Versprechen-Set steht zum Download bereit.',
    'download' => 'Set herunterladen (PDF)',
    'download_lang' => 'PDF auf :language',
    'links_valid' => 'Die Download-Links funktionieren :days Tage lang. Wenn sie abgelaufen sind, antworten Sie einfach auf diese E-Mail.',
    'help' => 'Code verloren oder eine Frage? Antworten Sie auf diese E-Mail oder schreiben Sie an :email und nennen Sie die Bestellung :number.',
    // The same without the lost-code question (orders without a code: the promise kit).
    'help_kit' => 'Haben Sie eine Frage? Antworten Sie auf diese E-Mail oder schreiben Sie an :email und nennen Sie die Bestellung :number.',
    'hello' => 'Hallo,',
    'signoff' => 'Herzliche Grüße',
    'signature' => 'Curio und das PetPrep-Team',
    'receipt' => 'Zahlungsbeleg und Rechnung erhalten Sie separat von Link, das die Zahlung für PetPrep abwickelt.',
    'order' => 'Bestellung :number',
    'pdf_title' => 'Geschenkgutschein',
    'pdf_product' => '12-wöchige PetPrep-Challenge',
    'pdf_tagline' => 'Verschenken Sie keinen Welpen. Verschenken Sie einen Probelauf.',
    'pdf_for' => 'Für',
    'pdf_from' => 'Von',
    'pdf_code' => 'Code',
    'pdf_valid' => 'Gültig bis',
    'pdf_redeem' => 'Einlösen auf',
    'pdf_file' => 'petprep-geschenkgutschein',
    // Sender name of the shop e-mails in this language (David 2026-11-17: translated).
    'from_name' => 'Curio von PetPrep',
    'language_name' => 'Deutsch',
];
