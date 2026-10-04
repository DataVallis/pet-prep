<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Game Loop Tasks
|--------------------------------------------------------------------------
|
| The minutely cron job processes metric decay for all active pets
| and runs the escalation matrix checks. This is the core game loop.
|
*/

// Minutely: Process pet metric decay + escalation checks
Schedule::command('pets:process-decay')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->name('pet-decay-loop')
    ->description('Minutely metric decay and escalation for active pets');

// Daily (M2-02): delete child login PINs that expired more than 7 days ago.
// 03:17 UTC — off the hour, outside the families' evening rush.
Schedule::command('pins:prune')
    ->dailyAt('03:17')
    ->withoutOverlapping()
    ->name('prune-child-login-pins')
    ->description('Delete expired / used child login PINs older than 7 days');

// Daily (M4, PR #22 review): re-queue reference images blocked by the AI budget
// or an exhausted fal balance. 00:23 UTC — just after the budget day rolls over
// (AI_BUDGET_TIMEZONE defaults to UTC).
Schedule::command('media:retry-references')
    ->dailyAt('00:23')
    ->withoutOverlapping()
    ->name('retry-reference-images')
    ->description('Re-queue reference images blocked by the AI budget / fal balance');

// Hourly: fail AI Lab results stuck in `running` for over an hour.
Schedule::command('media:sweep-lab')
    ->hourlyAt(41)
    ->withoutOverlapping()
    ->name('sweep-media-lab')
    ->description('Fail AI Lab results running for more than an hour');
