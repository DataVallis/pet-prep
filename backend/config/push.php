<?php

/*
|--------------------------------------------------------------------------
| Push notifications (M3-02) — Expo Push Service
|--------------------------------------------------------------------------
| Escalation pushes go through Expo (which relays to APNs / FCM). Payloads
| carry {type, pet_id} only — no child names or other personal data.
*/

return [
    // Master switch: true in production, false in tests (phpunit.xml) and
    // whenever push should stay silent. Off → no rows, no jobs.
    'enabled' => (bool) env('PUSH_ENABLED', false),

    // Optional "Enhanced push security" access token from expo.dev (sent as a
    // bearer token). Without it Expo accepts unauthenticated sends.
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),

    'send_url' => env('EXPO_PUSH_SEND_URL', 'https://exp.host/--/api/v2/push/send'),
    'receipts_url' => env('EXPO_PUSH_RECEIPTS_URL', 'https://exp.host/--/api/v2/push/getReceipts'),

    // Expo limits: 100 messages per send request, 1000 ids per receipts request.
    'chunk_size' => 100,
    'receipts_chunk_size' => 1000,

    // Duplicate guard: the same pet + type is pushed at most once per window.
    'dedupe_minutes' => (int) env('PUSH_DEDUPE_MINUTES', 30),

    // Queue for the send / receipt jobs (worker: queue-broadcasts, fast lane).
    'queue' => env('PUSH_QUEUE', 'notifications'),

    'http_timeout_seconds' => 10,
];
