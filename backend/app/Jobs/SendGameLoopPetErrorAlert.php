<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * David 2026-11-04: pets whose game-loop work (decay, escalation, routine day close …)
 * threw in a `pets:process-decay` run — queued by PetLoopGuard::finish() for pets not
 * yet alerted today (one claim per pet per Europe/Ljubljana day). Pet id, stage and
 * exception class only (no message, child or family data; System health has the
 * error). A failed post releases the claims so a later run retries. `alerts` queue.
 */
class SendGameLoopPetErrorAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  array<int, array{stage: string, exception: string}>  $pets  pet id => first failure
     * @param  int  $failedPets  all pets that failed in the run (also those already alerted today)
     * @param  list<string>  $claimKeys
     */
    public function __construct(
        public readonly array $pets,
        public readonly int $failedPets,
        public readonly array $claimKeys,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if ($this->pets === [] || ! $discord->configured()) {
            $this->releaseClaims();

            return;
        }

        $fields = [];
        foreach ($this->pets as $petId => $failure) {
            $fields[__('admin.game_loop.discord.pet', ['id' => $petId])] = $failure['stage'].' · '.class_basename($failure['exception']);
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::GameLoopPetError,
                __('admin.game_loop.discord.title', ['count' => count($this->pets), 'total' => $this->failedPets]),
                __('admin.game_loop.discord.body'),
                $fields,
            ));
        } catch (DiscordWebhookException $e) {
            $this->releaseClaims();
            report($e);
        }
    }

    private function releaseClaims(): void
    {
        foreach ($this->claimKeys as $key) {
            Cache::forget($key);
        }
    }
}
