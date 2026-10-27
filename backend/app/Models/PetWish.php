<?php

namespace App\Models;

use App\Enums\WishStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A child's wish for an item for a pet (M6-W01). Ids only — no new personal
 * data. Written only by WishService / WishCreditService.
 *
 * @property int $id
 * @property int $pet_id
 * @property int $child_user_id
 * @property string $item_key
 * @property WishStatus $status
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class PetWish extends Model
{
    protected $fillable = ['pet_id', 'child_user_id', 'item_key', 'status', 'decided_by_user_id', 'decided_at'];

    protected function casts(): array
    {
        return ['status' => WishStatus::class, 'decided_at' => 'datetime'];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(User::class, 'child_user_id');
    }
}
