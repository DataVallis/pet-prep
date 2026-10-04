<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Exceptions\FamilyException;
use App\Models\Family;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Second parent (M2-01, ADR-012): a parent of a family creates an invite
 * code; another parent account redeems it and becomes a parent of the same
 * family (sees every child and pet).
 *
 *  - code: 8 characters from an unambiguous alphabet (no 0/O/1/I/L), valid
 *    24 h, single use; a new code from the same parent revokes their
 *    previous unused one;
 *  - redeem: only a parent whose current family has no children and no pets
 *    (a fresh account) — otherwise 409 `family_not_empty` (merging two
 *    families with pets is out of scope, pending David);
 *  - brute force: 5 failed redeem attempts per account per 15 minutes →
 *    429 (independent of the route throttle).
 */
class FamilyInviteService
{
    public const CODE_LENGTH = 8;

    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const EXPIRY_HOURS = 24;

    public const MAX_FAILED_ATTEMPTS = 5;

    public const FAILED_ATTEMPTS_DECAY_SECONDS = 900;

    public function __construct(private readonly FamilyService $families) {}

    /**
     * @return array{code: string, expires_at: Carbon}
     */
    public function createInvite(User $parent): array
    {
        if (! $parent->isParent()) {
            throw new FamilyException('not_a_parent', 'Only a parent can invite another parent.', 403);
        }

        return DB::transaction(function () use ($parent): array {
            $family = $this->families->ensureFamilyFor($parent);

            FamilyInvite::where('created_by', $parent->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            do {
                $code = $this->randomCode();
            } while (FamilyInvite::where('code', $code)->exists());

            $expiresAt = now()->addHours(self::EXPIRY_HOURS)->startOfSecond();

            FamilyInvite::create([
                'family_id' => $family->id,
                'created_by' => $parent->id,
                'code' => $code,
                'expires_at' => $expiresAt,
            ]);

            return ['code' => $code, 'expires_at' => $expiresAt];
        });
    }

    /**
     * Redeem an invite code: $parent leaves their empty family and becomes a
     * parent of the inviting family.
     *
     * @throws FamilyException invalid_code | code_expired | code_used (422),
     *                         already_member | family_not_empty (409),
     *                         too_many_attempts (429), not_a_parent (403)
     */
    public function joinFamily(User $parent, string $code): Family
    {
        if (! $parent->isParent()) {
            throw new FamilyException('not_a_parent', 'Only a parent profile can join a family.', 403);
        }

        $limiterKey = 'family-join:'.$parent->id;
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILED_ATTEMPTS)) {
            throw new FamilyException('too_many_attempts', 'Too many wrong codes. Try again later.', 429);
        }

        $code = strtoupper(trim($code));

        try {
            $family = DB::transaction(function () use ($parent, $code): Family {
                $invite = FamilyInvite::where('code', $code)->lockForUpdate()->first();

                if ($invite === null) {
                    throw new FamilyException('invalid_code', 'This invite code does not exist.');
                }
                if ($invite->used_at !== null) {
                    throw new FamilyException('code_used', 'This invite code was already used.');
                }
                if (! $invite->expires_at->isFuture()) {
                    throw new FamilyException('code_expired', 'This invite code has expired.');
                }

                $target = Family::findOrFail($invite->family_id);
                $current = $this->families->familyOf($parent);

                if ($current !== null && $current->id === $target->id) {
                    throw new FamilyException('already_member', 'You are already a parent of this family.', 409);
                }

                if ($current !== null) {
                    $hasChildren = FamilyMember::where('family_id', $current->id)
                        ->where('role', FamilyRole::Child->value)->exists();
                    $hasPets = Pet::where('family_id', $current->id)->exists();
                    if ($hasChildren || $hasPets) {
                        throw new FamilyException('family_not_empty', 'Your account already has children or pets; families cannot be merged.', 409);
                    }

                    FamilyMember::where('user_id', $parent->id)->delete();
                    // The empty family (its quiet hours, open invites) goes
                    // away when nobody is left in it.
                    if (! FamilyMember::where('family_id', $current->id)->exists()) {
                        QuietHours::where('family_id', $current->id)->delete();
                        $current->delete();
                    }
                }

                // A parent's own quiet-hours row (parent_id unique) from the
                // old family must not shadow the new family's.
                QuietHours::where('parent_id', $parent->id)
                    ->where(fn ($q) => $q->whereNull('family_id')->orWhere('family_id', '!=', $target->id))
                    ->delete();

                $this->families->addMember($target, $parent, FamilyRole::Parent);
                // Deprecated mirror: the parent's users.timezone = family timezone.
                $parent->forceFill(['timezone' => $target->timezone])->saveQuietly();

                $invite->forceFill(['used_at' => now(), 'used_by' => $parent->id])->save();

                return $target;
            });
        } catch (FamilyException $e) {
            if (in_array($e->reason, ['invalid_code', 'code_used', 'code_expired'], true)) {
                RateLimiter::hit($limiterKey, self::FAILED_ATTEMPTS_DECAY_SECONDS);
            }

            throw $e;
        }

        RateLimiter::clear($limiterKey);
        $parent->unsetRelation('family');

        return $family;
    }

    private function randomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
