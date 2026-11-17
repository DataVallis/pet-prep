<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admin copy of a Stripe product + price (M6-S09). Stripe is the source of truth;
 * rows are written only by Shop\ShopProductSync (`shop:sync-products`, admin button).
 *
 * @property int $id
 * @property string $key gift_voucher | promise_kit (config('shop.products'))
 * @property string $name
 * @property string|null $description
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 * @property int|null $unit_amount
 * @property string|null $currency
 * @property bool $tax_inclusive
 * @property bool $active
 * @property bool $livemode
 * @property Carbon|null $synced_at
 */
class ShopProduct extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key', 'name', 'description', 'stripe_product_id', 'stripe_price_id', 'unit_amount', 'currency',
        'tax_inclusive', 'active', 'livemode', 'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_amount' => 'integer',
            'tax_inclusive' => 'boolean',
            'active' => 'boolean',
            'livemode' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** Stripe dashboard link of the product (test or live). */
    public function dashboardUrl(): ?string
    {
        if ($this->stripe_product_id === null) {
            return null;
        }

        return 'https://dashboard.stripe.com/'.($this->livemode ? '' : 'test/').'products/'.$this->stripe_product_id;
    }
}
