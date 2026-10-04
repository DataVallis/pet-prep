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
