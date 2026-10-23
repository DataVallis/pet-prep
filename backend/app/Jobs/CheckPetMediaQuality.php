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

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [60];

    /** Below the queue's retry_after (90 s): ffmpeg (≤ 20 s per call) + the vision call (≤ 45 s). */
    public int $timeout = 85;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(MediaQualityService $quality): void
    {
        $quality->checkCandidate($this->petMediaId);
    }

    public function failed(?Throwable $exception): void
    {
        // The slot stays `running` with its candidate; media:sweep re-runs the check later.
        Log::error('CheckPetMediaQuality: giving up for now (media:sweep re-runs it)', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage(), 'status' => PetMedia::find($this->petMediaId)?->status]);
    }
}
