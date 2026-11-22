<?php

namespace App\Services\Validation;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition source of a parent (VALIDATION_PLAN §4.1, contract docs/engineering/VALIDATION_API.md
 * §1, David 2026-11-21). Written at sign-up (ParentRegistrationService / SocialSignInService fill
 * AcquisitionAnswer::columns() on the NEW user) or once later through recordOnce() — the only
 * writer of an existing user's `acquisition_*` columns.
 */
class AcquisitionSourceService
{
    /**
     * PATCH /api/parent/settings: stores the answer only while the parent has none yet (one chance
     * for parents who signed up earlier). A later answer is ignored. Children never have one.
     */
    public function recordOnce(User $parent, ?AcquisitionAnswer $answer): User
    {
        if ($answer === null || ! $parent->isParent() || $parent->acquisition_source !== null) {
            return $parent;
        }

        $stored = DB::transaction(function () use ($parent, $answer): ?string {
            $locked = User::whereKey($parent->id)->lockForUpdate()->firstOrFail();
            if ($locked->acquisition_source === null) {
                $locked->forceFill($answer->columns())->save();
            }

            return $locked->acquisition_source;
        });

        $fresh = User::whereKey($parent->id)->first(['acquisition_source', 'acquisition_detail', 'acquisition_recorded_at']);
        $parent->forceFill([
            'acquisition_source' => $stored,
            'acquisition_detail' => $fresh?->acquisition_detail,
            'acquisition_recorded_at' => $fresh?->acquisition_recorded_at,
        ])->syncOriginalAttributes(['acquisition_source', 'acquisition_detail', 'acquisition_recorded_at']);

        return $parent;
    }
}
