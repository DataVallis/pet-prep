<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §5.3, David 2026-11-10 »da«): before the new
 * app opens the store sheet it tells the server which pet the 12-week
 * challenge purchase is for. The RevenueCat webhook assigns the purchase's
 * credit to that pet (a free pet is upgraded). Per parent (= the purchase's
 * RevenueCat `app_user_id`), single use, valid `ChallengePurchaseIntentService::TTL_MINUTES`.
 * Written only by ChallengePurchaseIntentService / ChallengeCreditService.
 *
 * @property int $id
 * @property int $family_id
 * @property int $parent_user_id
 * @property int $pet_id
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $consumed_by webhook | activate | superseded
 * @property int|null $challenge_credit_id
 * @property Carbon $created_at
 */
class ChallengePurchaseIntent extends Model
{
    public const CONSUMED_BY_WEBHOOK = 'webhook';

    public const CONSUMED_BY_ACTIVATE = 'activate';

    public const CONSUMED_BY_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = ['family_id', 'parent_user_id', 'pet_id', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    /**
     * Not used yet (expiry is checked by the reader against the purchase time).
     *
     * @param  Builder<ChallengePurchaseIntent>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('consumed_at');
    }
}
