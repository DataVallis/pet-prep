<?php

/*
|--------------------------------------------------------------------------
| Website shop (M6-S09, WEBSITE_SALES_SPEC §3.4–§3.7, David 2026-11-17)
|--------------------------------------------------------------------------
| Products and prices live in Stripe (the source of truth, VAT included). This
| file maps our product keys to the Stripe price ids (env) and holds the shop's
| non-secret settings. Adding a product = a Stripe product + price, a key here,
| a fulfilment branch in ShopFulfillment and its e-mail.
*/

return [

    // Public website the buyer returns to after the Stripe checkout.
    'website_url' => rtrim((string) env('SHOP_WEBSITE_URL', 'https://petprep.si'), '/'),

    // Redeem page slug per language (copy of pet-prep-website src/i18n/config.ts
    // `salesSlugs.redeem` — keep both in step). Path = /<locale>/<slug>, English /<slug>.
    'redeem_slugs' => [
        'en' => 'redeem', 'sl' => 'unovci', 'de' => 'einloesen', 'fr' => 'utiliser-le-bon', 'es' => 'canjear',
        'it' => 'riscatta', 'pt' => 'resgatar', 'nl' => 'inwisselen', 'pl' => 'zrealizuj', 'cs' => 'uplatnit',
        'hr' => 'iskoristi', 'sv' => 'los-in', 'da' => 'indloes', 'fi' => 'lunasta', 'nb' => 'los-inn',
    ],

    'products' => [
        'gift_voucher' => [
            'price_id' => env('STRIPE_PRICE_GIFT_VOUCHER'),
            // Admin switch that has to be on before the website may sell / redeem it.
            'switch' => 'gift_vouchers',
            // The gift e-mail also offers the promise kit PDFs (spec §3.1).
            'includes_files_of' => 'promise_kit',
        ],
        'promise_kit' => [
            'price_id' => env('STRIPE_PRICE_PROMISE_KIT'),
            'switch' => 'promise_kit',
        ],
    ],

    // Checkout branding (the Stripe account also sells other things of David's).
    'branding' => [
        'display_name' => 'PetPrep',
        'icon_url' => env('SHOP_BRAND_ICON_URL', 'https://petprep.si/brand/email/petprep-mark-192.png'),
        'logo_url' => env('SHOP_BRAND_LOGO_URL', 'https://petprep.si/brand/email/petprep-wordmark-320.png'),
        'button_color' => '#121614',
        'background_color' => '#F3F5F2',
    ],

    // GET /api/shop/orders/{session}: the code / downloads are shown this long after the purchase.
    'order_lookup_days' => 30,

    // Signed download links (website thank-you page, e-mail).
    'download_link_hours' => 24,
    'email_download_link_days' => 30,

    // Private disk + folder of the sold files: <dir>/<product>/<locale>.pdf
    'files_disk' => env('SHOP_FILES_DISK', 'local'),
    'files_dir' => 'shop-files',

    'email' => [
        // Sender: the brand mascot, replies go to the inbox David reads (David 2026-11-17).
        'from_address' => env('SHOP_MAIL_FROM_ADDRESS', 'curio@petprep.si'),
        'from_name' => env('SHOP_MAIL_FROM_NAME', 'Curio from PetPrep'),
        'reply_to' => env('SHOP_MAIL_REPLY_TO', 'hello@petprep.si'),
        'support_address' => env('SHOP_SUPPORT_ADDRESS', 'hello@petprep.si'),
        // emails:send — how many per run, minutes between attempts (index = attempts so far).
        'batch' => 50,
        'retry_minutes' => [5, 30, 120, 360, 1440],
        // A row stuck in `sending` this long (crashed worker) is retried.
        'stale_sending_minutes' => 15,
    ],

];
