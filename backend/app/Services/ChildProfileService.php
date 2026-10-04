<?php

namespace App\Services;

use App\Enums\FamilyRole;
use App\Enums\UserRole;
use App\Exceptions\FamilyException;
use App\Models\ChildLoginPin;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
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

    public function __construct(private readonly FamilyService $families) {}

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

    /**
     * Sign the child out on every device: delete all their tokens and revoke
     * their open login PINs.
     *
     * @return array{tokens: int, pins: int}
     */
    public function revokeDevices(User $child): array
    {
        return DB::transaction(function () use ($child): array {
            $tokens = $child->tokens()->delete();
            $pins = ChildLoginPin::open()
                ->where('child_user_id', $child->id)
                ->update(['revoked_at' => now()]);

            return ['tokens' => $tokens, 'pins' => $pins];
        });
    }

    /**
     * Keep the child's newest MAX_DEVICES tokens, delete the rest.
     */
    public function pruneDevices(User $child): int
    {
        $keep = $child->tokens()->orderByDesc('id')->limit(self::MAX_DEVICES)->pluck('id');

        return $child->tokens()->whereNotIn('id', $keep)->delete();
    }

    public function devicesCount(User $child): int
    {
        return $child->tokens()->count();
    }
}
