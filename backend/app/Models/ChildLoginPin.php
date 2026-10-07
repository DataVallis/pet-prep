<?php

namespace App\Models;

use App\Enums\PetPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time login / pairing PIN for a specific child profile (M2-02).
 * The PIN itself is never stored, only `pin_hash` (HMAC-SHA256 with the app
 * key). Open = not consumed and not revoked; usable = open and not expired.
 *
 * @property int $id
 * @property int $family_id
 * @property int $child_user_id
 * @property int|null $created_by
 * @property int|null $pet_id
 * @property string $pin_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $revoked_at
 * @property array{breed?: string, origin?: string, age_stage?: string, features?: list<string>}|null $pet_options New pet's profile (M5-R01) + app features (M5-R02)
 * @property PetPlan|null $plan New pet's plan (M3-11); null = old app build → challenge
 */
class ChildLoginPin extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id',
        'child_user_id',
        'created_by',
        'pet_id',
        'pin_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
        'pet_options',
        // M3-11: plan of the new pet (null = old app build → challenge).
        'plan',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['pin_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'pet_options' => 'array',
            'plan' => PetPlan::class,
        ];
    }

    /**
     * @param  Builder<ChildLoginPin>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('consumed_at')->whereNull('revoked_at');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_user_id');
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
