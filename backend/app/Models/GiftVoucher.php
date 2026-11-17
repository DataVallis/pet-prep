<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * One website gift voucher (M6-S01 / M6-S09, WEBSITE_SALES_SPEC §3.4) = one paid
 * Stripe shop order of the gift product (`shop_order_id`, UNIQUE). The code is OURS
 * (`PP-XXXX-XXXX-XXXX`, ShopFulfillment): `key_hash` = sha256(canonical code) for the
 * redeem lookup, `code_encrypted` (Crypt, APP_KEY) for the thank-you page, the e-mail
 * and the admin's audited »Show code«, `key_last4` for lists. Written only by
 * ShopFulfillment, ShopRefundService and GiftVoucherRedeemService. The redeemed
 * credit points here (`challenge_credits.gift_voucher_id`, one direction).
 *
 * @property int $id
 * @property int $shop_order_id
 * @property string $key_hash
 * @property string $key_last4
 * @property string $code_encrypted
 * @property string $environment PRODUCTION | SANDBOX (Stripe livemode)
 * @property string $status purchased | redeemed | refunded | expired | disabled
 * @property Carbon $purchased_at
 * @property Carbon $expires_at purchased_at + 12 months
 * @property Carbon|null $redeemed_at
 * @property int|null $family_id
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

    /** A voucher is valid this long from its purchase (spec §7). */
    public const VALID_MONTHS = 12;

    /** Crockford base32 without I, L, O, U — what a code is made of. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shop_order_id', 'key_hash', 'key_last4', 'code_encrypted', 'environment', 'status', 'purchased_at', 'expires_at',
        'redeemed_at', 'family_id', 'refunded_at', 'disabled_at', 'push_sent_at',
    ];

    /** @var list<string> */
    protected $hidden = ['code_encrypted', 'key_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shop_order_id' => 'integer',
            'family_id' => 'integer',
            'purchased_at' => 'datetime',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disabled_at' => 'datetime',
            'push_sent_at' => 'datetime',
        ];
    }

    /** A new random code `PP-XXXX-XXXX-XXXX` (60 random bits). */
    public static function newCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::ALPHABET[random_int(0, 31)];
        }

        return 'PP-'.implode('-', str_split($chars, 4));
    }

    /**
     * Canonical form of an entered code: upper case, no whitespace or dashes, the
     * Crockford look-alikes mapped (O → 0, I / L → 1), the optional »PP« prefix dropped.
     */
    public static function canonical(string $code): string
    {
        $c = strtoupper((string) preg_replace('/[\s\-_.]+/u', '', $code));
        $c = strtr($c, ['O' => '0', 'I' => '1', 'L' => '1']);
        if (strlen($c) === 14 && str_starts_with($c, 'PP')) {
            $c = substr($c, 2);
        }

        return $c;
    }

    public static function hashOf(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    public static function expiryFor(Carbon $purchasedAt): Carbon
    {
        return $purchasedAt->copy()->addMonthsNoOverflow(self::VALID_MONTHS);
    }

    /** The code as the buyer sees it (decrypted). */
    public function code(): string
    {
        return Crypt::decryptString($this->code_encrypted);
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    public function credit(): HasOne
    {
        return $this->hasOne(ChallengeCredit::class);
    }
}
