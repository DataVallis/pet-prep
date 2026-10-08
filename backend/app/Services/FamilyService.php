<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Exceptions\FamilyException;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\QuietHours;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Family model (M2-01, ADR-012): membership, caretakers, recipients.
 *
 * Invariants enforced here (and by the database where feasible):
 *  - a user belongs to at most one family (family_user.user_id unique);
 *  - a pet belongs to exactly one family (pets.family_id NOT NULL) and has
 *    at least one caretaker child;
 *  - only children are caretakers; parents never act on a pet;
 *  - a child cares for at most one ACTIVE pet at a time (MVP choice,
 *    pending David — partial unique index pet_caretakers_one_active_pet_per_child).
 */
class FamilyService
{
    /**
     * The user's family, created on demand:
     *  - parent without a family → a new family (timezone = users.timezone);
     *  - child linked to a parent (deprecated users.parent_id) → the parent's family;
     *  - child without a parent → a family of its own (legacy data only).
     */
    public function ensureFamilyFor(User $user): Family
    {
        $existing = $this->familyOf($user);
        if ($existing !== null) {
            return $existing;
        }

        if ($user->isChild() && $user->parent_id !== null) {
            $parent = User::find($user->parent_id);
            if ($parent !== null && ! $parent->isChild()) {
                $family = $this->ensureFamilyFor($parent);
                $this->addMember($family, $user, FamilyRole::Child);

                return $family;
            }
        }

        $family = Family::create(['timezone' => $user->timezone ?: Family::DEFAULT_TIMEZONE]);
        $this->addMember($family, $user, $user->isChild() ? FamilyRole::Child : FamilyRole::Parent);

        return $family;
    }

    /**
     * Fresh lookup (not the cached relation).
     */
    public function familyOf(User $user): ?Family
    {
        $familyId = FamilyMember::where('user_id', $user->id)->value('family_id');

        return $familyId !== null ? Family::find($familyId) : null;
    }

    /**
     * @throws FamilyException when the user already belongs to another family
     */
    public function addMember(Family $family, User $user, FamilyRole $role): FamilyMember
    {
        $member = FamilyMember::where('user_id', $user->id)->first();

        if ($member !== null) {
            if ($member->family_id !== $family->id) {
                throw new FamilyException('other_family', 'This profile already belongs to another family.');
            }

            return $member;
        }

        $member = FamilyMember::create([
            'family_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);
        $user->unsetRelation('family');

        if ($role === FamilyRole::Parent) {
            $this->ensureDefaultQuietHours($family, $user);
        }

        return $member;
    }

    /**
     * Every family has quiet hours (fix/quiet-hours-default, 2026-10-08):
     * the first parent who joins a family without a row creates
     * QuietHours::DEFAULTS (night 21:00–07:00, active). Insert only — an
     * existing family row (or a legacy row of this parent, parent_id is
     * unique) is never touched. ON CONFLICT DO NOTHING keeps a concurrent
     * insert from aborting the caller's transaction. A family without any
     * parent has no row and reads the same defaults (Pet::quietHours()).
     */
    public function ensureDefaultQuietHours(Family $family, User $parent): void
    {
        if (QuietHours::where('family_id', $family->id)->orWhere('parent_id', $parent->id)->exists()) {
            return;
        }

        $now = now();
        DB::table('quiet_hours')->insertOrIgnore(array_merge(QuietHours::DEFAULTS, [
            'parent_id' => $parent->id,
            'family_id' => $family->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    /**
     * Make $child a caretaker of $pet.
     *
     * @param  bool  $requiresContract  false only for legacy/grandfathered rows
     *
     * @throws FamilyException not a child / not in the pet's family / already
     *                         caring for another active pet
     */
    public function addCaretaker(Pet $pet, User $child, bool $requiresContract = true): PetCaretaker
    {
        if (! $child->isChild()) {
            throw new FamilyException('not_a_child', 'Only a child profile can care for a pet.');
        }

        $existing = PetCaretaker::where('pet_id', $pet->id)->where('user_id', $child->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        if (FamilyMember::where('user_id', $child->id)->value('family_id') !== $pet->family_id) {
            throw new FamilyException('other_family', 'The child is not a member of this pet\'s family.');
        }

        if ($pet->is_active && $this->activePetOf($child) !== null) {
            throw new FamilyException('already_has_pet', 'This child already cares for an active pet.');
        }

        try {
            return PetCaretaker::create([
                'pet_id' => $pet->id,
                'user_id' => $child->id,
                'requires_contract' => $requiresContract,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent pairing raced past the check above.
            throw new FamilyException('already_has_pet', 'This child already cares for an active pet.');
        }
    }

    /**
     * The active pet a child cares for, if any (MVP: at most one).
     */
    public function activePetOf(User $child): ?Pet
    {
        return Pet::query()
            ->whereIn('id', PetCaretaker::where('user_id', $child->id)->whereNull('ended_at')->select('pet_id'))
            ->where('is_active', true)
            ->first();
    }

    /**
     * Everyone who must hear about a parent-level event of this pet
     * (phase-3 alarm, illness, game over): all parents of the pet's family.
     * Single resolution point for push / e-mail once they exist (M3).
     *
     * @return Collection<int, User>
     */
    public function parentRecipients(Pet $pet): Collection
    {
        return User::query()
            ->whereIn('id', FamilyMember::where('family_id', $pet->family_id)
                ->where('role', FamilyRole::Parent->value)
                ->select('user_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Children who must hear about a child-level event (phase 1 / 2
     * reminders): every caretaker of the pet.
     *
     * @return Collection<int, User>
     */
    public function caretakerRecipients(Pet $pet): Collection
    {
        return User::query()
            ->whereIn('id', PetCaretaker::where('pet_id', $pet->id)->active()->select('user_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Change the family timezone. users.timezone of every parent is kept in
     * sync (deprecated mirror, read by old code paths).
     */
    public function updateTimezone(Family $family, string $timezone): Family
    {
        $family->forceFill(['timezone' => $timezone])->save();

        User::query()
            ->whereIn('id', FamilyMember::where('family_id', $family->id)
                ->where('role', FamilyRole::Parent->value)
                ->select('user_id'))
            ->update(['timezone' => $timezone]);

        return $family;
    }
}
