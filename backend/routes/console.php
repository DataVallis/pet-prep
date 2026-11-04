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
| Overlap locks (DEPLOYMENT.md D34, incident 2026-11-04): EVERY withoutOverlapping()
| names an explicit expiry in minutes. Laravel's default is 1440 (24 h): a run killed
| before it finished (reboot, OOM, container stop) leaves its mutex in the Redis cache
| (AOF — it survives a reboot) and the scheduler silently skips the event until it
| expires — after the reboot of 2026-11-04 the game loop stood still for ~9 h. The
| expiry = the longest a killed run may block it; keep it just above the longest
| legitimate run. Every per-row write behind these commands is claimed or row-locked,
| so a rare overlap after an expiry is harmless. The scheduler container also clears
| all schedule mutexes when it starts (docker/production/entrypoint.sh), and
| `system-health:game-loop-check` alerts when the game loop stalls anyway.
|
*/

// Minutely: Process pet metric decay + escalation checks.
// Overlap lock 3 min: a tick takes seconds (per-pet units, PetLoopGuard) and every
// pet write re-reads the pet under lockForUpdate(), so an overlapping second tick only
// waits for the row locks; a killed tick blocks at most 3 ticks (< the 5-min stall alert).
Schedule::command('pets:process-decay')
    ->everyMinute()
    ->withoutOverlapping(3)
    ->runInBackground()
    ->name('pet-decay-loop')
    ->description('Minutely metric decay and escalation for active pets');

// Daily (M2-02): delete child login PINs that expired more than 7 days ago.
// 03:17 UTC — off the hour, outside the families' evening rush.
Schedule::command('pins:prune')
    ->dailyAt('03:17')
    // One DELETE query; 30 min covers any run (D34: explicit expiry).
    ->withoutOverlapping(30)
    ->name('prune-child-login-pins')
    ->description('Delete expired / used child login PINs older than 7 days');

// Daily (M4, PR #22 review; videos since M4-03): re-queue reference images and
// state videos blocked by the AI budget or an exhausted fal balance. 00:23 UTC —
// just after the budget day rolls over (AI_BUDGET_TIMEZONE defaults to UTC).
Schedule::command('media:retry')
    ->dailyAt('00:23')
    // Only queues jobs (each slot is claimed atomically); 60 min is far above a run (D34).
    ->withoutOverlapping(60)
    ->name('retry-reference-images')
    ->description('Re-queue AI reference images / state videos blocked by the AI budget / fal balance');

// Hourly: fail AI Lab results stuck in `running` for over an hour.
Schedule::command('media:sweep-lab')
    ->hourlyAt(41)
    // One UPDATE; below the hourly cadence (D34).
    ->withoutOverlapping(30)
    ->name('sweep-media-lab')
    ->description('Fail AI Lab results running for more than an hour');

// Hourly (M4-03): pet media stuck after a lost job / fal webhook — videos without
// a webhook after 2 h fail as timed_out, lost downloads and dead claims re-queue.
Schedule::command('media:sweep')
    ->hourlyAt(47)
    // Free fal status polls + re-queues of claimed slots; below the hourly cadence (D34).
    ->withoutOverlapping(30)
    ->name('sweep-pet-media')
    ->description('Recover pet media slots stuck after a lost job or webhook');

// Hourly (David 2026-10-28, home first — school and games wait for their clips; 2026-11-02
// self-healing): failed school / game clips are re-queued 30 min after the failure (≤ 2 auto
// retries per chain; after a fal refusal the next prompt variant, never the identical request);
// once the recovery is exhausted the activity switches to simple mode (playable, classic stage)
// and Discord gets one informative post. Chains reported before 2026-11-02 are picked up once.
// M6-W03 (David 2026-11-03): AI content of »Želje« items — catalogue pictures, the content of every
// owned item (also items bought before W03 — the automatic backfill), lost requests, self-healing.
Schedule::command('media:item-media')
    ->everyTenMinutes()
    // = the cadence: rows are read in batches of 500 and every job claims its row, so a
    // killed run blocks at most one run (D34).
    ->withoutOverlapping(10)
    ->name('item-media')
    ->description('AI content of bought items (catalogue pictures, pet-with-item pictures / clips), self-healing');

Schedule::command('media:retry-activity-clips')
    ->hourlyAt(17)
    // Retries are capped inside a conditional UPDATE (auto_retries); below the hourly cadence (D34).
    ->withoutOverlapping(30)
    ->name('retry-activity-clips')
    ->description('Heal failed school / game clips; exhausted activities switch to simple mode');

// Daily (David 2026-11-04): the AI still check of stored item key images made before the check
// existed (production bed: ghost paws). Self-limiting — a checked file generation is never
// selected again, so after the first run it finds nothing (0 $). A fail (stills mode enforce)
// regenerates the key image with a new seed; its clip follows. Pet key images: manual only
// (`media:still-check --pets --dry-run` shows count + cost).
Schedule::command('media:still-check --items')
    ->dailyAt('05:13')
    ->timezone('Europe/Ljubljana')
    // Few rows, one vision call each (a checked generation is never paid twice); 60 min is far
    // above a run and still releases a killed run's lock long before the next day (D34).
    ->withoutOverlapping(60)
    ->name('still-check-items')
    ->description('AI still check of stored item key images never checked; failures regenerated (enforce)');

// Daily (frugal clips, QA #451 m7): delete zero-cost previews older than frugal_preview_keep_hours.
Schedule::command('media:frugal-preview --prune')
    ->dailyAt('04:41')
    ->withoutOverlapping(30)
    ->description('Delete old frugal-clip previews (media:frugal-preview)');

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
    // Reads box headers only (fixed files rewritten once); below the hourly cadence (D34).
    ->withoutOverlapping(50)
    ->name('faststart-pet-media')
    ->description('Move the MP4 index to the front of stored pet videos');

// Hourly at night (David 2026-10-30): re-encode stored AI videos once (same resolution /
// fps, CRF 21) when it saves ≥ 30 % and SSIM ≥ 0.98 — only while the superadmin switch
// /admin → Feature switches »Video compression« is on (read fresh; default off → no-op).
// The compressed file gets a NEW key (rows switched in one transaction, the original deleted
// after a grace period by a later run). 22:00–06:00 Ljubljana: the server is idle. A run
// first recovers interrupted swaps; batches read with chunkById, stop after the time budget
// (media.compression.time_budget_seconds); new clips are picked up the next night.
Schedule::command('media:compress')
    ->hourlyAt(37)
    // timezone() BEFORE between(): between() captures the timezone when it is called (QA #450 M1).
    ->timezone('Europe/Ljubljana')
    ->between('22:00', '06:00')
    ->withoutOverlapping(60)
    // Up to the time budget (10 min) — never hold up the every-minute events of the same tick.
    ->runInBackground()
    ->name('compress-pet-media')
    ->description('Re-encode stored AI videos once when it saves disk and looks the same (switch-gated)');

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
    // Overlap lock 3 min (D34 — the incident of 2026-11-04 blocked it for ~9 h too): a run
    // queues at most DISPATCH_BATCH rows and claims each with a conditional UPDATE, so an
    // overlap never queues a push twice.
    ->withoutOverlapping(3)
    ->name('push-dispatch-scheduled')
    ->description('Queue held push notifications whose send_after has passed');

// Every 15 min (M3-02): queue the Expo push receipt check (DeviceNotRegistered →
// device disabled) and delete push rows older than 30 days. Off the quarter hour.
Schedule::command('push:receipts')
    ->cron('7,22,37,52 * * * *')
    // Only queues one job; = the 15-min cadence (D34).
    ->withoutOverlapping(15)
    ->name('push-receipts')
    ->description('Check Expo push receipts and prune old push rows');

// Daily (System health, David 2026-10-19): resolved errors > 30 days, any
// error not seen for 90 days and hourly buckets > 14 days; M5-05 app error
// reports not seen for 30 days. 03:29 UTC.
Schedule::command('system-health:prune')
    ->dailyAt('03:29')
    // A few DELETE queries; 60 min covers any run (D34).
    ->withoutOverlapping(60)
    ->name('prune-system-errors')
    ->description('Delete old resolved / stale rows of the system error log and app error reports older than 30 days');

// Hourly (DEPLOYMENT.md D31, incident 2026-11-04: the root disk hit 100 % and took
// production down): root disk ≥ system_health.disk_alert_percent (80 %) → ONE Discord
// `disk_space` alert per day (Europe/Ljubljana), numbers only. Off the hour.
Schedule::command('system-health:disk-check')
    ->hourlyAt(11)
    ->withoutOverlapping(10)
    ->name('disk-space-check')
    ->description('Discord alert once per day when the root disk is ≥ 80 % full');

// Every minute (DEPLOYMENT.md D34, incident 2026-11-04: a stale overlap lock stopped the game
// loop for ~9 h and nothing alerted): its OWN event, never part of the tick it watches. The
// `pets:process-decay` heartbeat older than system_health.heartbeat_stale_minutes (5) → ONE
// Discord `game_loop_stalled` alert per stall episode (minutes since the last tick only),
// re-armed when a tick finishes again. Sent directly, not queued (independent of the queue
// worker), so in the background — a slow Discord never holds up push:dispatch-scheduled.
// Overlap lock 2 min: a run is one cache read + at most one post (10 s timeout).
Schedule::command('system-health:game-loop-check')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground()
    ->name('game-loop-check')
    ->description('Discord alert once per stall when the game-loop heartbeat is older than 5 min');

// Every 5 min (M5-T15, David 2026-10-22): queue the AI budget warning check —
// ≥ 80 % / 100 % of the production and AI Lab daily / monthly caps and a low
// fal.ai balance → Filament notification (+ e-mail with a real mailer) to the
// superadmins, once per threshold per budget day / month. Off the 5-minute mark.
Schedule::command('ai:budget-check')
    ->cron('3-59/5 * * * *')
    ->name('ai-budget-check')
    ->description('Warn the superadmins when an AI budget reaches 80 % / 100 % or the fal.ai balance is low');
