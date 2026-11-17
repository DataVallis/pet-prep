<?php

namespace App\Services\Shop;

use App\Models\ShopProduct;

/**
 * Copies the configured Stripe products / prices into `shop_products` for the admin
 * (M6-S09, spec §3.7). Stripe stays the source of truth: prices are edited there.
 * HTTP first, then one short write per product.
 */
class ShopProductSync
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @return array<string, string> product key → synced | not_configured | not_found
     */
    public function run(): array
    {
        $result = [];
        foreach ((array) config('shop.products') as $key => $product) {
            $priceId = (string) ($product['price_id'] ?? '');
            if ($priceId === '' || ! $this->stripe->configured()) {
                $result[$key] = 'not_configured';

                continue;
            }
            $price = $this->stripe->retrievePrice($priceId);
            if ($price === null) {
                $result[$key] = 'not_found';

                continue;
            }
            $p = is_array($price['product'] ?? null) ? $price['product'] : [];
            ShopProduct::updateOrCreate(['key' => (string) $key], [
                'name' => mb_substr((string) ($p['name'] ?? $key), 0, 160),
                'description' => is_string($p['description'] ?? null) ? $p['description'] : null,
                'stripe_product_id' => is_string($p['id'] ?? null) ? $p['id'] : (is_string($price['product'] ?? null) ? $price['product'] : null),
                'stripe_price_id' => $priceId,
                'unit_amount' => isset($price['unit_amount']) ? (int) $price['unit_amount'] : null,
                'currency' => is_string($price['currency'] ?? null) ? $price['currency'] : null,
                'tax_inclusive' => ($price['tax_behavior'] ?? null) === 'inclusive',
                'active' => (bool) ($price['active'] ?? false) && (bool) ($p['active'] ?? true),
                'livemode' => (bool) ($price['livemode'] ?? false),
                'synced_at' => now(),
            ]);
            $result[$key] = 'synced';
        }

        return $result;
    }
}
