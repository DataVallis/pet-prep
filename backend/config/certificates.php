<?php

/*
|--------------------------------------------------------------------------
| Responsibility Certificate (M2-01d, CERTIFICATE_SPEC)
|--------------------------------------------------------------------------
|
| `launched_at`: the feature launch. Only challenges that end (84 program
| days reached, or game over) at or after this instant get a certificate /
| report row and the parent push — the first deploy never backfills history
| (QA M3, 2026-10-12). ISO 8601, UTC.
|
*/

return [
    'launched_at' => env('CERTIFICATES_LAUNCHED_AT', '2026-10-12T00:00:00Z'),
];
