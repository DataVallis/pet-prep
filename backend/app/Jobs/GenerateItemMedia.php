<?php

namespace App\Jobs;

use App\Enums\AiCallFailure;
use App\Models\ItemMedia;
use App\Services\Media\ItemMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * M6-W03: submit one `item_media` row to fal (catalogue picture, item key image or item clip —
 * ItemMediaService::generate). The row claim makes a duplicate harmless; retried only when fal
 * was never reached. Never inside a DB transaction (FalGateway refuses it).
 */
class GenerateItemMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    /** Below the queue's retry_after (90 s); the submit itself is capped well below. */
    public int $timeout = 75;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $itemMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->itemMediaId;
    }

    public function handle(ItemMediaService $items): void
    {
        if (! $items->generate($this->itemMediaId)) {
            throw new RuntimeException("Submitting item_media {$this->itemMediaId} did not reach fal");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $row = ItemMedia::find($this->itemMediaId);

        if ($row !== null && in_array($row->status, [ItemMedia::STATUS_PENDING, ItemMedia::STATUS_RUNNING], true) && $row->request_id === null) {
            app(ItemMediaService::class)->fail($row, AiCallFailure::HttpError, 'Submit failed: '.$exception?->getMessage());
        }
    }
}
