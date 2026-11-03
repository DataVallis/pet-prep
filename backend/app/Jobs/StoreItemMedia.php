<?php

namespace App\Jobs;

use App\Models\ItemMedia;
use App\Services\Media\ItemMediaService;
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
 * M6-W03: download an item row's fal result (allowlisted URL) to the media disk and store it
 * (ItemMediaService::storeResult) — then the next step (key image → clip → the pets are told).
 */
class StoreItemMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [20, 120, 600];

    public int $timeout = 85;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $itemMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemMediaId;
    }

    public function handle(ItemMediaService $items): void
    {
        if (! $items->storeResult($this->itemMediaId)) {
            throw new RuntimeException("Downloading item_media {$this->itemMediaId} failed");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $row = ItemMedia::find($this->itemMediaId);

        // QA #655 m5: our download failed — the fal URL may still be valid: the sweep downloads again
        // (free) before the row fails and is regenerated (paid).
        if ($row !== null && $row->status === ItemMedia::STATUS_RUNNING) {
            app(ItemMediaService::class)->downloadFailed($row, (string) $exception?->getMessage());
        }

        Log::error('StoreItemMedia: giving up', ['item_media_id' => $this->itemMediaId, 'error' => $exception?->getMessage()]);
    }
}
