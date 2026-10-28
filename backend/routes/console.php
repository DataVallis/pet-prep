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

// Every 10 min (school smooth playback, David 2026-10-28): ffmpeg-derived school clips
// (attention / success loops, release = reversed performing — 0 $ AI cost) for stored
// school phase clips that have none yet or whose source changed; small batches, so
// production fills existing looks by itself after a deploy (idempotent, unique jobs).
Schedule::command('media:school-derivatives')
    ->cron('3-59/10 * * * *')
    ->withoutOverlapping(10)
    ->name('school-clip-derivatives')
    ->description('Queue ffmpeg-derived school clips (loops, release) of stored school phase clips');

// Hourly safety net (2026-10-20): stored videos with the MP4 index at the end are
// rewritten moov-first (the app's player cannot open them otherwise). Reads only
// box headers of files that are already fine. Also fixes files stored before the fix.
Schedule::command('media:faststart')
    ->hourlyAt(17)
    ->withoutOverlapping()
    ->name('faststart-pet-media')
    ->description('Move the MP4 index to the front of stored pet videos');

// Every 5 min (M5-R14 – R20 follow-up, David 2026-10-24): switch breed care tasks on
// for existing dogs once their apps declare `care_tasks` (from the next family-local
// midnight, never retroactively) and push the parent groomer / bath reminder when a
// period starts (once per period). Off the 5-minute mark; each step has a time
// budget (CareTaskActivationService / CareReminderPushService) and the overlap
// lock expires after 10 min, so a crashed run never blocks it for a day.
Schedule::command('pets:care-tasks')
    ->cron('1-59/5 * * * *')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('pets-care-tasks')
    ->description('Switch care tasks on for existing dogs and push parent care reminders');

// Every minute (M3-02, PR #35 review): queue held pushes that are due (illness /
// game over after quiet hours, the daily walk reminder at its earliest time).
Schedule::command('push:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('push-dispatch-scheduled')
    ->description('Queue held push notifications whose send_after has passed');

// Every 15 min (M3-02): queue the Expo push receipt check (DeviceNotRegistered →
// device disabled) and delete push rows older than 30 days. Off the quarter hour.
Schedule::command('push:receipts')
    ->cron('7,22,37,52 * * * *')
    ->withoutOverlapping()
    ->name('push-receipts')
    ->description('Check Expo push receipts and prune old push rows');

// Daily (System health, David 2026-10-19): resolved errors > 30 days, any
// error not seen for 90 days and hourly buckets > 14 days; M5-05 app error
// reports not seen for 30 days. 03:29 UTC.
Schedule::command('system-health:prune')
    ->dailyAt('03:29')
    ->withoutOverlapping()
    ->name('prune-system-errors')
    ->description('Delete old resolved / stale rows of the system error log and app error reports older than 30 days');

// Every 5 min (M5-T15, David 2026-10-22): queue the AI budget warning check —
// ≥ 80 % / 100 % of the production and AI Lab daily / monthly caps and a low
// fal.ai balance → Filament notification (+ e-mail with a real mailer) to the
// superadmins, once per threshold per budget day / month. Off the 5-minute mark.
Schedule::command('ai:budget-check')
    ->cron('3-59/5 * * * *')
    ->name('ai-budget-check')
    ->description('Warn the superadmins when an AI budget reaches 80 % / 100 % or the fal.ai balance is low');
