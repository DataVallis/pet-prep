<?php

namespace App\Jobs;

use App\Enums\AiCallFailure;
use App\Events\PetUpdated;
use App\Models\Pet;
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
 * Generates the canonical reference image (Pet DNA anchor) for a new pet —
 * first step of the media pipeline (M4-03, PetMediaService). On success the
 * fal result is downloaded by StorePetMedia, which then queues the state videos.
 *
 * Runs on the queue so pairing never waits on fal.ai and no external HTTP call
 * happens inside a database transaction. Dispatched after the pairing commit.
 */
class GeneratePetReferenceImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Must stay below the queue connection's retry_after (90 s) — see config/queue.php. */
    public int $timeout = 75;

    /** Release the uniqueness lock even if the dispatching transaction rolled back. */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petId) {}

    public function uniqueId(): string
    {
        return (string) $this->petId;
    }

    public function handle(PetMediaService $media): void
    {
        $pet = Pet::find($this->petId);

        if (! $pet || ! $pet->is_active) {
            return;
        }

        if (! $media->generateReferenceImage($pet)) {
            // Let the queue retry with backoff; failed() marks the pet when retries run out.
            throw new RuntimeException("fal.ai reference image generation failed for pet {$pet->id}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $pet = Pet::find($this->petId);

        if ($pet) {
            $slot = PetMedia::where('pet_id', $pet->id)->where('kind', PetMedia::KIND_IMAGE)->first();

            if ($slot !== null && $slot->status !== PetMedia::STATUS_READY) {
                app(PetMediaService::class)->fail($slot, AiCallFailure::HttpError, $exception?->getMessage());
            }

            if ($pet->media_status !== 'ready') {
                $pet->updateQuietly(['media_status' => 'failed', 'media_error' => AiCallFailure::HttpError->value]);
                PetUpdated::afterCommit($pet->fresh(), 'reference_image_failed');
            }
        }

        Log::error('GeneratePetReferenceImage: giving up', [
            'pet_id' => $this->petId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
