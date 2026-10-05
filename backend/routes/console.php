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

// Daily (M4, PR #22 review; videos since M4-03): re-queue reference images and
// state videos blocked by the AI budget or an exhausted fal balance. 00:23 UTC —
// just after the budget day rolls over (AI_BUDGET_TIMEZONE defaults to UTC).
Schedule::command('media:retry')
    ->dailyAt('00:23')
    ->withoutOverlapping()
    ->name('retry-reference-images')
    ->description('Re-queue AI reference images / state videos blocked by the AI budget / fal balance');

// Hourly: fail AI Lab results stuck in `running` for over an hour.
Schedule::command('media:sweep-lab')
    ->hourlyAt(41)
    ->withoutOverlapping()
    ->name('sweep-media-lab')
    ->description('Fail AI Lab results running for more than an hour');

// Hourly (M4-03): pet media stuck after a lost job / fal webhook — videos without
// a webhook after 2 h fail as timed_out, lost downloads and dead claims re-queue.
Schedule::command('media:sweep')
    ->hourlyAt(47)
    ->withoutOverlapping()
    ->name('sweep-pet-media')
    ->description('Recover pet media slots stuck after a lost job or webhook');

// Every 15 min (M3-02): queue the Expo push receipt check (DeviceNotRegistered →
// device disabled) and delete push rows older than 30 days. Off the quarter hour.
Schedule::command('push:receipts')
    ->cron('7,22,37,52 * * * *')
    ->withoutOverlapping()
    ->name('push-receipts')
    ->description('Check Expo push receipts and prune old push rows');
