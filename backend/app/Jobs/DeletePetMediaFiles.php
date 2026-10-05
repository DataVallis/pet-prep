<?php

namespace App\Jobs;

use App\Models\Pet;
use App\Services\Media\PetMediaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Delete the stored AI media files (`pet_media` disk, one directory per pet) of
 * pets whose rows are gone (M2-08). Dispatched after the deleting transaction
 * committed — a rolled-back deletion never loses files. Idempotent: a missing
 * directory is fine, and a pet that still exists is skipped (never deletes the
 * files of a live pet).
 */
class DeletePetMediaFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  list<int>  $petIds
     */
    public function __construct(public readonly array $petIds) {}

    public function handle(PetMediaService $media): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->petIds)));
        if ($ids === []) {
            return;
        }

        $alive = Pet::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($ids, $alive) as $petId) {
            $media->deleteFilesOf($petId);
        }

        if ($alive !== []) {
            Log::warning('DeletePetMediaFiles: skipped pets that still exist', ['pet_ids' => $alive]);
        }
    }
}
