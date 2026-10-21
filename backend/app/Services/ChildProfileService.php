<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Enums\StepUnit;
use App\Enums\UserRole;
use App\Events\PetUpdated;
use App\Exceptions\FamilyException;
use App\Models\ChildLoginPin;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Pet;
use App\Models\PetDailyStep;
use App\Models\User;
use App\Services\Push\PushDeviceService;
use Illuminate\Support\Facades\DB;

/**
 * Child profiles without e-mail / password (M2-02, David 2026-10-02:
 * "Otrok se prijavi samo s PIN-om").
 *
 * A parent creates the profile with a nickname and an optional birth year —
 * nothing else about the child is collected (minor, GDPR art. 8). The child
 * signs in only with a one-time PIN the parent generates for this profile
 * (ChildPinLoginService); every device gets its own Sanctum token with the
 * `child` ability, at most MAX_DEVICES per child.
 */
class ChildProfileService
{
    /**
     * Child profiles per family (abuse guard, Claude 2026-10-04).
     */
    public const MAX_CHILDREN_PER_FAMILY = 10;

    /**
     * Signed-in devices per child (Claude 2026-10-04): a new PIN login beyond
     * this revokes the child's least recently created token(s).
     */
    public const MAX_DEVICES = 3;

    /**
     * Token name for a child device when the client's name isn't safe.
     */
    public const DEFAULT_DEVICE_LABEL = 'child-device';

    public function __construct(private readonly FamilyService $families) {}

    /**
     * The token name stored for a child's device (Claude 2026-10-04, PR #16
     * review). A device name can carry the child's real name ("Maja Novak's
     * iPhone"); only a short model-like label is kept, anything else becomes
     * DEFAULT_DEVICE_LABEL. The app sends the model only ("iPhone",
     * "Samsung SM-A515F").
     */
    public static function deviceLabel(?string $deviceName): string
    {
        $name = trim((string) $deviceName);

        return preg_match('/^[A-Za-z0-9 ._()-]{1,40}$/', $name) === 1 ? $name : self::DEFAULT_DEVICE_LABEL;
    }

    /**
     * @throws FamilyException too_many_children (422), not_a_parent (403)
     */
    public function createChild(User $parent, string $displayName, ?int $birthYear): User
    {
        if (! $parent->isParent()) {
            throw new FamilyException('not_a_parent', 'Only a parent can add a child.', 403);
        }

        return DB::transaction(function () use ($parent, $displayName, $birthYear): User {
            // Lock order (backend/CLAUDE.md): parent user row → family row.
            User::whereKey($parent->id)->lockForUpdate()->first();
            $family = $this->families->ensureFamilyFor($parent);
            Family::whereKey($family->id)->lockForUpdate()->first();

            $count = FamilyMember::where('family_id', $family->id)
                ->where('role', FamilyRole::Child->value)
                ->count();
            if ($count >= self::MAX_CHILDREN_PER_FAMILY) {
                throw new FamilyException('too_many_children', 'This family already has the maximum number of children.');
            }

            $child = new User;
            $child->forceFill([
                'name' => $displayName,
                'birth_year' => $birthYear,
                'role' => UserRole::Child->value,
                'email' => null,
                'password' => null,
                // Deprecated mirror (M2-01b): the parent who created the profile.
                'parent_id' => $parent->id,
            ]);
            // Quietly: membership is written explicitly below (the legacy
            // User::created bridge hook would do the same).
            $child->saveQuietly();

            $this->families->addMember($family, $child, FamilyRole::Child);

            return $child;
        });
    }

    /**
     * A child profile of the parent's family, or null (another family's child
     * is indistinguishable from a missing one). Lookup only — never creates a
     * family.
     */
    public function childOfParentFamily(User $parent, mixed $childId): ?User
    {
        if (! $parent->isParent() || ! is_scalar($childId) || ! ctype_digit((string) $childId)) {
            return null;
        }

        $family = $this->families->familyOf($parent);
        if ($family === null) {
            return null;
        }

        $isChild = FamilyMember::where('family_id', $family->id)
            ->where('user_id', (int) $childId)
            ->where('role', FamilyRole::Child->value)
            ->exists();

        return $isChild ? User::find((int) $childId) : null;
    }

    /** M5-T12: `PetUpdated` event type when a parent changes a caretaker's settings (adapted mode). */
    public const SETTINGS_EVENT = 'caretaker_settings_changed';

    /**
     * Switch the child's adapted mode (M5-T12, »Prilagojen način«): their walk counts
     * wheelchair pushes instead of steps (1 push = 1 step; goals, energy, routines and
     * the Care Score are unchanged). Only the boolean is stored — never a reason.
     * A change broadcasts one `PetUpdated` (`caretaker_settings_changed`, no child data in
     * the payload) on the child's active pet after commit, so the child's app refetches
     * its state and switches the wording / health data type; the same value again
     * changes nothing.
     *
     * Switching OFF withdraws the parent's consent (GDPR Art. 9, David 2026-10-21): in
     * the same transaction every `pet_daily_steps` row of the child with `unit = pushes`
     * becomes `steps` (counts unchanged — 1 push = 1 step — so energy, routines, points
     * and the Care Score do not move). That unit is the only stored trace of the mode
     * besides the flag: the report's `walk_unit` and the export's `unit` are derived from
     * it. Parent-logged walk minutes (`manual_minutes`, `parent_walk_entries`) are walks
     * and stay. Lock order child → pet rows (as PetActivityService::logParentWalk), so a
     * step sync or walk entry running at the same time either finishes first (its row is
     * converted here) or sees the switch off.
     */
    public function setAdaptedMode(User $child, bool $on): User
    {
        $changed = DB::transaction(function () use ($child, $on): bool {
            $locked = User::whereKey($child->id)->lockForUpdate()->firstOrFail();
            if ((bool) $locked->adapted_mode === $on) {
                return false;
            }
            $locked->forceFill(['adapted_mode' => $on])->save();

            if (! $on) {
                $this->forgetPushes($locked);
            }

            $pet = $this->families->activePetOf($locked);
            if ($pet !== null) {
                PetUpdated::afterCommit($pet, self::SETTINGS_EVENT);
            }

            return true;
        });

        return $changed ? $child->refresh() : $child;
    }

    /**
     * M5-T12 consent withdrawal: the child's push days become step days (caller holds
     * the child row lock). The pets of those rows (and the active pet) are locked first
     * in id order — step writers hold the pet lock.
     */
    private function forgetPushes(User $child): void
    {
        $petIds = PetDailyStep::where('user_id', $child->id)->where('unit', StepUnit::Pushes->value)
            ->distinct()->pluck('pet_id')
            ->push($this->families->activePetOf($child)?->id)
            ->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($petIds === []) {
            return;
        }
        Pet::whereIn('id', $petIds)->orderBy('id')->lockForUpdate()->pluck('id');

        PetDailyStep::where('user_id', $child->id)->where('unit', StepUnit::Pushes->value)
            ->update(['unit' => StepUnit::Steps->value]);
    }

    /**
     * Sign the child out on every device: delete all their tokens and push
     * devices and revoke their open login PINs.
     *
     * @return array{tokens: int, pins: int}
     */
    public function revokeDevices(User $child): array
    {
        return DB::transaction(function () use ($child): array {
            // Same child row lock as pin-login (parent → child → …): a PIN
            // login running concurrently either finishes first (its new token
            // is deleted here) or waits and then sees its PIN revoked.
            User::whereKey($child->id)->lockForUpdate()->first();

            $tokens = $child->tokens()->delete();
            // M3-02: the signed-out phones get no more pushes (rows tied to
            // a token cascade anyway; this also catches unlinked ones).
            app(PushDeviceService::class)->removeForUsers([$child->id]);
            $pins = ChildLoginPin::open()
                ->where('child_user_id', $child->id)
                ->update(['revoked_at' => now()]);

            return ['tokens' => $tokens, 'pins' => $pins];
        });
    }

    /**
     * Keep the child's newest MAX_DEVICES own-device tokens, delete the rest.
     *
     * Shared-phone sessions (»Igraj kot«, token name `shared:p{parentId}:…`) are
     * NOT counted and never pruned here (fix 2026-10-21): the app reuses that
     * token for weeks, so it was always the oldest and every new PIN login of
     * the child on another phone pushed it out — the parent's phone landed on
     * the start screen every day or two. They are capped separately: one per
     * (parent, child), replaced by ChildPinLoginService::deviceSession().
     */
    public function pruneDevices(User $child): int
    {
        $shared = addcslashes(ChildPinLoginService::SHARED_TOKEN_PREFIX, '\\%_').'%';
        $own = fn () => $child->tokens()->where('name', 'not like', $shared);
        $keep = $own()->orderByDesc('id')->limit(self::MAX_DEVICES)->pluck('id');

        return $own()->whereNotIn('id', $keep)->delete();
    }

    public function devicesCount(User $child): int
    {
        return $child->tokens()->count();
    }
}
