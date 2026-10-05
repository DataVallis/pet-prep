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
 * Downloads a fal result (allowlisted URL) to the private pet-media disk and
 * marks the slot ready (M4-05). Size / content-type violations fail the slot
 * permanently; network errors retry. No HTTP inside a DB transaction.
 */
class StorePetMedia implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [20, 120, 600];

    /** Below the queue's retry_after (90 s); the download itself is capped at 80 s. */
    public int $timeout = 85;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $petMediaId) {}

    public function uniqueId(): string
    {
        return (string) $this->petMediaId;
    }

    public function handle(PetMediaService $media): void
    {
        if (! $media->storeResult($this->petMediaId)) {
            throw new RuntimeException("Downloading pet_media {$this->petMediaId} failed");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $slot = PetMedia::find($this->petMediaId);

        if ($slot !== null && $slot->status === PetMedia::STATUS_RUNNING) {
            app(PetMediaService::class)->fail($slot, AiCallFailure::HttpError, 'Download failed: '.$exception?->getMessage());

            if ($slot->isImage() && $slot->pet !== null && $slot->pet->media_status !== 'ready') {
                $slot->pet->updateQuietly(['media_status' => 'failed', 'media_error' => AiCallFailure::HttpError->value]);
            }
        }

        Log::error('StorePetMedia: giving up', ['pet_media_id' => $this->petMediaId, 'error' => $exception?->getMessage()]);
    }
}
