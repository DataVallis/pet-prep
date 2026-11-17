<?php

// M6-S09: e-mails and the voucher PDF of the website shop (WEBSITE_SALES_SPEC §3.6).
// One file per website language; keep the keys identical in all 15 (ShopEmailLangTest).

return [
    'gift_subject' => 'Tu vale regalo de PetPrep',
    'gift_preheader' => 'El código de un reto PetPrep de 12 semanas, listo para regalar.',
    'gift_intro' => 'Gracias por tu pedido. Aquí tienes tu vale regalo para un reto PetPrep de 12 semanas.',
    'code_label' => 'Código del vale',
    'valid_until' => 'Válido hasta el :date',
    'how_title' => 'Cómo regalarlo',
    'step_print' => 'Imprime el vale adjunto a este correo y escribe para quién es y de parte de quién.',
    'step_redeem' => 'El padre o la madre inicia sesión en :url con la misma cuenta que en la app PetPrep e introduce el código.',
    'step_start' => 'En la app, elige la mascota para la que es el reto, y empiezan las 12 semanas.',
    'redeem_button' => 'Canjear el vale',
    'kit_title' => 'Incluido: el kit Promesa a la mascota',
    'kit_text' => 'Un kit familiar para imprimir que ayuda a hablar de tener una mascota. Descárgalo aquí:',
    'kit_subject' => 'Tu kit Promesa a la mascota',
    'kit_preheader' => 'Tu kit familiar para imprimir ya está listo para descargar.',
    'kit_intro' => 'Gracias por tu pedido. Tu kit Promesa a la mascota para imprimir ya está listo para descargar.',
    'download' => 'Descargar el kit (PDF)',
    'download_lang' => 'PDF en :language',
    'links_valid' => 'Los enlaces de descarga funcionan durante :days días. Si caducan, responde a este correo.',
    'help' => '¿Has perdido el código o tienes una pregunta? Responde a este correo o escribe a :email indicando el pedido :number.',
    // The same without the lost-code question (orders without a code: the promise kit).
    'help_kit' => '¿Tienes una pregunta? Responde a este correo o escribe a :email indicando el pedido :number.',
    'hello' => 'Hola:',
    'signoff' => 'Un saludo,',
    'signature' => 'Curio y el equipo de PetPrep',
    'receipt' => 'El recibo y la factura te llegan por separado de Link, que procesa el pago para PetPrep.',
    'order' => 'Pedido :number',
    'pdf_title' => 'Vale regalo',
    'pdf_product' => 'Reto PetPrep de 12 semanas',
    'pdf_tagline' => 'No regales un cachorro. Regala una prueba.',
    'pdf_for' => 'Para',
    'pdf_from' => 'De',
    'pdf_code' => 'Código',
    'pdf_valid' => 'Válido hasta',
    'pdf_redeem' => 'Canjéalo en',
    'pdf_file' => 'petprep-vale-regalo',
    // Sender name of the shop e-mails in this language (David 2026-11-17: translated).
    'from_name' => 'Curio de PetPrep',
    'language_name' => 'español',
];
