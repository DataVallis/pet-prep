<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | fal.ai — AI Media Generation Orchestrator
    |--------------------------------------------------------------------------
    | fal.ai handles Kling 3.0 (video generation) and Flux (image generation).
    | The Pet DNA architecture ensures 100% visual consistency across all
    | AI-generated images and videos by using a fixed seed + reference image.
    |
    */
    'fal_ai' => [
        'key' => env('FAL_AI_API_KEY'),
        // Webhooks are verified with fal.ai's ED25519 signature (no shared secret).
        'jwks_url' => env('FAL_AI_JWKS_URL', 'https://rest.fal.ai/.well-known/jwks.json'),
        'webhook_tolerance_seconds' => (int) env('FAL_AI_WEBHOOK_TOLERANCE', 300),
        // Media URLs we are willing to show to a child (host or any subdomain).
        'media_hosts' => array_filter(explode(',', (string) env('FAL_AI_MEDIA_HOSTS', 'fal.media'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | RevenueCat — purchase webhook + challenge credits (M3-08 / M3-11)
    |--------------------------------------------------------------------------
    */
    'revenuecat' => [
        // The webhook's Authorization header value (RevenueCat → "Bearer <secret>").
        // Empty → POST /api/webhooks/revenuecat answers 503 and processes nothing (fail closed).
        'webhook_secret' => env('REVENUECAT_WEBHOOK_SECRET'),
        'public_key' => env('REVENUECAT_PUBLIC_KEY'),
        // Store product ids of the consumable 12-week challenge (PAYMENTS_SPEC P1),
        // comma-separated. A purchase of one of them = one challenge credit.
        'challenge_products' => array_values(array_filter(array_map('trim', explode(',', (string) env('REVENUECAT_CHALLENGE_PRODUCTS', 'petprep_challenge_12w'))))),
        // SANDBOX events are always stored; they grant / revoke only when this is true.
        'accept_sandbox' => (bool) env('REVENUECAT_ACCEPT_SANDBOX', env('APP_ENV', 'production') !== 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Parent sign-in with Google / Apple (M2-10c)
    |--------------------------------------------------------------------------
    | The apps send the provider's ID token to POST /api/auth/social; the API
    | accepts it only when its `aud` is one of these IDs. No secrets here:
    | client IDs / bundle ids are public identifiers.
    */
    'google' => [
        // OAuth client IDs (web, iOS, Android), comma-separated. Default = PetPrep's
        // own ids (public identifiers, David 2026-10-13); an explicitly empty
        // GOOGLE_CLIENT_IDS= disables Google sign-in (503 provider_disabled).
        'client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'GOOGLE_CLIENT_IDS',
            '1029107925972-jcets5rq4a03c00okr54e55jvluj2giv.apps.googleusercontent.com,'
            .'1029107925972-fm90sk3l07phkqbkfcnjli2jddp4bqfu.apps.googleusercontent.com,'
            .'1029107925972-42jsoh8vvtsjv4tk8398o4g1mrp94mib.apps.googleusercontent.com',
        ))))),
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
    ],

    'apple' => [
        // Accepted `aud` values: the iOS bundle id (native Sign in with Apple) and,
        // later, a Services ID for Android / web. Comma-separated. Empty → disabled.
        'audiences' => array_values(array_filter(array_map('trim', explode(',', (string) env('APPLE_AUDIENCES', 'com.datavallis.petprep'))))),
        'jwks_url' => 'https://appleid.apple.com/auth/keys',

        // Token revocation when a parent deletes the account (M2-10c, App Store
        // guideline 5.1.1(v)). Team / key ids are public identifiers (defaults);
        // the private key (.p8) is a SECRET — base64 of the whole file (one line)
        // or raw PEM with `\n` escapes. Without it revocation is skipped (logged),
        // the deletion itself still succeeds.
        'team_id' => env('APPLE_TEAM_ID', '4345XU4MU8'),
        'key_id' => env('APPLE_KEY_ID', 'Z8WR3WL68G'),
        'private_key' => env('APPLE_SIGN_IN_PRIVATE_KEY'),
        // client_id of the token / revoke calls = the iOS bundle id (empty → the
        // first of `audiences`, else com.datavallis.petprep).
        'client_id' => env('APPLE_CLIENT_ID'),
        'token_url' => 'https://appleid.apple.com/auth/token',
        'revoke_url' => 'https://appleid.apple.com/auth/revoke',
    ],

];
