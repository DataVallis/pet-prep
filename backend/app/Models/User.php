<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\FamilyService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Family timezone when none is set (M1-03). Matches the column default.
     */
    public const DEFAULT_TIMEZONE = 'Europe/Ljubljana';

    /**
     * Family sync for the deprecated columns (M2-01, ADR-012). Until the
     * mobile app stops relying on users.parent_id / users.timezone /
     * pets.user_id, code that writes only the old columns (seeders, admin,
     * old tests) still lands in the family model:
     *  - a new parent gets a family; a child linked via parent_id joins the
     *    parent's family;
     *  - a parent's users.timezone change is copied to the family.
     * New code writes the family directly (FamilyService).
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->isParent() || ($user->isChild() && $user->parent_id !== null)) {
                app(FamilyService::class)->ensureFamilyFor($user);
            }
        });

        static::updated(function (User $user): void {
            if ($user->isChild() && $user->parent_id !== null && $user->wasChanged('parent_id')) {
                app(FamilyService::class)->ensureFamilyFor($user);
            }

            if ($user->isParent() && $user->wasChanged('timezone') && $user->timezone) {
                $familyId = FamilyMember::where('user_id', $user->id)->value('family_id');
                if ($familyId !== null) {
                    Family::whereKey($familyId)->update(['timezone' => $user->timezone]);
                    if ($user->relationLoaded('family') && $user->family !== null) {
                        $user->family->setRawAttributes(
                            array_merge($user->family->getAttributes(), ['timezone' => $user->timezone]),
                            true,
                        );
                    }
                }
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'timezone',
        'is_superadmin',
        'parent_id',
        'pairing_pin',
        'pin_expires_at',
        'pairing_pet_id',
        'revenuecat_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pairing_pin',
        'revenuecat_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_superadmin' => 'boolean',
            'pin_expires_at' => 'datetime',
            'birth_year' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

    /**
     * The family this user belongs to (M2-01; at most one).
     */
    public function family(): HasOneThrough
    {
        return $this->hasOneThrough(Family::class, FamilyMember::class, 'user_id', 'id', 'id', 'family_id');
    }

    public function familyMembership(): HasOne
    {
        return $this->hasOne(FamilyMember::class);
    }

    /**
     * Pets this child cares for (pet_caretakers, M2-01). A shared pet has
     * several caretakers; a child has at most one active pet.
     */
    public function caredPets(): BelongsToMany
    {
        return $this->belongsToMany(Pet::class, 'pet_caretakers')
            ->withPivot(['requires_contract', 'pet_is_active'])
            ->withTimestamps();
    }

    /**
     * @deprecated M2-01 — use family(). The parent who paired this child
     * (users.parent_id), kept in sync for old app builds.
     *
     * The parent of this child user (null for parent profiles).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * @deprecated M2-01 — use family()->children(). Children whose
     * users.parent_id points to this parent (not the second parent's).
     */
    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * @deprecated M2-01 — use caredPets(). Pets whose primary caretaker
     * (pets.user_id) is this child.
     */
    public function pet(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    /**
     * The active pet for this child user.
     */
    public function activePet(): ?Pet
    {
        return $this->caredPets()->where('pets.is_active', true)->orderByDesc('pets.id')->first();
    }

    /**
     * The pet the child API works on (M1-07): the active pet, otherwise the
     * most recent one (a game-over pet is inactive but must still be shown
     * with its lock). Null when the child has never been paired.
     */
    public function currentPet(): ?Pet
    {
        return $this->caredPets()
            ->orderByDesc('pets.is_active')
            ->orderByDesc('pets.id')
            ->first();
    }

    /**
     * The quiet hours configuration defined by this parent.
     */
    public function quietHours(): HasOne
    {
        return $this->hasOne(QuietHours::class, 'parent_id');
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Canonical form of an e-mail address (M2-10a): trimmed, lower case.
     * New accounts store it; lookups compare against lower(email) so legacy
     * mixed-case rows still match.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The account with this e-mail address, case-insensitive. An exact
     * match wins should legacy data hold two case variants.
     */
    public static function findByEmail(string $email): ?self
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === '') {
            return null;
        }

        return self::query()
            ->whereRaw('lower(email) = ?', [$normalized])
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [$normalized])
            ->orderBy('id')
            ->first();
    }

    public static function emailTaken(string $email): bool
    {
        $normalized = self::normalizeEmail($email);

        return $normalized !== '' && self::query()->whereRaw('lower(email) = ?', [$normalized])->exists();
    }

    /**
     * The family's IANA timezone (families.timezone, M2-01; before that the
     * parent's users.timezone). Every wall-clock rule (quiet hours, local
     * midnight, dashboard days) is evaluated in it; timestamps are stored in
     * UTC. Falls back to the legacy columns for a user without a family.
     */
    public function familyTimezone(): string
    {
        if ($this->family?->timezone) {
            return $this->family->timezone;
        }

        if ($this->isChild() && $this->parent_id !== null) {
            return $this->parent?->timezone ?? self::DEFAULT_TIMEZONE;
        }

        return $this->timezone ?? self::DEFAULT_TIMEZONE;
    }

    /**
     * Determine if the user is a parent.
     */
    public function isParent(): bool
    {
        return $this->role === UserRole::Parent;
    }

    /**
     * Determine if the user is a child.
     */
    public function isChild(): bool
    {
        return $this->role === UserRole::Child;
    }

    /**
     * Determine if the user is a superadmin (Filament admin panel access).
     */
    public function isSuperadmin(): bool
    {
        return $this->is_superadmin === true;
    }

    /**
     * FilamentUser: Determine if the user can access the admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isSuperadmin();
    }

    /**
     * Check if the current pairing PIN is valid (not expired).
     */
    public function hasValidPairingPin(): bool
    {
        return $this->pairing_pin !== null
            && $this->pin_expires_at !== null
            && $this->pin_expires_at->isFuture();
    }
}
