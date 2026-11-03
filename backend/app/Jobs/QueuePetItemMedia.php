<?php

namespace App\Jobs;

use App\Models\Pet;
use App\Services\Media\ItemMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * M6-W03: a pet got an item (redeem) or a new picture (life stage): queue the content of its
 * items for its current picture and tell the family about whatever is already there
 * (ItemMediaService::ensure + settle — a shared look's content costs 0 $).
 */
class QueuePetItemMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $petId) {}

    public function uniqueId(): string
    {
        return (string) $this->petId;
    }

    public function handle(ItemMediaService $items): void
    {
        $pet = Pet::find($this->petId);

        if ($pet === null) {
            return;
        }

        $items->ensure($pet);
        $items->settle($pet->fresh() ?? $pet);
    }
}
