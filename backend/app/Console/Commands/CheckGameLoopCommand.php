<?php

namespace App\Console\Commands;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * DEPLOYMENT.md D34 (incident 2026-11-04: after a reboot a killed `pets:process-decay` run left
 * its withoutOverlapping mutex behind and the scheduler silently skipped the game loop for ~9 h).
 *
 * Runs every minute as its OWN scheduled event (never inside the tick it watches): when the
 * game-loop heartbeat (SystemHealthService::beat(), written at the end of every tick) is older
 * than system_health.heartbeat_stale_minutes (5), it posts ONE Discord `game_loop_stalled` alert
 * per stall episode — numbers only (minutes since the last tick). The episode is keyed by the last
 * heartbeat itself, so the alert re-arms as soon as a tick finishes again. No heartbeat at all
 * (fresh cache) counts from the first check that saw none.
 *
 * Sent directly (not queued): the alert must not depend on a queue worker that may be down too.
 * A failed post releases the claim, so the next minute retries. After a gap in its own runs
 * (scheduler restarted, maintenance mode) it only observes once, so the tick of the same minute
 * can beat first — no false alarm after a deploy. Limit: if the scheduler container itself is
 * down, nothing here runs (the System health tile still turns red).
 */
class CheckGameLoopCommand extends Command
{
    public const LAST_RUN_KEY = 'system_health:game_loop_check:last_run';

    public const MISSING_SINCE_KEY = 'system_health:game_loop_check:missing_since';

    public const CLAIM_PREFIX = 'system_health:game_loop_stalled:';

    /** A previous check older than this → the checker itself was not running; observe only. */
    public const WARM_UP_GAP_MINUTES = 3;

    protected $signature = 'system-health:game-loop-check';

    protected $description = 'Post one Discord admin alert per stall when the game-loop heartbeat (pets:process-decay) is older than 5 min';

    public function handle(SystemHealthService $health, DiscordWebhook $discord): int
    {
        $now = Carbon::now()->utc();
        $previousRun = Cache::get(self::LAST_RUN_KEY);
        Cache::put(self::LAST_RUN_KEY, $now->toIso8601String(), $now->copy()->addDay());

        $threshold = max(1, (int) config('system_health.heartbeat_stale_minutes', 5));
        $last = $health->lastHeartbeat();

        if ($last === null) {
            Cache::add(self::MISSING_SINCE_KEY, $now->toIso8601String(), $now->copy()->addDays(7));
            $since = Carbon::parse((string) Cache::get(self::MISSING_SINCE_KEY, $now->toIso8601String()))->utc();
            $episode = 'missing:'.$since->getTimestamp();
        } else {
            Cache::forget(self::MISSING_SINCE_KEY);
            $since = $last;
            $episode = (string) $last->getTimestamp();
        }

        $seconds = max(0, $now->getTimestamp() - $since->getTimestamp());
        $minutes = intdiv($seconds, 60);
        // Same rule as the System health tile (heartbeatIsStale): strictly older than the threshold.
        if ($seconds <= $threshold * 60) {
            $this->info("Game loop OK: last tick {$minutes} min ago.");

            return self::SUCCESS;
        }

        $this->warn("Game loop stalled: no finished tick for {$minutes} min (alert at {$threshold}).");

        if (! is_string($previousRun) || Carbon::parse($previousRun)->lt($now->copy()->subMinutes(self::WARM_UP_GAP_MINUTES))) {
            $this->info('The check itself was not running before — observing once before alerting.');

            return self::SUCCESS;
        }

        if (! $discord->configured()) {
            return self::SUCCESS;
        }

        $key = self::CLAIM_PREFIX.$episode;
        if (! Cache::add($key, true, $now->copy()->addDays(7))) {
            $this->info('Stall alert already sent for this episode.');

            return self::SUCCESS;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::GameLoopStalled,
                __('admin.game_loop_stalled.discord.title', ['minutes' => $minutes]),
                __('admin.game_loop_stalled.discord.body', ['threshold' => $threshold]),
                [
                    __('admin.game_loop_stalled.discord.minutes') => (string) $minutes,
                    __('admin.game_loop_stalled.discord.threshold') => (string) $threshold,
                ],
            ));
            $this->info('Stall alert posted.');
        } catch (DiscordWebhookException $e) {
            Cache::forget($key);
            report($e);
        }

        return self::SUCCESS;
    }
}
