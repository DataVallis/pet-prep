<?php

namespace App\Models;

use App\Enums\ChallengeCreditRevokeReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One purchased 12-week challenge (M3-11, PAYMENTS_SPEC P1): created by a
 * RevenueCat purchase of the consumable, owned by the buyer's family and
 * assigned to exactly one pet. Written only by ChallengeCreditService.
 *
 * Available = not revoked and not assigned (`assigned_at` null). A credit
 * whose pet was deleted keeps `assigned_at` (used) with `pet_id` null.
 *
 * @property int $id
 * @property int $family_id
 * @property int|null $purchase_event_id null for a website gift credit (source web_gift)
 * @property string $source store (RevenueCat) | web_gift (M6-S01 website voucher)
 * @property int|null $gift_voucher_id
 * @property string $product_id
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
 * @property Carbon|null $unassigned_alerted_at
 */
class ChallengeCredit extends Model
{
    /** M6-S01: a store purchase through RevenueCat (every credit before M6-S01). */
    public const SOURCE_STORE = 'store';

    /** M6-S01: a website gift voucher (Lemon Squeezy) redeemed for the family. */
    public const SOURCE_WEB_GIFT = 'web_gift';

    public const VIA_PARENT = 'parent';

    public const VIA_WEBHOOK = 'webhook';

    /** M3-13: a credit the family already held paid a challenge pet at its birth (contract). */
    public const VIA_BIRTH = 'birth';

    /**
     * M6-U01 (FREE_PET_UPGRADE_SPEC §5.3): the webhook assigned it to the pet the
     * parent's purchase intent named (a free pet is upgraded on this path).
     */
    public const VIA_INTENT = 'intent';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'family_id', 'purchase_event_id', 'product_id', 'store', 'environment', 'transaction_id', 'purchased_at',
        'pet_id', 'assigned_at', 'assigned_via', 'revoked_at', 'revoke_reason', 'revoke_event_id',
        'transferred_at', 'transferred_from_family_id', 'source', 'gift_voucher_id',
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
            'unassigned_alerted_at' => 'datetime',
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

    public function giftVoucher(): BelongsTo
    {
        return $this->belongsTo(GiftVoucher::class);
    }

    /**
     * Not revoked and not assigned yet — usable for a pet, oldest first.
     *
     * @param  Builder<ChallengeCredit>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('revoked_at')->whereNull('assigned_at');
    }

    /**
     * @param  Builder<ChallengeCredit>  $query
     */
    public function scopeUnrevoked(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
