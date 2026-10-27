<?php

namespace App\Models;

use App\Enums\ChallengeCreditRevokeReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One purchased wish item (M6-W02, WISHES_API §5): created by a
 * RevenueCat purchase of a wish product, owned by the buyer's family and
 * assigned to one pet by the parent (redeem). Written only by WishCreditService.
 *
 * Available = not revoked and not assigned (`assigned_at` null). A credit
 * whose pet was deleted keeps `assigned_at` (used) with `pet_id` null.
 *
 * @property int $id
 * @property int $family_id
 * @property int $purchase_event_id
 * @property string $product_id
 * @property string $item_key
 * @property string|null $redeem_key
 * @property string|null $store
 * @property string|null $environment
 * @property string|null $transaction_id
 * @property Carbon $purchased_at
 * @property int|null $pet_id
 * @property Carbon|null $assigned_at
 * @property string|null $assigned_via
 * @property Carbon|null $revoked_at
 * @property ChallengeCreditRevokeReason|null $revoke_reason
 * @property string|null $revoke_event_id
 * @property Carbon|null $transferred_at
 * @property int|null $transferred_from_family_id
 */
class WishCredit extends Model
{
    public const VIA_PARENT = 'parent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id', 'purchase_event_id', 'product_id', 'store', 'environment', 'transaction_id', 'purchased_at',
        'item_key', 'redeem_key', 'pet_id', 'assigned_at', 'assigned_via', 'revoked_at', 'revoke_reason', 'revoke_event_id',
        'transferred_at', 'transferred_from_family_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'transferred_at' => 'datetime',
            'revoke_reason' => ChallengeCreditRevokeReason::class,
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function purchaseEvent(): BelongsTo
    {
        return $this->belongsTo(PurchaseEvent::class);
    }

    /**
     * Not revoked and not assigned yet — usable for a pet, oldest first.
     *
     * @param  Builder<WishCredit>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('revoked_at')->whereNull('assigned_at');
    }

    /**
     * @param  Builder<WishCredit>  $query
     */
    public function scopeUnrevoked(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
