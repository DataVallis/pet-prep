<?php

namespace App\Jobs;

use App\Models\ItemMedia;
use App\Services\Media\MediaQualityService;
use App\Services\Media\StillQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Check of a downloaded »Želje« item file candidate (David 2026-11-04): AI vision check of a
 * catalogue picture / key image; for a clip the free technical check + (David 2026-11-19) the AI
 * realism check of its contact sheet and start frame; then store / regenerate (a clip whose item
 * is wrong: its key image first) / »Needs review« (StillQualityService::checkItemCandidate). A
 * vision call without a verdict is retried later (release, ≤ VISION_ERROR_RETRIES). Never inside a DB
 * transaction (the vision call goes through FalGateway). Unique per row; the check row is the
 * claim against a double check / double spend. A lost job (or a check that waited for budget) is
 * re-dispatched by the item sweep (StoreItemMedia of a row that already has a candidate).
 */
class CheckItemMediaQuality implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Room for release()s while another worker holds the check or a vision error is retried (QA #1414 m2). */
    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [60];

    /**
     * QA #1414 m3: an item clip = ffprobe + frame statistics + the contact sheet (≤ 4 ffmpeg calls ×
     * 12 s) AND one vision call (30 s) + the copy from the disk — 110 s. Below the queue's
     * retry_after (config/queue.php redis / database, 150 s since 2026-11-19) so a running check is
     * never handed to a second worker.
     */
    public int $timeout = 110;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $itemMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemMediaId;
    }

    public function handle(StillQualityService $stills): void
    {
        match ($stills->checkItemCandidate($this->itemMediaId)) {
            'busy' => $this->release(MediaQualityService::STALE_CHECK_MINUTES * 60 + 30),
            'retry' => $this->release(120),
            default => null,
        };
    }

    public function failed(?Throwable $exception): void
    {
        // The row stays `running` with its candidate; the item sweep re-runs the check later.
        Log::error('CheckItemMediaQuality: giving up for now (the item sweep re-runs it)', ['item_media_id' => $this->itemMediaId, 'error' => $exception?->getMessage(), 'status' => ItemMedia::find($this->itemMediaId)?->status]);
    }
}
