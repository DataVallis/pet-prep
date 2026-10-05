<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Every minute (M3-02, PR #35 review): queue held pushes whose `send_after`
 * has passed — illness / game over held over quiet hours, the daily walk
 * reminder held until 2 h after the last quiet stretch. The send job
 * re-checks quiet hours, the pet and the walk.
 */
class DispatchScheduledPushesCommand extends Command
{
    protected $signature = 'push:dispatch-scheduled';

    protected $description = 'Queue held push notifications whose time has come';

    public function handle(NotificationService $notifications): int
    {
        if (! config('push.enabled')) {
            return self::SUCCESS;
        }

        $queued = $notifications->dispatchScheduled();
        if ($queued > 0) {
            $this->info("Queued {$queued} held push notification(s).");
        }

        return self::SUCCESS;
    }
}
