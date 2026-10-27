<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item a pet owns (M6-W01): one row per durable item, one per treat pack.
 * Revoked (store refund) rows stay for the record with `revoked_at`.
 *
 * @property int $id
 * @property int $pet_id
 * @property string $item_key
 * @property string $kind durable | consumable
 * @property string $variant
 * @property int|null $wish_credit_id
 * @property int $quantity_total
 * @property int $quantity_left
 * @property Carbon $acquired_at
 * @property Carbon|null $revoked_at
 */
class PetItem extends Model
{
    protected $fillable = ['pet_id', 'item_key', 'kind', 'variant', 'wish_credit_id', 'quantity_total', 'quantity_left', 'acquired_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['acquired_at' => 'datetime', 'revoked_at' => 'datetime', 'quantity_total' => 'integer', 'quantity_left' => 'integer'];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}
