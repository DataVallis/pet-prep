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
 * M5-T08 (APP_REFINEMENT_SPEC §7): generates one state KEY IMAGE (pet_media
 * kind keyframe) — a Nano Banana Pro Edit of the stage's reference image in
 * the state's pose. The slot claim makes duplicates harmless; budget / balance
 * refusals fail the slot without retry (daily media:retry). Once stored,
 * StorePetMedia → queueStateVideos() submits the state's loop (and the
 * transitions whose two poses are there).
 */
class GeneratePetKeyframe implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Must stay below the queue connection's retry_after (90 s). */
    public int $timeout = 75;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(PetMediaService $media): void
    {
        if (! $media->generateKeyframe($this->petMediaId)) {
            throw new RuntimeException("fal.ai key image generation failed for pet_media {$this->petMediaId}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $slot = PetMedia::find($this->petMediaId);

        if ($slot !== null && $slot->status !== PetMedia::STATUS_READY && $slot->source_url === null) {
            app(PetMediaService::class)->fail($slot, AiCallFailure::HttpError, $exception?->getMessage());
        }

        Log::error('GeneratePetKeyframe: giving up', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage()]);
    }
}
