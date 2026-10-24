<?php

/*
 * System health (David 2026-10-19): reported exceptions and error-level log
 * lines are stored in `system_errors` and shown on /admin/system-health
 * (superadmin) — no external error tracker. See App\Services\SystemHealth.
 */
return [
    // Kill switch for the capture (the page keeps working).
    'capture_enabled' => (bool) env('SYSTEM_HEALTH_CAPTURE', true),

    // Write captured errors through their own DB connection (a clone of the
    // default one): the row survives a rollback of the caller's transaction
    // and a failed write can never poison it. phpunit.xml turns it off so
    // RefreshDatabase rolls the rows back (the default connection then uses
    // a savepoint).
    'isolated_connection' => (bool) env('SYSTEM_HEALTH_ISOLATED_CONNECTION', true),

    // At most one DB write per fingerprint per window; occurrences in between
    // are counted in the cache and added on the next write (approximate).
    'write_window_seconds' => 60,

    // After a failed write the recorder pauses this long (per PHP process).
    'pause_after_failure_seconds' => 60,

    'message_max_chars' => 1000,

    'trace_frames' => 15,

    // Error-level log lines without an exception (Log::error / critical …)
    // are captured too (level ≥ this).
    'capture_log_levels' => ['error', 'critical', 'alert', 'emergency'],

    'retention' => [
        'resolved_days' => 30,
        'any_days' => 90,
        'hourly_buckets_days' => 14,
    ],

    // M5-05: crash / render-error reports from the mobile app
    // (POST /api/app-errors → app_error_* tables, App\Services\AppErrors).
    'app_errors' => [
        // Fingerprints, per-version rows and hourly buckets not seen for this long are deleted.
        'retention_days' => 30,
        // Reports per minute per client IP (IPv6 /64) and per signed-in user.
        'per_minute' => 30,
        // Discord `app_error_spike` when one fingerprint reaches this many reports in one clock hour.
        'spike_per_hour' => 20,
        // At most one Discord post per fingerprint in this many hours (new or spike).
        'alert_cooldown_hours' => 24,
    ],

    // Page thresholds.
    'heartbeat_stale_minutes' => 5,
    'stuck_ledger_days' => 2,
    'disk_warn_percent' => 90,

    // Queues the workers listen on (compose.production.yaml).
    'queues' => ['auth', 'default', 'broadcasts', 'notifications'],
];
