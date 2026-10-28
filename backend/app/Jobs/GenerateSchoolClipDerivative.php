<?php

namespace App\Jobs;

use App\Services\Media\SchoolClipDerivativeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * School smooth playback (David 2026-10-28): make the ffmpeg-derived clip (attention /
 * success ping-pong loop, reversed performing = release) of one stored school phase clip.
 * Zero AI cost — no fal call, nothing in the AI budget / ledger
 * (SchoolClipDerivativeService::make). Unique per source row; idempotent. Dispatched by
 * PetMediaService::afterStored and the `media:school-derivatives` backfill (every 10 min),
 * which also picks up anything a lost job left behind.
 */
class GenerateSchoolClipDerivative implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [120];

    /** Below the queue's retry_after (90 s): 3 ffmpeg / ffprobe calls of ≤ 25 s each. */
    public int $timeout = 85;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(SchoolClipDerivativeService $derivatives): void
    {
        // A parallel run for the same row holds the lock — the backfill re-checks later.
        $derivatives->make($this->petMediaId);
    }
}
