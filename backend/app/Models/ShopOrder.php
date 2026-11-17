<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One website shop order (M6-S09, WEBSITE_SALES_SPEC §3.4) = one Stripe Checkout
 * Session. Written only by Shop\ShopFulfillment (create / pay / fulfil), Shop\ShopRefundService
 * (refund / dispute) and the admin »change e-mail« action. Stripe (Link) is the seller and
 * keeps the books; this row is what PetPrep delivered and to whom.
 *
 * The buyer's e-mail / name are kept because the code and the files are sent to that
 * address (privacy policy). Never log them.
 *
 * @property int $id
 * @property string|null $number PP-2026-000123, given when the order is paid
 * @property string $product_key gift_voucher | promise_kit
 * @property string $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property bool $livemode
 * @property string $status pending | paid | refunded | partially_refunded | disputed | failed
 * @property int|null $amount_total minor units, VAT included
 * @property int|null $amount_tax
 * @property int $amount_refunded
 * @property string|null $currency lower case ISO 4217 (as Stripe sends it)
 * @property string $locale one of config('shop.locales')
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property string|null $customer_country
 * @property Carbon|null $paid_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $disputed_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 */
class ShopOrder extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED, self::STATUS_DISPUTED, self::STATUS_FAILED];

    public const PRODUCT_GIFT_VOUCHER = 'gift_voucher';

    public const PRODUCT_PROMISE_KIT = 'promise_kit';

    /** @var list<string> */
    protected $fillable = [
        'number', 'product_key', 'stripe_checkout_session_id', 'stripe_payment_intent_id', 'livemode', 'status',
        'amount_total', 'amount_tax', 'amount_refunded', 'currency', 'locale', 'customer_email', 'customer_name',
        'customer_country', 'paid_at', 'fulfilled_at', 'refunded_at', 'disputed_at', 'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'amount_total' => 'integer',
            'amount_tax' => 'integer',
            'amount_refunded' => 'integer',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disputed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** Was it paid and not taken back? (downloads, codes, e-mails) */
    public function delivers(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_PARTIALLY_REFUNDED, self::STATUS_DISPUTED], true);
    }

    /** `PP-<year>-<id, 6 digits>` — readable, unique, no extra sequence. */
    public static function numberFor(int $id, Carbon $at): string
    {
        return sprintf('PP-%d-%06d', $at->year, $id);
    }

    /** »49,99 EUR« style amount for admin lists. */
    public static function money(?int $minor, ?string $currency): string
    {
        if ($minor === null) {
            return '—';
        }

        return number_format($minor / 100, 2, ',', '.').' '.strtoupper((string) $currency);
    }

    public function voucher(): HasOne
    {
        return $this->hasOne(GiftVoucher::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(OutboundEmail::class);
    }

    public function stripeEvents(): HasMany
    {
        return $this->hasMany(StripeEvent::class);
    }

    /** The buyer's e-mail with the middle hidden: »da***@gmail.com«. */
    public function maskedEmail(): ?string
    {
        if ($this->customer_email === null || ! str_contains($this->customer_email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $this->customer_email, 2);

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
