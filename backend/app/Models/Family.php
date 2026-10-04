<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A family (M2-01, ADR-012): several parents, several children, one or more
 * pets. All parents see every child and pet of the family. The family
 * timezone drives every wall-clock rule (quiet hours, midnight, feed windows).
 * The billing unit is the pet, not the family.
 *
 * @property int $id
 * @property string $timezone
 */
class Family extends Model
{
    public const DEFAULT_TIMEZONE = 'Europe/Ljubljana';

    /**
     * @var list<string>
     */
    protected $fillable = ['timezone'];

    public function members(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_user')->withPivot('role')->withTimestamps();
    }

    public function parents(): BelongsToMany
    {
        return $this->users()->wherePivot('role', FamilyRole::Parent->value)->orderBy('users.id');
    }

    public function children(): BelongsToMany
    {
        return $this->users()->wherePivot('role', FamilyRole::Child->value)->orderBy('users.id');
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function quietHours(): HasOne
    {
        return $this->hasOne(QuietHours::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(FamilyInvite::class);
    }

    public function hasParent(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('role', FamilyRole::Parent->value)
            ->exists();
    }
}
