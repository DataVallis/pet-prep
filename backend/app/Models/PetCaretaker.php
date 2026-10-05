<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * **History (M2-08, PR #29):** when a child profile is deleted, its row on a
 * pet that stays (shared pet) becomes a TOMBSTONE — `user_id` null,
 * `ended_at` = deletion time, `started_at` = when the child started caring
 * (their contract / the birth). Tombstones keep the fair-share Care Score of
 * the remaining children unchanged (CareScoreService counts every caretaker
 * active when a routine opened). Lists, counts, recipients and
 * authorization use only ACTIVE rows (`scopeActive`).
 *
 * @property int $pet_id
 * @property int|null $user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
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
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Caretakers still caring (not a deleted child's tombstone).
     *
     * @param  Builder<PetCaretaker>  $query
     * @return Builder<PetCaretaker>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('user_id')->whereNull('ended_at');
    }

    public function isTombstone(): bool
    {
        return $this->user_id === null || $this->ended_at !== null;
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
