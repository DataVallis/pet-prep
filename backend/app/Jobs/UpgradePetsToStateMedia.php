<?php

namespace App\Jobs;

use App\Services\Media\StateMediaUpgradePlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * M5-T16: one chunk of the superadmin bulk »Upgrade existing pets to new media«
 * (StateMediaUpgradePlanner::upgradeChunk). Each pet is re-checked, moved to state media
 * version 3 and its pipeline queued through PetMediaService::generateMissing — the media
 * jobs claim their (look) slots, go through FalGateway / AiSpendGuard and read both
 * superadmin switches fresh, so running a chunk twice never pays twice.
 *
 * QA m2: idempotent (a pet already on version 3 is no longer eligible) → 3 tries; the
 * planner logs done / skipped / failed per chunk. QA M1: `onlyComplete` skips any pet whose
 * fresh plan would cost money.
 */
class UpgradePetsToStateMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @param  list<int>  $petIds
     */
    public function __construct(public readonly array $petIds, public readonly bool $onlyComplete = false) {}

    public function handle(StateMediaUpgradePlanner $planner): void
    {
        $planner->upgradeChunk($this->petIds, $this->onlyComplete);
    }
}
