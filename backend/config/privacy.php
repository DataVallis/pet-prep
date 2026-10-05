<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data export (M2-08, GDPR art. 15 / 20)
    |--------------------------------------------------------------------------
    | GET /api/parent/account/export builds the family's JSON synchronously.
    | Above this many history rows (activities, steps, walks, routines, status
    | periods, hygiene events) it answers 413 `export_too_large` instead of
    | holding a PHP worker — an asynchronous export is a follow-up. A 12-week
    | challenge of one pet is roughly 3–4k rows.
    */
    'export_max_rows' => (int) env('PRIVACY_EXPORT_MAX_ROWS', 50000),

];
