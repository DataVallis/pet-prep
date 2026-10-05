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

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function labResult(): BelongsTo
    {
        return $this->belongsTo(MediaLabResult::class, 'media_lab_result_id');
    }
}
