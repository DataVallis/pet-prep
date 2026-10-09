<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Enums\Species;
use App\Enums\UserRole;
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
use App\Models\PetContract;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\Push\PushDeviceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Account deletion (M2-08 — App Store "in-app account deletion", GDPR art. 17).
 * Immediate and irreversible (no grace period in the MVP; Claude, waiting for
 * David). Three entry points, one set of rules:
 *
 *  - deleteParentAccount(): the parent's own account. The LAST parent of a
 *    family takes the whole family with them (children, pets, media files,
 *    contracts incl. signatures, logs, tokens, push devices, PINs, invites, quiet hours,
 *    routines) — plus orphaned legacy child accounts that point at one of its
 *    parents (users.parent_id) but belong to no family. If another parent
 *    remains, only this parent goes (user, tokens, invites); the deprecated
 *    mirrors that point at them are handed to the remaining parent first,
 *    because `users.parent_id`, `pets.user_id` and `quiet_hours.parent_id`
 *    CASCADE on delete. Legacy pets the parent owns in ANOTHER family are
 *    deleted (with ledger detach + files), never left to the cascade.
 *  - deleteChildProfile(): one child. Pets the child cared for alone are
 *    deleted with all their data; a shared pet stays with the other
 *    caretakers — the child's caretaker row becomes a TOMBSTONE (user_id
 *    null, started_at / ended_at kept, PR #29) so the remaining children's
 *    past fair-share Care Score is unchanged; the child's activities keep
 *    counting for the pet with the actor nulled (FK `ON DELETE SET NULL`);
 *    the child's own contract (signature) and per-child step rows go.
 *  - deleteFamily(): Filament (superadmin), same purge as the last parent.
 *
 * Every deletion runs in one transaction (retried up to TRANSACTION_ATTEMPTS
 * times on a deadlock / serialization failure) that first locks ALL parent
 * rows of the family in id order, then all child rows in id order, then the
 * family row, then the pet rows — the family lock order. Files on the
 * `pet_media` disk are removed by a queued job dispatched AFTER commit
 * (DeletePetMediaFiles). `ai_spend_ledger` rows stay for accounting (pet refs
 * nulled first, AiSpendLedger::detachPets). One audit line per deletion,
 * without personal data (family id + counts).
 *
 * Password confirmation is throttled per scope (account / child) and counts
 * only FAILED attempts: PASSWORD_ATTEMPTS per PASSWORD_DECAY_SECONDS per user.
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

    /** DB::transaction attempts (deadlock / serialization failure → retry). */
    public const TRANSACTION_ATTEMPTS = 3;

    /** Failed password confirmations per user and scope … */
    public const PASSWORD_ATTEMPTS = 5;

    /** … within this many seconds. */
    public const PASSWORD_DECAY_SECONDS = 900;

    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_CHILD = 'child';

    /**
     * Check the parent's password before an irreversible deletion. Only wrong
     * passwords count against the limit (a success clears it).
     *
     * @throws AccountDeletionException invalid_password (422), too_many_attempts (429)
     */
    public function confirmPassword(User $user, string $password, string $scope): void
    {
        $key = "deletion-password:{$scope}:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_ATTEMPTS)) {
            throw new AccountDeletionException(
                'too_many_attempts',
                'Too many wrong passwords. Please try again later.',
                429,
                RateLimiter::availableIn($key),
            );
        }

        // A parent without a password (admin-created / legacy) cannot confirm —
        // same answer as a wrong password.
        if ($user->password === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::PASSWORD_DECAY_SECONDS);

            throw new AccountDeletionException('invalid_password', 'The password is not correct.', 422);
        }

        RateLimiter::clear($key);
    }

    /**
     * Delete the parent's own account (and the family, if they are its last parent).
     *
     * @return array{scope: 'family'|'parent', family_deleted: bool, parents_deleted: int, children_deleted: int, pets_deleted: int}
     *
     * `$acknowledgePaidChallenge` (M3-11 P5): required when a deleted pet has
     * a paid, unfinished challenge (the purchase stays used).
     *
     * @throws AccountDeletionException not_a_parent (403), superadmin_protected (403),
     *                                  paid_challenge_ack_required (422)
     */
    public function deleteParentAccount(User $parent, bool $acknowledgePaidChallenge = false): array
    {
        if (! $parent->isParent()) {
            throw new AccountDeletionException('not_a_parent', 'Only a parent account can be deleted here.', 403);
        }
        $this->assertNotSuperadmin($parent);

        return DB::transaction(function () use ($parent, $acknowledgePaidChallenge): array {
            $familyId = FamilyMember::where('user_id', $parent->id)->value('family_id');

            if ($familyId === null) {
                // A parent without a family (legacy): only the account (+ the
                // legacy pets it still owns).
                $locked = User::whereKey($parent->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return $this->summary('parent', false, 0, 0, 0); // already gone (idempotent)
                }
                $this->assertNotSuperadmin($locked);
                $legacyPets = $this->legacyPetIdsOf([$parent->id])->all();
                $this->assertPaidChallengeAcknowledged($legacyPets, $acknowledgePaidChallenge);
                $this->detachLegacyMirrors([$parent->id]);
                $pets = $this->deletePets($legacyPets);
                $this->deleteUsers([$parent->id]);
                $this->audit('parent_deleted', null, self::BY_SELF, 1, 0, $pets);

                return $this->summary('parent', false, 1, 0, $pets);
            }

            $members = $this->lockFamily((int) $familyId);
            if (! in_array($parent->id, $members['parents'], true)) {
                // Moved or deleted between the read and the locks.
                throw new AccountDeletionException('conflict', 'The family changed meanwhile. Please try again.', 409);
            }
            $this->assertNotSuperadmin(User::findOrFail($parent->id));

            // Decided under the family lock: a concurrent deletion by the other
            // parent is serialised here, so exactly one of them is "the last".
            $others = array_values(array_diff($members['parents'], [$parent->id]));

            if ($others === []) {
                $this->assertPaidChallengeAcknowledged(Pet::where('family_id', $familyId)->pluck('id')->all(), $acknowledgePaidChallenge);
                $result = $this->purgeFamily((int) $familyId);
                $this->audit('family_deleted', (int) $familyId, self::BY_SELF, $result['parents'], $result['children'], $result['pets']);

                return $this->summary('family', true, $result['parents'], $result['children'], $result['pets']);
            }

            $pets = $this->removeParent($parent->id, (int) $familyId, $others[0]);
            $this->audit('parent_removed', (int) $familyId, self::BY_SELF, 1, 0, $pets);

            return $this->summary('parent', false, 1, 0, $pets);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * A parent deletes one child profile of their family.
     *
     * @return array{child_id: int, pets_deleted: int, pets_kept: int}
     *
     * @throws AccountDeletionException child_not_found (404), not_a_parent (403),
     *                                  paid_challenge_ack_required (422, M3-11 P5)
     */
    public function deleteChildProfile(User $parent, User $child, bool $acknowledgePaidChallenge = false): array
    {
        if (! $parent->isParent()) {
            throw new AccountDeletionException('not_a_parent', 'Only a parent can delete a child profile.', 403);
        }

        return DB::transaction(function () use ($parent, $child, $acknowledgePaidChallenge): array {
            $notFound = fn () => new AccountDeletionException('child_not_found', 'No such child in your family.', 404);

            $familyId = FamilyMember::where('user_id', $child->id)
                ->where('role', FamilyRole::Child->value)
                ->value('family_id');
            if ($familyId === null) {
                throw $notFound();
            }

            // Re-checked under the locks: still a child of the parent's family.
            $members = $this->lockFamily((int) $familyId);
            if (! in_array($parent->id, $members['parents'], true) || ! in_array($child->id, $members['children'], true)) {
                throw $notFound();
            }

            $petIds = PetCaretaker::where('user_id', $child->id)->pluck('pet_id')
                ->merge(Pet::where('user_id', $child->id)->pluck('id'))
                ->unique()
                ->values();
            $pets = Pet::whereIn('id', $petIds)->orderBy('id')->lockForUpdate()->get();

            $toDelete = [];
            $kept = [];
            foreach ($pets as $pet) {
                $others = PetCaretaker::where('pet_id', $pet->id)
                    ->active()
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
                $this->tombstoneCaretaker($pet, $child);
                $kept[] = $pet;
            }

            // M3-11 P5: checked before anything is written (the transaction
            // rolls the shared-pet changes above back on a refusal).
            $this->assertPaidChallengeAcknowledged($toDelete, $acknowledgePaidChallenge);
            $deleted = $this->deletePets($toDelete);
            ChildLoginPin::where('child_user_id', $child->id)->delete();
            FamilyMember::where('user_id', $child->id)->delete();
            // Contract and per-child step rows cascade with the user;
            // activities_log / pet_daily_routines keep their rows with
            // actor_user_id nulled.
            $this->deleteUsers([$child->id]);

            // The remaining caretakers and parents see the pet without this child.
            foreach ($kept as $pet) {
                PetUpdated::afterCommit(Pet::find($pet->id) ?? $pet, 'caretaker_removed');
            }

            $this->audit('child_deleted', (int) $familyId, self::BY_PARENT, 0, 1, $deleted);

            return ['child_id' => $child->id, 'pets_deleted' => $deleted, 'pets_kept' => count($kept)];
        }, self::TRANSACTION_ATTEMPTS);
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
            $members = $this->lockFamily($family->id);
            if (! Family::whereKey($family->id)->exists()) {
                return ['parents' => 0, 'children' => 0, 'pets' => 0]; // already gone
            }

            foreach (User::whereIn('id', array_merge($members['parents'], $members['children']))->get() as $user) {
                $this->assertNotSuperadmin($user);
            }

            $result = $this->purgeFamily($family->id);
            $this->audit('family_deleted', $family->id, self::BY_ADMIN, $result['parents'], $result['children'], $result['pets']);

            return $result;
        }, self::TRANSACTION_ATTEMPTS);
    }

    // ──────────────────────────────────────────────────────────────
    //  Internals (callers hold the locks inside one transaction)
    // ──────────────────────────────────────────────────────────────

    /**
     * M3-11 P5 (David 2026-10-07): deleting a pet with a paid, unfinished
     * challenge throws the purchase away (it stays used, no refund to the
     * family) — the parent must say so explicitly. The body lists those pets
     * (id + breed; no child data). Admin (Filament) deletions are not gated.
     *
     * @param  list<int>|array<int, int|string>  $petIds
     *
     * @throws AccountDeletionException paid_challenge_ack_required (422)
     */
    private function assertPaidChallengeAcknowledged(array $petIds, bool $acknowledged): void
    {
        if ($acknowledged || $petIds === []) {
            return;
        }

        $losingPets = Pet::whereIn('id', $petIds)->orderBy('id')->get()
            ->filter(fn (Pet $pet): bool => $pet->deletionLosesPurchase());
        $losing = $losingPets
            ->map(fn (Pet $pet): array => ['pet_id' => $pet->id, 'breed_type' => $pet->breed_type->value, 'name' => $pet->name])
            ->values()
            ->all();

        if ($losing !== []) {
            // M5-R06-06: names the animal; "pet" once a cat is among them (dog wording unchanged).
            $noun = $losingPets->every(fn (Pet $pet): bool => $pet->speciesValue() === Species::Dog) ? 'dog' : 'pet';
            throw new AccountDeletionException(
                'paid_challenge_ack_required',
                'This deletes a '.$noun.' whose paid 12-week challenge is not finished. The purchase stays used. Send acknowledge_paid_challenge: true to continue.',
                422,
                extra: ['pets' => $losing],
            );
        }
    }

    /**
     * The family lock order: every parent row (id order), every child row (id
     * order), the family row. Returns the members as read AFTER the locks.
     *
     * @return array{parents: list<int>, children: list<int>}
     */
    private function lockFamily(int $familyId): array
    {
        $ids = fn (FamilyRole $role): array => FamilyMember::where('family_id', $familyId)
            ->where('role', $role->value)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        User::whereIn('id', $ids(FamilyRole::Parent))->orderBy('id')->lockForUpdate()->get(['id']);
        User::whereIn('id', $ids(FamilyRole::Child))->orderBy('id')->lockForUpdate()->get(['id']);
        Family::whereKey($familyId)->lockForUpdate()->first(['id']);

        return ['parents' => $ids(FamilyRole::Parent), 'children' => $ids(FamilyRole::Child)];
    }

    /**
     * Everything of one family. Order respects the RESTRICT foreign keys
     * (pets / family_user / quiet_hours → families).
     *
     * @return array{parents: int, children: int, pets: int}
     */
    private function purgeFamily(int $familyId): array
    {
        $members = FamilyMember::where('family_id', $familyId)->get(['user_id', 'role']);
        $parentIds = $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Parent)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        // Orphaned legacy child accounts (PR #29 m3): a child whose deprecated
        // parent_id points at one of the parents but who belongs to no family.
        // Nobody else can ever manage them — they go with the family.
        $orphans = $parentIds === [] ? [] : User::query()
            ->where('role', UserRole::Child->value)
            ->whereIn('parent_id', $parentIds)
            ->whereNotIn('id', FamilyMember::query()->select('user_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $userIds = array_values(array_unique(array_merge($members->pluck('user_id')->map(fn ($id) => (int) $id)->all(), $orphans)));

        // The family's pets, plus legacy pets whose deprecated owner is one of
        // the users (pets.user_id cascades — delete them here, with their
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
            'parents' => count($parentIds),
            'children' => $members->filter(fn (FamilyMember $m) => $m->role === FamilyRole::Child)->count() + count($orphans),
            'pets' => $pets,
        ];
    }

    /**
     * Only this parent leaves; the family, its children and pets stay with
     * $heirId. Returns the number of pets deleted (legacy pets the parent owns
     * in another family).
     */
    private function removeParent(int $parentId, int $familyId, int $heirId): int
    {
        // Quiet hours (one row per family; quiet_hours.parent_id is unique and
        // cascades): the family's row passes to the remaining parent.
        QuietHours::where('parent_id', $parentId)->whereNull('family_id')->delete();
        if (QuietHours::where('parent_id', $parentId)->where('family_id', $familyId)->exists()) {
            QuietHours::where('parent_id', $heirId)->whereNull('family_id')->delete();
            QuietHours::where('parent_id', $parentId)->where('family_id', $familyId)->update(['parent_id' => $heirId]);
        }

        // Deprecated mirrors that would cascade: the family's children and
        // orphaned legacy children (users.parent_id) go to the remaining
        // parent; children of other families are detached.
        $familyUserIds = FamilyMember::where('family_id', $familyId)->pluck('user_id');
        User::where('parent_id', $parentId)->whereIn('id', $familyUserIds)->update(['parent_id' => $heirId]);
        User::where('parent_id', $parentId)
            ->where('role', UserRole::Child->value)
            ->whereNotIn('id', FamilyMember::query()->select('user_id'))
            ->update(['parent_id' => $heirId]);
        $this->detachLegacyMirrors([$parentId]);

        // Legacy pets owned by the parent (pets.user_id cascades): the
        // family's go to the remaining parent; pets of ANY other family are
        // deleted here (ledger detach + files after commit), never by the FK.
        Pet::where('user_id', $parentId)->where('family_id', $familyId)->update(['user_id' => $heirId]);
        $foreign = Pet::where('user_id', $parentId)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $pets = $this->deletePets($foreign);

        // Their open invite codes go (they cascade anyway); PINs they issued
        // stay valid for the family (child_login_pins.created_by → null).
        FamilyInvite::where('created_by', $parentId)->delete();
        FamilyMember::where('user_id', $parentId)->delete();
        $this->deleteUsers([$parentId]);

        return $pets;
    }

    /**
     * End the child's caretaker row on a pet that stays (PR #29): keep it as
     * a tombstone with the moment the child started caring, so fair-share
     * scores of the remaining caretakers don't change.
     */
    private function tombstoneCaretaker(Pet $pet, User $child): void
    {
        $row = PetCaretaker::where('pet_id', $pet->id)->where('user_id', $child->id)->first();
        if ($row === null) {
            return; // deprecated pets.user_id only, no caretaker row
        }

        $start = null;
        if (! $pet->isUnborn()) {
            $bornAt = CarbonImmutable::instance($pet->born_at)->utc();
            if ($row->started_at !== null) {
                $start = CarbonImmutable::instance($row->started_at)->utc();
            } elseif (! $row->requires_contract) {
                $start = $bornAt;
            } else {
                $signedAt = PetContract::where('pet_id', $pet->id)->where('user_id', $child->id)->value('signed_at');
                $start = $signedAt !== null ? CarbonImmutable::parse($signedAt, 'UTC') : null;
            }
            if ($start !== null && $start->lessThan($bornAt)) {
                $start = $bornAt;
            }
        }

        PetCaretaker::whereKey($row->id)->update([
            'user_id' => null,
            'started_at' => $start,
            'ended_at' => now(),
        ]);
    }

    /**
     * Users outside the purge whose deprecated parent_id points at a deleted
     * user are detached (parent_id → null) instead of cascading with them.
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
     * events, PINs, media slots; users.pairing_pet_id → null). Spend rows are
     * detached first. Files go after commit.
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
     * Delete user rows with their push devices, tokens, web sessions and
     * reset tokens.
     *
     * @param  list<int>  $userIds
     */
    private function deleteUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        // M3-02: explicit (the FKs cascade too) — no push may reach a phone
        // of a deleted account, even from a send job already queued.
        app(PushDeviceService::class)->removeForUsers($userIds);
        PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();

        $emails = User::whereIn('id', $userIds)->whereNotNull('email')->pluck('email')->all();
        if ($emails !== []) {
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
        }

        User::whereIn('id', $userIds)->delete();
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
