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
    | RevenueCat — In-App Purchase Verification
    |--------------------------------------------------------------------------
    */
    'revenuecat' => [
        'secret_key' => env('REVENUECAT_SECRET_KEY'),
        'public_key' => env('REVENUECAT_PUBLIC_KEY'),
        'border_collie_product_id' => env('REVENUECAT_BORDER_COLLIE_PRODUCT_ID', 'border_collie_unlock'),
    ],

];
