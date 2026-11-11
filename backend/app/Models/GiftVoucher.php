<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One website gift voucher (M6-S01, WEBSITE_SALES_SPEC §3.4) = one Lemon Squeezy
 * license key of the gift product. Written only by GiftVoucherRedeemService and
 * LemonSqueezyWebhookService (row upsert on `ls_license_key_id`).
 *
 * The key itself is NEVER stored: `key_hash` = sha256(canonical key) and
 * `key_last4` (admin display only). No buyer data. The redeemed credit points
 * here (`challenge_credits.gift_voucher_id`, one direction — no circular FK).
 *
 * @property int $id
 * @property string $key_hash
 * @property string $key_last4
 * @property int $ls_license_key_id
 * @property int $ls_order_id
 * @property string $environment PRODUCTION | SANDBOX
 * @property string $status purchased | redeemed | refunded | expired | disabled
 * @property Carbon $purchased_at
 * @property Carbon $expires_at purchased_at + 12 months (server rule, LS expiry ignored)
 * @property int|null $claim_family_id
 * @property Carbon|null $claimed_at
 * @property Carbon|null $redeemed_at
 * @property int|null $family_id
 * @property string|null $ls_instance_id
 * @property Carbon|null $refunded_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $push_sent_at
 */
class GiftVoucher extends Model
{
    public const STATUS_PURCHASED = 'purchased';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_DISABLED = 'disabled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PURCHASED, self::STATUS_REDEEMED, self::STATUS_REFUNDED, self::STATUS_EXPIRED, self::STATUS_DISABLED];

    /** A voucher is valid this long from its purchase (spec §7 default), whatever the LS license length is. */
    public const VALID_MONTHS = 12;

    /** A claim younger than this blocks another family (it may be mid-redeem). */
    public const CLAIM_TTL_MINUTES = 5;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key_hash', 'key_last4', 'ls_license_key_id', 'ls_order_id', 'environment', 'status', 'purchased_at', 'expires_at',
        'claim_family_id', 'claimed_at', 'redeemed_at', 'family_id', 'ls_instance_id', 'refunded_at', 'disabled_at', 'push_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ls_license_key_id' => 'integer',
            'ls_order_id' => 'integer',
            'claim_family_id' => 'integer',
            'family_id' => 'integer',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disabled_at' => 'datetime',
            'push_sent_at' => 'datetime',
        ];
    }

    /** Canonical form of a code: all whitespace removed, lowercase (LS keys are lowercase UUIDs — UNVERIFIED). */
    public static function canonical(string $code): string
    {
        return strtolower((string) preg_replace('/\s+/u', '', $code));
    }

    public static function hashOf(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    public static function expiryFor(Carbon $purchasedAt): Carbon
    {
        return $purchasedAt->copy()->addMonthsNoOverflow(self::VALID_MONTHS);
    }

    /** Status as admin shows it: an unredeemed voucher past its validity reads »expired«. */
    public function displayStatus(?Carbon $now = null): string
    {
        if ($this->status === self::STATUS_PURCHASED && $this->expires_at->lt($now ?? now())) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function credit(): HasOne
    {
        return $this->hasOne(ChallengeCredit::class);
    }
}
