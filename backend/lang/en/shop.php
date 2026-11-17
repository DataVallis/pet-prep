<?php

// M6-S09: e-mails and the voucher PDF of the website shop (WEBSITE_SALES_SPEC §3.6).
// One file per website language; keep the keys identical in all 15 (ShopEmailLangTest).

return [
    'gift_subject' => 'Your PetPrep gift voucher',
    'gift_preheader' => 'The code for a 12-week PetPrep challenge, ready to give.',
    'gift_intro' => 'Thank you for your order. Here is your gift voucher for one 12-week PetPrep challenge.',
    'code_label' => 'Voucher code',
    'valid_until' => 'Valid until :date',
    'how_title' => 'How to give it',
    'step_print' => 'Print the voucher attached to this e-mail and fill in who it is for and who it is from.',
    'step_redeem' => 'The parent signs in at :url with the same account as in the PetPrep app and enters the code.',
    'step_start' => 'In the app, the parent chooses the pet the challenge is for, and the 12 weeks begin.',
    'redeem_button' => 'Redeem the voucher',
    'kit_title' => 'Included: the Pet Promise kit',
    'kit_text' => 'A printable family kit for the conversation about getting a pet. Download it here:',
    'kit_subject' => 'Your Pet Promise kit',
    'kit_preheader' => 'Your printable family kit is ready to download.',
    'kit_intro' => 'Thank you for your order. Your printable Pet Promise kit is ready to download.',
    'download' => 'Download the kit (PDF)',
    'download_lang' => 'PDF in :language',
    'links_valid' => 'The download links work for :days days. If they expire, just reply to this e-mail.',
    'help' => 'Lost the code or have a question? Reply to this e-mail or write to :email and mention order :number.',
    // The same without the lost-code question (orders without a code: the promise kit).
    'help_kit' => 'Have a question? Reply to this e-mail or write to :email and mention order :number.',
    'hello' => 'Hello,',
    'signoff' => 'Warm regards,',
    'signature' => 'Curio and the PetPrep team',
    'receipt' => 'The receipt and invoice come separately from Link, which processes the payment for PetPrep.',
    'order' => 'Order :number',
    'pdf_title' => 'Gift voucher',
    'pdf_product' => '12-week PetPrep challenge',
    'pdf_tagline' => 'Don\'t give a puppy. Give a trial run.',
    'pdf_for' => 'For',
    'pdf_from' => 'From',
    'pdf_code' => 'Code',
    'pdf_valid' => 'Valid until',
    'pdf_redeem' => 'Redeem at',
    'pdf_file' => 'petprep-gift-voucher',
    // Sender name of the shop e-mails in this language (David 2026-11-17: translated).
    'from_name' => 'Curio from PetPrep',
    'language_name' => 'English',
];
