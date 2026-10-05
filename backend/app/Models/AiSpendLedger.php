<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fal.ai call with its ESTIMATED cost (M4-07). Written by
 * App\Services\Media\AiSpendGuard before the HTTP call (status reserved) and
 * settled afterwards: committed (fal accepted / produced) or void (refused,
 * failed before fal charged). Caps count reserved + committed.
 */
class AiSpendLedger extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_VOID = 'void';

    protected $table = 'ai_spend_ledger';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purpose',
        'profile',
        'endpoint',
        'unit',
        'units',
        'cost_usd',
        'status',
        'error_reason',
        'request_id',
        'pet_id',
        'pet_media_id',
        'media_lab_result_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'float',
            'cost_usd' => 'float',
        ];
    }

    /**
     * Rows that count against the caps.
     *
     * @param  Builder<AiSpendLedger>  $query
     * @return Builder<AiSpendLedger>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RESERVED, self::STATUS_COMMITTED]);
    }

    /**
     * Keep the spend of pets that are about to be deleted, without the pet
     * (M2-08: accounting outlives the family). Must run BEFORE the pet delete:
     * left to the two `ON DELETE SET NULL` FKs (pet_id → pets, pet_media_id →
     * pet_media) a row linked to both fails in one DELETE — PostgreSQL re-checks
     * pet_media_id while setting pet_id null, after the slot is already gone.
     *
     * @param  array<int, int>  $petIds
     */
    public static function detachPets(array $petIds): int
    {
        if ($petIds === []) {
            return 0;
        }

        return self::query()
            ->where(fn (Builder $q) => $q
                ->whereIn('pet_id', $petIds)
                ->orWhereIn('pet_media_id', PetMedia::whereIn('pet_id', $petIds)->select('id')))
            ->update(['pet_id' => null, 'pet_media_id' => null]);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function labResult(): BelongsTo
    {
        return $this->belongsTo(MediaLabResult::class, 'media_lab_result_id');
    }
}
