<?php

namespace App\Jobs;

use App\Models\PetMedia;
use App\Services\Media\VideoCompressionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * David 2026-10-30: a new clip is compressed BEFORE it is shown — between the quality-check
 * verdict (or the staging with the check off, or a superadmin's »Approve anyway«) and the
 * promotion. Promotes the compressed key
 * or, when compression is rejected / fails, the original — a slot is never left waiting:
 * `failed()` (timeout, crash) promotes the original and marks the try as failed so a
 * re-dispatch (media:sweep → CheckPetMediaQuality) promotes directly; the nightly
 * `media:compress` may try the stored file again. Default queue (one worker), like the check.
 */
class CompressPetMediaCandidate implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Room for a release() while another run holds the row (nightly backfill / a duplicate). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30];

    /**
     * Below the queue's retry_after (90 s): copy + 4 ffmpeg / ffprobe calls ×
     * `pipeline_process_timeout_seconds` (20) + upload. A 5 s clip takes ~7 s (veryfast).
     */
    public int $timeout = 85;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $petMediaId,
        public readonly string $candidatePath,
        public readonly ?string $quality,
        public readonly ?string $reason,
        /** @var list<string> running (pipeline) or failed (»Approve anyway«) */
        public readonly array $fromStatuses = [PetMedia::STATUS_RUNNING],
    ) {}

    public function uniqueId(): string
    {
        return $this->petMediaId.':'.$this->candidatePath;
    }

    public function handle(VideoCompressionService $compression): void
    {
        if ($compression->compressCandidate($this->petMediaId, $this->candidatePath, $this->quality, $this->reason, $this->fromStatuses) === 'busy') {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $promoted = app(VideoCompressionService::class)->promoteOriginal($this->petMediaId, $this->candidatePath, $this->quality, $this->reason,
            failure: 'pipeline compression gave up: '.mb_substr((string) $exception?->getMessage(), 0, 200), fromStatuses: $this->fromStatuses);
        Log::warning('CompressPetMediaCandidate: gave up — original promoted', ['pet_media_id' => $this->petMediaId, 'promoted' => $promoted, 'error' => $exception?->getMessage()]);
    }
}
