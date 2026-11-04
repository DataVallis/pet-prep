<?php

namespace App\Services\GameLoop;

use App\Jobs\SendGameLoopPetErrorAlert;
use App\Services\Alerts\DiscordWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-pet error isolation of the game loop (`pets:process-decay`, David 2026-11-04 after
 * PR #737: a pet's stale `escalation_level = 3` stayed an hour because one throwing pet
 * aborted that minute's loop for every pet after it — and a throw in the decay loop
 * skipped escalation for everyone).
 *
 * Every per-pet loop of the tick wraps each pet's unit of work (its own transaction +
 * row lock) in try / catch and hands a failure to {@see failed()}: the exception is
 * `report()`ed (→ System health `system_errors`, deduplicated by fingerprint), the loop
 * continues with the next pet. The command calls {@see begin()} first and {@see finish()}
 * last: one error-level log line per run with the counts (System health), and ONE queued
 * Discord `game_loop_pet_error` alert per pet per Europe/Ljubljana day (pet id, stage and
 * exception class only — never a message, child or family data), so a pet that keeps
 * failing every minute is noticed without spam.
 *
 * A new per-pet loop in the tick MUST do the same (backend/CLAUDE.md, game-loop invariants).
 */
class PetLoopGuard
{
    public const CLAIM_PREFIX = 'game_loop:pet_alert:';

    /** Pets listed in one Discord post (embed field limit is 25). */
    public const MAX_PETS_PER_ALERT = 20;

    /** @var array<int, array{stage: string, exception: string}> first failure of each pet in this run */
    private array $failures = [];

    /** @var array<string, int> failures per stage in this run */
    private array $perStage = [];

    public function begin(): void
    {
        $this->failures = [];
        $this->perStage = [];
    }

    /**
     * A pet's unit of work threw (its transaction is already rolled back): report it
     * and remember it for this run. Never throws.
     */
    public function failed(string $stage, int $petId, Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // the error path must never stop the loop
        }

        $this->perStage[$stage] = ($this->perStage[$stage] ?? 0) + 1;
        $this->failures[$petId] ??= ['stage' => $stage, 'exception' => $e::class];
    }

    /** Failed pet units in this run (a pet failing in two stages counts twice). */
    public function failureCount(): int
    {
        return array_sum($this->perStage);
    }

    /** @return array<string, int> */
    public function failuresPerStage(): array
    {
        return $this->perStage;
    }

    /** @return array<int, array{stage: string, exception: string}> */
    public function failedPets(): array
    {
        return $this->failures;
    }

    /**
     * End of the run: one System health log line when any pet failed, and the Discord
     * alert for pets not yet alerted today. Returns the number of pets in the alert.
     * Never throws (a broken cache / queue must not fail the tick).
     */
    public function finish(): int
    {
        if ($this->failures === []) {
            return 0;
        }

        try {
            Log::error('Game loop: pet processing failed this run — the other pets were processed', [
                'failed_pets' => count($this->failures),
                'failed_units' => $this->failureCount(),
                'stages' => $this->perStage,
            ]);
        } catch (Throwable) {
            // best effort
        }

        try {
            return $this->alert();
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // best effort
            }

            return 0;
        }
    }

    private function alert(): int
    {
        if (! app(DiscordWebhook::class)->configured()) {
            return 0;
        }

        $pets = [];
        $keys = [];
        foreach ($this->failures as $petId => $failure) {
            if (count($pets) >= self::MAX_PETS_PER_ALERT) {
                break;
            }
            $key = self::claimKey($petId);
            if (Cache::add($key, true, now()->addHours(48))) {
                $pets[$petId] = $failure;
                $keys[] = $key;
            }
        }

        if ($pets === []) {
            return 0;
        }

        SendGameLoopPetErrorAlert::dispatch($pets, count($this->failures), $keys);

        return count($pets);
    }

    public static function claimKey(int $petId): string
    {
        $tz = (string) config('system_health.game_loop_alert_timezone', 'Europe/Ljubljana');

        return self::CLAIM_PREFIX.Date::now()->setTimezone($tz)->toDateString().':'.$petId;
    }
}
