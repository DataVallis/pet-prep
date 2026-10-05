<?php

namespace App\Console\Commands;

use App\Jobs\CheckPushReceipts;
use App\Models\PushNotification;
use Illuminate\Console\Command;

/**
 * Every 15 minutes (M3-02): queue the Expo receipt check (HTTP runs in the
 * job, not in the scheduler) and delete push notification rows older than
 * 30 days (tickets cascade).
 */
class CheckPushReceiptsCommand extends Command
{
    protected $signature = 'push:receipts';

    protected $description = 'Queue the Expo push receipt check and prune push rows older than 30 days';

    public function handle(): int
    {
        if (config('push.enabled')) {
            CheckPushReceipts::dispatch()->onQueue((string) config('push.queue'));
        }

        $pruned = PushNotification::where('created_at', '<', now()->subDays(30))->delete();

        $this->info("Receipt check queued; pruned {$pruned} push notification(s) older than 30 days.");

        return self::SUCCESS;
    }
}
