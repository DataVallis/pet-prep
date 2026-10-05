<?php

namespace App\Jobs;

use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\ExpoPushException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one escalation push (push_notifications row) through Expo (M3-02).
 * Queued after the escalation commits; unique per notification so a
 * duplicate dispatch can't send twice at the same time. Transient Expo
 * errors (connection, 429, 5xx) retry with backoff; a rejected request fails
 * at once. Already ticketed devices are skipped on a retry.
 */
class SendPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 180];

    public int $timeout = 30;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $pushNotificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->pushNotificationId;
    }

    public function handle(NotificationService $notifications, ExpoPushClient $client): void
    {
        try {
            $notifications->deliver($this->pushNotificationId, $client);
        } catch (ExpoPushException $e) {
            if (! $e->retryable) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(NotificationService::class)->markFailed($this->pushNotificationId, $exception?->getMessage() ?? 'failed');

        Log::error('SendPushNotification: giving up', [
            'push_notification_id' => $this->pushNotificationId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
