<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Events\PetUpdated;
use App\Exceptions\AccountDeletionException;
use App\Jobs\DeletePetMediaFiles;
use App\Models\AiSpendLedger;
use App\Models\ChildLoginPin;
use App\Models\Family;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\QuietHours;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Account deletion (M2-08 — App Store "in-app account deletion", GDPR art. 17).
 * Immediate and irreversible (no grace period in the MVP; Claude, waiting for
 * David). Three entry points, one set of rules:
 *
 *  - deleteParentAccount(): the parent's own account. The LAST parent of a
 *    family takes the whole family with them (children, pets, media files,
 *    contracts incl. signatures, logs, tokens, PINs, invites, quiet hours,
 *    routines). If another parent remains, only this parent goes (user,
 *    tokens, invites); the deprecated mirrors that point at them are handed to
 *    the remaining parent first, because `users.parent_id`, `pets.user_id` and
 *    `quiet_hours.parent_id` CASCADE on delete.
 *  - deleteChildProfile(): one child. Pets the child cared for alone are
 *    deleted with all their data; a shared pet stays with the other
 *    caretakers — the child's activities keep counting for the pet, with the
 *    actor nulled (FK `ON DELETE SET NULL`), the child's own contract,
 *    caretaker row and per-child step rows go.
 *  - deleteFamily(): Filament (superadmin), same purge as the last parent.
 *
 * Every deletion runs in one transaction with row locks in the family lock
 * order (parent user rows → child user rows → family row → pet rows). Files on
 * the `pet_media` disk are removed by a queued job dispatched AFTER commit
 * (DeletePetMediaFiles). `ai_spend_ledger` rows stay for accounting — their
 * `pet_id` / `pet_media_id` are nulled by the FK. One audit line per deletion,
 * without personal data (family id + counts).
 *
 * Late work for deleted pets is harmless by design: queued media jobs find no
 * pet / slot and return; a late fal webhook matches the ledger row (slot gone)
 * → 200 "Superseded."; the decay tick, escalation and the routine ledger skip
 * ids whose row is gone.
 */
class AccountDeletionService
{
    public const BY_SELF = 'self';

    public const BY_PARENT = 'parent';

    public const BY_ADMIN = 'admin';

    /**
     * @throws AccountDeletionException invalid_password (422)
     */
    public function assertPassword(User $user, string $password): void
    {
        // A parent without a password (admin-created / legacy) cannot confirm —
        // same answer as a wrong password.
        if ($user->password === null || ! Hash::check($password, $user->password)) {
            throw new AccountDeletionException('invalid_password', 'The password is not correct.', 422);
        }
    }

    /**
     * Delete the parent's own account (and the family, if they are its last parent).
     *
     * @return array{scope: 'family'|'parent', family_deleted: bool, parents_deleted: int, children_deleted: int, pets_deleted: int}
     *
     * @throws AccountDeletionException not_a_parent (403), superadmin_protected (403)
     */
    public function deleteParentAccount(User $parent): array
    {
        if (! $parent->isParent()) {
            throw new AccountDeletionException('not_a_parent', 'Only a parent account can be deleted here.', 403);
        }
        $this->assertNotSuperadmin($parent);

        return DB::transaction(function () use ($parent): array {
            // Lock order: parent user row → child user rows → family row → pets.
            $locked = User::whereKey($parent->id)->lockForUpdate()->first();
            if ($locked === null) {
                return $this->summary('parent', false, 0, 0, 0); // already gone (idempotent)
            }
            $this->assertNotSuperadmin($locked);

            $familyId = FamilyMember::where('user_id', $parent->id)->value('family_id');
            if ($familyId === null) {
                // A parent without a family (legacy): only the account.
                $this->detachLegacyMirrors([$parent->id]);
                $pets = $this->deletePets($this->legacyPetIdsOf([$parent->id])->all());
                $this->deleteUsers([$parent->id]);
                $this->audit('parent_deleted', null, self::BY_SELF, 1, 0, $pets);

                return $this->summary('parent', false, 1, 0, $pets);
            }

            $this->lockChildrenOf($familyId);
            Family::whereKey($familyId)->lockForUpdate()->first();

            // Re-read under the family lock: a concurrent deletion by the other
            // parent is serialised here, so exactly one of them is "the last".
            $heir = FamilyMember::where('family_id', $familyId)
                ->where('role', FamilyRole::Parent->value)
                ->where('user_id', '!=', $parent->id)
                ->orderBy('user_id')
                ->value('user_id');

            if ($heir === null) {
                $result = $this->purgeFamily((int) $familyId);
                $this->audit('family_deleted', (int) $familyId, self::BY_SELF, $result['parents'], $result['children'], $result['pets']);

                return $this->summary('family', true, $result['parents'], $result['children'], $result['pets']);
            }

            $this->removeParent($parent->id, (int) $familyId, (int) $heir);
            $this->audit('parent_removed', (int) $familyId, self::BY_SELF, 1, 0, 0);

            return $this->summary('parent', false, 1, 0, 0);
        });
    }

    /**
     * A parent deletes one child profile of their family.
     *
     * @return array{child_id: int, pets_deleted: int, pets_kept: int}
     *
     * @throws AccountDeletionException child_not_found (404), not_a_parent (403)
     */
    public function deleteChildProfile(User $parent, User $child): array
    {
        if (! $parent->isParent()) {
            throw new AccountDeletionException('not_a_parent', 'Only a parent can delete a child profile.', 403);
        }

        return DB::transaction(function () use ($parent, $child): array {
            User::whereKey($parent->id)->lockForUpdate()->first();
            $locked = User::whereKey($child->id)->lockForUpdate()->first();

            // Re-checked under the locks: still a child of the parent's family.
            $familyId = FamilyMember::where('user_id', $child->id)
                ->where('role', FamilyRole::Child->value)
                ->value('family_id');
            $parentFamilyId = FamilyMember::where('user_id', $parent->id)
                ->where('role', FamilyRole::Parent->value)
                ->value('family_id');

            if ($locked === null || ! $locked->isChild() || $familyId === null || $familyId !== $parentFamilyId) {
                throw new AccountDeletionException('child_not_found', 'No such child in your family.', 404);
            }

            Family::whereKey($familyId)->lockForUpdate()->first();

            $petIds = PetCaretaker::where('user_id', $child->id)->pluck('pet_id')
                ->merge(Pet::where('user_id', $child->id)->pluck('id'))
                ->unique()
                ->values();
            $pets = Pet::whereIn('id', $petIds)->orderBy('id')->lockForUpdate()->get();

            $toDelete = [];
            $kept = [];
            foreach ($pets as $pet) {
                $others = PetCaretaker::where('pet_id', $pet->id)
                    ->where('user_id', '!=', $child->id)
                    ->orderBy('id')
                    ->pluck('user_id');

                if ($others->isEmpty()) {
                    $toDelete[] = $pet->id;

                    continue;
                }

                // Shared pet: it stays. The deprecated primary caretaker mirror
                // (pets.user_id, ON DELETE CASCADE) moves to the next caretaker,
                // otherwise deleting the child would take the pet with it.
                if ((int) $pet->user_id === $child->id) {
                    Pet::whereKey($pet->id)->update(['user_id' => (int) $others->first()]);
                }
                $kept[] = $pet;
            }

            $deleted = $this->deletePets($toDelete);
            ChildLoginPin::where('child_user_id', $child->id)->delete();
            FamilyMember::where('user_id', $child->id)->delete();
            // Contract, caretaker rows and per-child step rows cascade with the
            // user; activities_log / pet_daily_routines keep their rows with
            // actor_user_id nulled.
            $this->deleteUsers([$child->id]);

            // The remaining caretakers and parents see the pet without this child.
            foreach ($kept as $pet) {
                PetUpdated::afterCommit(Pet::find($pet->id) ?? $pet, 'caretaker_removed');
            }

            $this->audit('child_deleted', (int) $familyId, self::BY_PARENT, 0, 1, $deleted);

            return ['child_id' => $child->id, 'pets_deleted' => $deleted, 'pets_kept' => count($kept)];
        });
    }

    /**
     * Filament (superadmin): delete a whole family with every member and pet.
     *
     * @return array{parents: int, children: int, pets: int}
     *
     * @throws AccountDeletionException superadmin_protected (403)
     */
    public function deleteFamily(Family $family): array
    {
        return DB::transaction(function () use ($family): array {
            $parentIds = FamilyMember::where('family_id', $family->id)
                ->where('role', FamilyRole::Parent->value)
                ->orderBy('user_id')
                ->pluck('user_id');
            $parents = User::whereIn('id', $parentIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($parents as $parent) {
                $this->assertNotSuperadmin($parent);
            }

            $this->lockChildrenOf($family->id);
            if (Family::whereKey($family->id)->lockForUpdate()->first() === null) {
                return ['parents' => 0, 'children' => 0, 'pets' => 0]; // already gone
            }

            $result = $this->purgeFamily($family->id);
            $this->audit('family_deleted', $family->id, self::BY_ADMIN, $result['parents'], $result['children'], $result['pets']);

            return $result;
        });
    }

    // ──────────────────────────────────────────────────────────────
    //  Internals (callers hold the locks inside one transaction)
    // ──────────────────────────────────────────────────────────────

    /**
     * Everything of one family. Order respects the RESTRICT foreign keys
     * (pets / family_user / quiet_hours → families).
     *
     * @return array{parents: int, children: int, pets: int}
     */
    private function purgeFamily(int $familyId): array
    {
        $members = FamilyMember::where('family_id', $familyId)->get(['user_id', 'role']);
        $userIds = $members->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        // The family's pets, plus legacy pets whose deprecated owner is one of
        // the members (pets.user_id cascades — delete them here, with their
        // files, instead of silently through the FK).
        $petIds = Pet::where('family_id', $familyId)->pluck('id')
            ->merge($this->legacyPetIdsOf($userIds))
            ->unique()
            ->values();
        Pet::whereIn('id', $petIds)->orderBy('id')->lockForUpdate()->get(['id']);

        $this->detachLegacyMirrors($userIds);
        $pets = $this->deletePets($petIds->all());

        QuietHours::where('family_id', $familyId)->orWhereIn('parent_id', $userIds)->delete();
        ChildLoginPin::where('family_id', $familyId)->delete();
        FamilyInvite::where('family_id', $familyId)->delete();
        FamilyMember::where('family_id', $familyId)->delete();
        $this->deleteUsers($userIds);
        Family::whereKey($familyId)->delete();

        return [
            'parents' => $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Parent)->count(),
            'children' => $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Child)->count(),
            'pets' => $pets,
        ];
    }

    /**
     * Only this parent leaves; the family, its children and pets stay with $heirId.
     */
    private function removeParent(int $parentId, int $familyId, int $heirId): void
    {
        // Quiet hours (one row per family; quiet_hours.parent_id is unique and
        // cascades): the family's row passes to the remaining parent.
        QuietHours::where('parent_id', $parentId)->whereNull('family_id')->delete();
        if (QuietHours::where('parent_id', $parentId)->where('family_id', $familyId)->exists()) {
            QuietHours::where('parent_id', $heirId)->whereNull('family_id')->delete();
            QuietHours::where('parent_id', $parentId)->where('family_id', $familyId)->update(['parent_id' => $heirId]);
        }

        // Deprecated mirrors that would cascade: the family's children
        // (users.parent_id) and legacy parent-owned pets (pets.user_id).
        $familyUserIds = FamilyMember::where('family_id', $familyId)->pluck('user_id');
        User::where('parent_id', $parentId)->whereIn('id', $familyUserIds)->update(['parent_id' => $heirId]);
        Pet::where('user_id', $parentId)->where('family_id', $familyId)->update(['user_id' => $heirId]);
        $this->detachLegacyMirrors([$parentId]);

        // Their open invite codes go (they cascade anyway); PINs they issued
        // stay valid for the family (child_login_pins.created_by → null).
        FamilyInvite::where('created_by', $parentId)->delete();
        FamilyMember::where('user_id', $parentId)->delete();
        $this->deleteUsers([$parentId]);
    }

    /**
     * Rows outside the purge that still point at these users through a
     * cascading deprecated mirror are detached instead of deleted with them
     * (legacy data only: a child whose parent_id is in another family).
     *
     * @param  list<int>  $userIds
     */
    private function detachLegacyMirrors(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        User::whereIn('parent_id', $userIds)->whereNotIn('id', $userIds)->update(['parent_id' => null]);
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, int>
     */
    private function legacyPetIdsOf(array $userIds): Collection
    {
        return $userIds === [] ? collect() : Pet::whereIn('user_id', $userIds)->pluck('id');
    }

    /**
     * Delete pets with every row that hangs off them (FK cascades: activities,
     * contracts, caretakers, steps, walks, routines, status periods, hygiene
     * events, PINs, media slots; ledger / users.pairing_pet_id → null). Files go
     * after commit.
     *
     * @param  array<int, int|string>  $petIds
     */
    private function deletePets(array $petIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $petIds)));
        if ($ids === []) {
            return 0;
        }

        AiSpendLedger::detachPets($ids);

        // Query-builder delete: no model events (the Pet::deleted hook would
        // queue one job per pet — one job for all of them here).
        $deleted = Pet::whereIn('id', $ids)->delete();
        DeletePetMediaFiles::dispatch($ids)->afterCommit();

        return $deleted;
    }

    /**
     * Delete user rows with their tokens, web sessions and reset tokens.
     *
     * @param  list<int>  $userIds
     */
    private function deleteUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();

        $emails = User::whereIn('id', $userIds)->whereNotNull('email')->pluck('email')->all();
        if ($emails !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
        }

        User::whereIn('id', $userIds)->delete();
    }

    private function lockChildrenOf(int $familyId): void
    {
        $childIds = FamilyMember::where('family_id', $familyId)
            ->where('role', FamilyRole::Child->value)
            ->pluck('user_id');

        User::whereIn('id', $childIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertNotSuperadmin(User $user): void
    {
        if ($user->isSuperadmin()) {
            throw new AccountDeletionException('superadmin_protected', 'A superadmin account cannot be deleted here.', 403);
        }
    }

    /**
     * One log line per deletion — no names, e-mails or user ids, only the
     * family id (a pseudonymous number) and counts.
     */
    private function audit(string $event, ?int $familyId, string $by, int $parents, int $children, int $pets): void
    {
        Log::info('Account deletion: '.$event, [
            'event' => $event,
            'family_id' => $familyId,
            'initiated_by' => $by,
            'parents' => $parents,
            'children' => $children,
            'pets' => $pets,
            'at' => now()->utc()->toIso8601String(),
        ]);
    }

    /**
     * @param  'family'|'parent'  $scope
     * @return array{scope: 'family'|'parent', family_deleted: bool, parents_deleted: int, children_deleted: int, pets_deleted: int}
     */
    private function summary(string $scope, bool $familyDeleted, int $parents, int $children, int $pets): array
    {
        return [
            'scope' => $scope,
            'family_deleted' => $familyDeleted,
            'parents_deleted' => $parents,
            'children_deleted' => $children,
            'pets_deleted' => $pets,
        ];
    }
}
