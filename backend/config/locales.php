<?php

/*
|--------------------------------------------------------------------------
| Server languages (M1-18)
|--------------------------------------------------------------------------
|
| The single list of languages the API speaks. A request's language comes
| from `Accept-Language` (App\Support\RequestLocale, middleware
| SetRequestLocale) and only decides response texts; push texts use the
| language stored per device (`device_push_tokens.locale`, set from the
| explicit `locale` field of POST /api/devices). Adding a language = add its code here and
| its files under lang/<code>/ (every key that lang/en/ has).
|
| `default` is used when a request names no supported language and when a
| device has no stored language (English, David 2026-10-02).
|
*/

return [
    'supported' => ['en', 'sl'],

    'default' => 'en',

    /*
    | Push language of a NEW install registered without the `locale` body
    | field (POST /api/devices). Only app builds from before M1-18 do that,
    | and they are Slovenian-only. Existing rows were backfilled the same way.
    */
    'unstated_device' => 'sl',
];
