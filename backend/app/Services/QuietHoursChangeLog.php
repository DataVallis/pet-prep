<?php

namespace App\Services;

use App\Enums\QuietHoursChangeScope;
use App\Http\Middleware\RecordClientFeatures;
use App\Models\QuietHoursChange;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The quiet hours change log (David 2026-11-24, »ja naredi«): one
 * `quiet_hours_changes` row per parent save that actually changed a schedule
 * — the family's row (QuietHoursController::save) or a pet's own hours,
 * including switching back to follow the family (PetQuietHoursService). The
 * only writer of that table; call it inside the save's transaction so the row
 * and the change commit together.
 *
 * Why: a tester family's quiet hours were overwritten 16 s after sign-up by a
 * save with every time null; request logs were already gone. The row keeps
 * before → after (normalised, QuietHours::normaliseValues), the parent, the
 * app's User-Agent and its `X-PetPrep-Features` header (both truncated to
 * 255) — no child data. Not logged: the default row FamilyService creates
 * with a family (its created_at shows it).
 */
class QuietHoursChangeLog
{
    public const MAX_HEADER_LENGTH = 255;

    /**
     * Record a change; same values before and after → nothing (null).
     *
     * @param  array<string, mixed>|null  $before  family: null = no row existed; pet: null = followed the family
     * @param  array<string, mixed>|null  $after  pet: null = follows the family again
     */
    public function record(
        int $familyId,
        ?int $petId,
        ?User $by,
        QuietHoursChangeScope $scope,
        ?array $before,
        ?array $after,
        ?Request $request = null,
    ): ?QuietHoursChange {
        if ($before === $after) {
            return null;
        }

        $request ??= app()->bound('request') ? app('request') : null;

        return QuietHoursChange::create([
            'family_id' => $familyId,
            'pet_id' => $scope === QuietHoursChangeScope::Pet ? $petId : null,
            'user_id' => $by?->id,
            'scope' => $scope,
            'before' => $before,
            'after' => $after,
            'user_agent' => self::truncate($request?->userAgent()),
            'client_features' => self::truncate($request?->header(RecordClientFeatures::HEADER)),
        ]);
    }

    private static function truncate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strcut($value, 0, self::MAX_HEADER_LENGTH, 'UTF-8');
    }
}
