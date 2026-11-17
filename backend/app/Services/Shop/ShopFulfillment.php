<?php

namespace App\Services\Shop;

use App\Jobs\SendShopAlert;
use App\Models\GiftVoucher;
use App\Models\ShopOrder;
use App\Support\WebsiteLocale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE place a Stripe Checkout Session becomes a PetPrep order and its delivery
 * (M6-S09, spec §3.4). Called by the webhook (checkout.session.completed /
 * async_payment_succeeded / async_payment_failed) and by the website's thank-you page
 * (GET /api/shop/orders/{session}) — whichever comes first; both may run at once.
 *
 * 1. Read the session from Stripe (HTTP, never in a transaction) with its line items.
 * 2. Ours? metadata.petprep_product is a configured product AND the single line item is
 *    that product's configured price; test-mode sessions only with STRIPE_ACCEPT_TEST_MODE.
 * 3. INSERT … ON CONFLICT DO NOTHING on the session id, then ONE transaction with the
 *    order row locked: amounts / buyer, paid → number + delivery exactly once
 *    (gift → a voucher with a new code) + the buyer's e-mail queued.
 *
 * Logs carry order / session ids only — never the code, the e-mail or the name.
 */
class ShopFulfillment
{
    /** The last fulfill() refused a paid session of ours (webhook outcome `rejected`). */
    public bool $lastRejected = false;

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly ShopEmailComposer $emails,
    ) {}

    /**
     * @return ShopOrder|null null = not one of our sessions (or unknown at Stripe)
     */
    public function fulfill(string $sessionId): ?ShopOrder
    {
        $session = $this->stripe->retrieveCheckoutSession($sessionId);
        if ($session === null) {
            return null;
        }
        $this->lastRejected = false;
        $product = $this->productOf($session);
        if ($product === null) {
            if ($this->claimsOurs($session) && ($session['payment_status'] ?? null) === 'paid') {
                // Paid, marked as ours, but price / quantity / mode do not match the config (e.g. the price was
                // changed while a checkout was open): the buyer paid and gets nothing — a person must look.
                $this->lastRejected = true;
                Log::error('ShopFulfillment: paid PetPrep session rejected (price, quantity or mode does not match)', ['session_id' => $sessionId]);
                if (Cache::add('shop:rejected:'.$sessionId, 1, now()->addDay())) {
                    SendShopAlert::dispatch(SendShopAlert::REASON_REJECTED_PAYMENT, null, null, $sessionId);
                }
            } else {
                Log::warning('ShopFulfillment: session is not a PetPrep shop session', ['session_id' => $sessionId]);
            }

            return null;
        }
        $livemode = (bool) ($session['livemode'] ?? false);
        if (! $livemode && ! config('services.stripe.accept_test_mode')) {
            Log::info('ShopFulfillment: test-mode session ignored', ['session_id' => $sessionId]);

            return null;
        }

        $now = now();
        ShopOrder::query()->insertOrIgnore([
            'product_key' => $product,
            'stripe_checkout_session_id' => $sessionId,
            'livemode' => $livemode,
            'status' => ShopOrder::STATUS_PENDING,
            'locale' => self::localeOf($session),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::transaction(function () use ($sessionId, $session, $product): ShopOrder {
            /** @var ShopOrder $order */
            $order = ShopOrder::where('stripe_checkout_session_id', $sessionId)->lockForUpdate()->firstOrFail();
            $this->fill($order, $session);

            $paymentStatus = (string) ($session['payment_status'] ?? '');
            if ($paymentStatus === 'paid'
                && in_array($order->status, [ShopOrder::STATUS_PENDING, ShopOrder::STATUS_FAILED], true)) {
                $paidAt = now()->startOfSecond();
                $order->forceFill([
                    'status' => ShopOrder::STATUS_PAID,
                    'paid_at' => $paidAt,
                    'failed_at' => null,
                    'number' => $order->number ?? ShopOrder::numberFor($order->id, $paidAt),
                ]);
            }
            $order->save();

            if ($order->status === ShopOrder::STATUS_PAID && $order->fulfilled_at === null) {
                $this->deliver($order, $product);
                $order->forceFill(['fulfilled_at' => now()])->save();
                $this->emails->queueFor($order);
                Log::info('ShopFulfillment: order fulfilled', ['order_id' => $order->id, 'product' => $product]);
            }

            return $order;
        });
    }

    /** A delayed payment method failed (webhook checkout.session.async_payment_failed). */
    public function markFailed(string $sessionId): ?ShopOrder
    {
        return DB::transaction(function () use ($sessionId): ?ShopOrder {
            $order = ShopOrder::where('stripe_checkout_session_id', $sessionId)->lockForUpdate()->first();
            if ($order === null || $order->status !== ShopOrder::STATUS_PENDING) {
                return $order;
            }
            $order->forceFill(['status' => ShopOrder::STATUS_FAILED, 'failed_at' => now()])->save();

            return $order;
        });
    }

    /**
     * Which product the session sells, or null when it is not ours.
     *
     * @param  array<string, mixed>  $session
     */
    public function claimsOurs(array $session): bool
    {
        $product = data_get($session, 'metadata.petprep_product');

        return is_string($product) && is_array(config('shop.products.'.$product));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function productOf(array $session): ?string
    {
        $product = data_get($session, 'metadata.petprep_product');
        if (! is_string($product) || ! is_array(config('shop.products.'.$product))) {
            return null;
        }
        if (($session['mode'] ?? null) !== 'payment') {
            return null;
        }
        $items = data_get($session, 'line_items.data');
        $priceId = (string) config('shop.products.'.$product.'.price_id');
        if (! is_array($items) || count($items) !== 1 || $priceId === ''
            || data_get($items, '0.price.id') !== $priceId || (int) data_get($items, '0.quantity', 1) !== 1) {
            return null;
        }

        return $product;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public static function localeOf(array $session): string
    {
        $locale = data_get($session, 'metadata.locale');

        return in_array($locale, WebsiteLocale::ALL, true) ? $locale : 'en';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function fill(ShopOrder $order, array $session): void
    {
        $email = data_get($session, 'customer_details.email');
        $name = data_get($session, 'customer_details.name');
        $country = data_get($session, 'customer_details.address.country');
        $pi = $session['payment_intent'] ?? null;
        $order->forceFill(array_filter([
            'stripe_payment_intent_id' => is_string($pi) ? $pi : (is_array($pi) ? ($pi['id'] ?? null) : null),
            'amount_total' => isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            'amount_tax' => data_get($session, 'total_details.amount_tax') !== null ? (int) data_get($session, 'total_details.amount_tax') : null,
            'currency' => is_string($session['currency'] ?? null) ? $session['currency'] : null,
            // The admin may have corrected the address — never overwrite it from Stripe.
            'customer_email' => $order->customer_email === null && is_string($email) ? mb_substr($email, 0, 254) : null,
            'customer_name' => $order->customer_name === null && is_string($name) ? mb_substr($name, 0, 160) : null,
            'customer_country' => is_string($country) && strlen($country) === 2 ? strtoupper($country) : null,
        ], fn ($v) => $v !== null));
    }

    /** Product delivery inside the order lock — exactly once per order. */
    private function deliver(ShopOrder $order, string $product): void
    {
        if ($product !== ShopOrder::PRODUCT_GIFT_VOUCHER || GiftVoucher::where('shop_order_id', $order->id)->exists()) {
            return; // the promise kit is delivered by its signed download links
        }
        $code = self::uniqueCode();
        $purchasedAt = ($order->paid_at ?? now())->copy()->utc()->startOfSecond();
        GiftVoucher::create([
            'shop_order_id' => $order->id,
            'key_hash' => GiftVoucher::hashOf(GiftVoucher::canonical($code)),
            'key_last4' => substr(GiftVoucher::canonical($code), -4),
            'code_encrypted' => Crypt::encryptString($code),
            'environment' => $order->livemode ? 'PRODUCTION' : 'SANDBOX',
            'status' => GiftVoucher::STATUS_PURCHASED,
            'purchased_at' => $purchasedAt,
            'expires_at' => GiftVoucher::expiryFor(Carbon::instance($purchasedAt)),
        ]);
    }

    private static function uniqueCode(): string
    {
        do {
            $code = GiftVoucher::newCode();
        } while (GiftVoucher::where('key_hash', GiftVoucher::hashOf(GiftVoucher::canonical($code)))->exists());

        return $code;
    }
}
