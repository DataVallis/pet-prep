<?php

namespace App\Services;

use App\Enums\BreedFactStatus;
use App\Models\BreedFact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Status workflow of a breed fact in the admin (M5-R09b, spec §5):
 * draft → verified (source fields required) → approved (superadmin only,
 * records approved_by / approved_at). Approving needs a verified fact; a fact
 * can be sent back to draft (clears the approval). Content is edited in git
 * (breeds:import-facts), not here — a changed approved fact comes back
 * through the importer as draft / verified.
 */
class BreedFactReviewService
{
    public function verify(BreedFact $fact, User $by): BreedFact
    {
        if ($fact->status !== BreedFactStatus::Draft) {
            throw new InvalidArgumentException('Only a draft can be verified.');
        }
        if (! $fact->hasSource()) {
            throw new InvalidArgumentException('Source id, name, URL and quote are required before verifying.');
        }
        $fact->update(['status' => BreedFactStatus::Verified->value, 'verified_by' => 'admin#'.$by->id]);

        return $fact;
    }

    public function approve(BreedFact $fact, User $by): BreedFact
    {
        if (! $by->isSuperadmin()) {
            throw new InvalidArgumentException('Only a superadmin can approve.');
        }
        if ($fact->status !== BreedFactStatus::Verified) {
            throw new InvalidArgumentException('Only a verified fact can be approved.');
        }
        if (! $fact->hasSource()) {
            throw new InvalidArgumentException('Source id, name, URL and quote are required.');
        }
        $fact->update(['status' => BreedFactStatus::Approved->value, 'approved_by' => $by->id, 'approved_at' => now()]);

        return $fact;
    }

    /**
     * Approve every verified fact of $ids (others are skipped).
     *
     * @param  iterable<BreedFact>  $facts
     * @return array{approved: int, skipped: int}
     */
    public function approveMany(iterable $facts, User $by): array
    {
        if (! $by->isSuperadmin()) {
            throw new InvalidArgumentException('Only a superadmin can approve.');
        }
        $approved = 0;
        $skipped = 0;
        DB::transaction(function () use ($facts, $by, &$approved, &$skipped): void {
            foreach ($facts as $fact) {
                if ($fact->status === BreedFactStatus::Verified && $fact->hasSource()) {
                    $this->approve($fact, $by);
                    $approved++;
                } else {
                    $skipped++;
                }
            }
        });

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /** Back to draft (e.g. an error was found); clears verify + approval. */
    public function reject(BreedFact $fact, User $by): BreedFact
    {
        if (! $by->isSuperadmin()) {
            throw new InvalidArgumentException('Only a superadmin can change the status.');
        }
        $fact->update(['status' => BreedFactStatus::Draft->value, 'verified_by' => null, 'approved_by' => null, 'approved_at' => null]);

        return $fact;
    }
}
