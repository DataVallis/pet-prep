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

    /**
     * QA #1139 M1: the item keys a purchase just put on the pet (redeem only) — an ACTION item's
     * given-up chain gets one fresh round for them. A plain property with a default: a job queued
     * by an older release unserializes without it.
     *
     * @var list<string>
     */
    public array $purchased = [];

    /** @param  list<string>  $purchased */
    public function __construct(public readonly int $petId, array $purchased = [])
    {
        $this->purchased = array_values($purchased);
    }

    /** A purchase is never swallowed by a queued picture / stage job of the same pet. */
    public function uniqueId(): string
    {
        return $this->petId.($this->purchased === [] ? '' : ':'.implode(',', $this->purchased));
    }

    public function handle(ItemMediaService $items): void
    {
        $pet = Pet::find($this->petId);

        if ($pet === null) {
            return;
        }

        if ($this->purchased !== []) {
            $items->reopenGivenUp($pet, $this->purchased);
        }
        $items->ensure($pet->fresh() ?? $pet);
        $items->settle($pet->fresh() ?? $pet);
    }
}
