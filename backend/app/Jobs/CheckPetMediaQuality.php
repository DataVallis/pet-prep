<?php

namespace App\Jobs;

use App\Models\PetMedia;
use App\Services\Media\MediaQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Quality check of a downloaded clip candidate (David 2026-10-23): technical (ffmpeg) +
 * AI vision check, then promote / one automatic regeneration / »Needs review"
 * (MediaQualityService::checkCandidate). Never inside a DB transaction (the vision call
 * goes through FalGateway). Unique per slot; the check row is the claim against a double
 * check / double spend. A lost job is re-dispatched by media:sweep (StorePetMedia of a
 * slot that already has a candidate).
 */
class CheckPetMediaQuality implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** QA #363 m2: room for a release() while another worker holds the check. */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60];

    /** Below the queue's retry_after (90 s): worst case 4 ffmpeg calls × 12 s + the vision call 30 s = 78 s. */
    public int $timeout = 85;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(MediaQualityService $quality): void
    {
        // QA #363 m2: another worker holds the check right now — come back later instead of dropping it.
        if ($quality->checkCandidate($this->petMediaId) === 'busy') {
            $this->release(MediaQualityService::STALE_CHECK_MINUTES * 60 + 30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // The slot stays `running` with its candidate; media:sweep re-runs the check later.
        Log::error('CheckPetMediaQuality: giving up for now (media:sweep re-runs it)', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage(), 'status' => PetMedia::find($this->petMediaId)?->status]);
    }
}
