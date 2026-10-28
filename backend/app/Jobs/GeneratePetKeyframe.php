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
 * kind keyframe; M5-T09 / T10: or a POSE image) — a Nano Banana Pro Edit of the
 * stage's reference image in the state's pose.
 *
 * Fix 2026-10-28 (David): the edit is SUBMITTED to fal's queue with the signed
 * webhook (like SubmitPetStateVideo) — this job only submits (seconds), the
 * image arrives on the webhook → StorePetMedia → queueStateVideos() submits the
 * state's loop (and the transitions / phase clips whose poses are there). A lost
 * webhook is polled / timed out by media:sweep. The slot claim makes duplicates
 * harmless (a running slot with a request id is never submitted again); a retry
 * happens only when fal never took the request (ledger void); budget / balance
 * refusals and a sent-but-unanswered submit fail the slot without retry.
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
            throw new RuntimeException("fal.ai did not accept the key / pose image for pet_media {$this->petMediaId}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $slot = PetMedia::find($this->petMediaId);

        // A slot fal accepted (request id) waits for its webhook / the sweep — never failed here.
        if ($slot !== null && $slot->request_id === null && $slot->isInFlight()) {
            app(PetMediaService::class)->fail($slot, AiCallFailure::HttpError, $exception?->getMessage());
        }

        Log::error('GeneratePetKeyframe: giving up', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage()]);
    }
}
