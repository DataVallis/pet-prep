<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_superadmin' => 'boolean',
            'pin_expires_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────

    /**
     * The parent of this child user (null for parent profiles).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * The children paired to this parent.
     */
    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * The pet belonging to this user (child).
     */
    public function pet(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    /**
     * The active pet for this child user.
     */
    public function activePet()
    {
        return $this->pet()->where('is_active', true)->first();
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
     * The family's IANA timezone: the parent's own, or — for a child — its
     * parent's. Every wall-clock rule (quiet hours, local midnight, dashboard
     * days) is evaluated in it; timestamps are stored in UTC.
     */
    public function familyTimezone(): string
    {
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
