<?php

namespace App\Jobs;

use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reads Expo push receipts (≥ 15 min after the send) and disables devices
 * that are no longer registered (M3-02). Queued by `push:receipts`.
 */
class CheckPushReceipts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function handle(NotificationService $notifications, ExpoPushClient $client): void
    {
        $result = $notifications->checkReceipts($client);

        if ($result['checked'] > 0) {
            Log::info('CheckPushReceipts: receipts read', $result);
        }
    }
}
