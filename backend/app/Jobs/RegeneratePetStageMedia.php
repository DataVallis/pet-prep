<?php

namespace App\Jobs;

use App\Models\Pet;
use App\Services\Media\PetMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The pet reached a new life stage (M5-R01): a new reference image of the
 * same dog at the new stage (image-to-image from the previous one, kept in
 * pet_media_history), then the entitled state videos
 * (PetMediaService::startStageTransition). Queued once by the decay tick
 * after the commit that changed `pets.life_stage` (row lock); unique per
 * pet so a duplicate dispatch is harmless. No fal call happens here — the
 * job only archives and queues GeneratePetReferenceImage.
 */
class RegeneratePetStageMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** A running first image / regeneration: try again later. */
    public const BUSY_RETRY_SECONDS = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petId) {}

    public function uniqueId(): string
    {
        return (string) $this->petId;
    }

    public function handle(PetMediaService $media): void
    {
        $pet = Pet::find($this->petId);

        if ($pet === null) {
            return;
        }

        $outcome = $media->startStageTransition($pet);

        if ($outcome === 'busy' && $this->attempts() < $this->tries) {
            $this->release(self::BUSY_RETRY_SECONDS);

            return;
        }

        Log::info('RegeneratePetStageMedia', ['pet_id' => $pet->id, 'life_stage' => $pet->life_stage?->value, 'outcome' => $outcome]);
    }
}
