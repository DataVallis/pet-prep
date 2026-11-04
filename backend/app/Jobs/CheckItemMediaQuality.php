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
 * catalogue picture / key image, free technical check of a clip, then store / regenerate with a
 * new seed / »Needs review« (StillQualityService::checkItemCandidate). Never inside a DB
 * transaction (the vision call goes through FalGateway). Unique per row; the check row is the
 * claim against a double check / double spend. A lost job (or a check that waited for budget) is
 * re-dispatched by the item sweep (StoreItemMedia of a row that already has a candidate).
 */
class CheckItemMediaQuality implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Room for a release() while another worker holds the check. */
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60];

    /** Below the queue's retry_after (90 s): one vision call (30 s) or ≤ 4 ffmpeg calls × 12 s. */
    public int $timeout = 85;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $itemMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemMediaId;
    }

    public function handle(StillQualityService $stills): void
    {
        if ($stills->checkItemCandidate($this->itemMediaId) === 'busy') {
            $this->release(MediaQualityService::STALE_CHECK_MINUTES * 60 + 30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        // The row stays `running` with its candidate; the item sweep re-runs the check later.
        Log::error('CheckItemMediaQuality: giving up for now (the item sweep re-runs it)', ['item_media_id' => $this->itemMediaId, 'error' => $exception?->getMessage(), 'status' => ItemMedia::find($this->itemMediaId)?->status]);
    }
}
