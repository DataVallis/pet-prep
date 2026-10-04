<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child who cares for a pet (M2-01, ADR-012). A shared pet has several.
 *
 * `pet_is_active` is maintained by database triggers (a copy of
 * pets.is_active) and backs the partial unique index "a child cares for at
 * most one active pet" — never write it from PHP.
 *
 * `requires_contract`: the child must sign their own contract before acting
 * (false only for caretaker rows backfilled from pets born before contracts
 * existed — grandfathered, M1-07b).
 *
 * @property int $pet_id
 * @property int $user_id
 * @property bool $pet_is_active
 * @property bool $requires_contract
 */
class PetCaretaker extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['pet_id', 'user_id', 'requires_contract'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pet_is_active' => 'boolean',
            'requires_contract' => 'boolean',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
