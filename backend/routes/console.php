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
