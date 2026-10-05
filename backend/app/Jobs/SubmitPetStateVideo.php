<?php

namespace App\Jobs;

use App\Enums\AiCallFailure;
use App\Models\PetMedia;
use App\Services\Media\PetMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Submits one pet state video (pet_media slot) to fal's queue with our signed
 * webhook (M4-03). The slot claim (pending → running) makes duplicates harmless;
 * budget / balance refusals fail the slot without retry (daily media:retry).
 */
class SubmitPetStateVideo implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(PetMediaService $media): void
    {
        if (! $media->submitVideo($this->petMediaId)) {
            throw new RuntimeException("fal.ai did not accept the state video for pet_media {$this->petMediaId}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $slot = PetMedia::find($this->petMediaId);

        if ($slot !== null && $slot->request_id === null && $slot->isInFlight()) {
            app(PetMediaService::class)->fail($slot, AiCallFailure::HttpError, $exception?->getMessage());
        }

        Log::error('SubmitPetStateVideo: giving up', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage()]);
    }
}
