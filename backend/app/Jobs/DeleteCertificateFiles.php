<?php

namespace App\Jobs;

use App\Models\ChallengeCertificate;
use App\Models\Pet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Delete certificate PDFs (M2-01d, `certificates` disk) whose rows are gone:
 * the directory of each deleted pet (`{pet_id}/`) and single files of a deleted
 * child on a pet that stays (shared pet). Dispatched after the deleting
 * transaction committed (AccountDeletionService, the Pet::deleted hook), so a
 * rolled-back deletion never loses a file. Idempotent; never touches a pet
 * that still exists or a path a row still points at.
 */
class DeleteCertificateFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /**
     * @param  list<int>  $petIds  pets whose whole directory goes
     * @param  list<string>  $paths  single files (a deleted child's certificates on a kept pet)
     */
    public function __construct(public readonly array $petIds = [], public readonly array $paths = []) {}

    public function handle(): void
    {
        $disk = Storage::disk('certificates');

        $ids = array_values(array_unique(array_map('intval', $this->petIds)));
        $alive = $ids === [] ? [] : Pet::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff($ids, $alive) as $petId) {
            $disk->deleteDirectory((string) $petId);
        }

        $paths = array_values(array_unique(array_filter($this->paths, fn ($p) => is_string($p) && $p !== '')));
        if ($paths === []) {
            return;
        }
        $used = ChallengeCertificate::whereIn('pdf_path', $paths)->pluck('pdf_path')->all();
        foreach (array_diff($paths, $used) as $path) {
            $disk->delete($path);
        }
    }
}
