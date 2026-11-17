<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShopCheckoutRequest;
use App\Models\ShopOrder;
use App\Services\Shop\ShopCheckoutService;
use App\Services\Shop\ShopFiles;
use App\Services\Shop\ShopFulfillment;
use App\Services\Shop\StripeException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Website shop (M6-S09, WEBSITE_SALES_SPEC §3.4): Stripe Managed Payments checkout,
 * the thank-you page's order lookup and the signed file downloads.
 */
class ShopController extends Controller
{
    public function __construct(
        private readonly ShopCheckoutService $checkout,
        private readonly ShopFulfillment $fulfillment,
    ) {}

    /**
     * Start a Stripe checkout for one product.
     *
     * Public (petprep.si calls it from the browser), throttled per IP (10 / min, 30 / h).
     * Answers the Stripe Checkout URL; the website redirects the buyer to it. After the
     * payment Stripe sends the buyer to `success_path?session_id=cs_…`.
     *
     * 200 `{url}` · 422 `invalid_request` · 503 `unavailable` (product switch off / Stripe
     * not configured) · 502 `upstream_error` · 429.
     *
     * POST /api/shop/checkout
     */
    public function checkout(ShopCheckoutRequest $request): JsonResponse
    {
        /** @var array{product: string, locale: string, success_path: string, cancel_path: string} $data */
        $data = $request->validated();
        $result = $this->checkout->create($data['product'], $data['locale'], $data['success_path'], $data['cancel_path']);

        if (isset($result['url'])) {
            return response()->json(['url' => $result['url']]);
        }

        return response()->json([
            /** @var 'unavailable'|'upstream_error'|'unknown_product' */
            'error' => $result['error'],
        ], $result['error'] === ShopCheckoutService::ERROR_UPSTREAM ? 502 : 503);
    }

    /**
     * The order behind a Checkout Session (website thank-you page).
     *
     * Public; the session id from Stripe's redirect is the key (long, unguessable).
     * Throttled per IP (60 / min). When the payment is confirmed but the webhook has not
     * arrived yet, the order is fulfilled now. The voucher code and the download links
     * are returned only for a paid order and only for `shop.order_lookup_days` after the
     * purchase; after that (or after a refund) `status` alone.
     *
     * 200 order · 202 `{status: pending}` (payment not confirmed yet — ask again) ·
     * 404 `not_found` · 502 `upstream_error`.
     *
     * GET /api/shop/orders/{session}
     */
    public function order(string $session): JsonResponse
    {
        $order = ShopOrder::where('stripe_checkout_session_id', $session)->first();
        if ($order === null || ($order->fulfilled_at === null && $order->status === ShopOrder::STATUS_PENDING)) {
            try {
                $order = $this->fulfillment->fulfill($session);
            } catch (StripeException $e) {
                report($e);

                return response()->json(['error' => 'upstream_error'], 502);
            }
        }
        if ($order === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($order->status === ShopOrder::STATUS_PENDING) {
            return response()->json(['status' => 'pending'], 202);
        }

        $recent = $order->paid_at !== null && $order->paid_at->gt(now()->subDays((int) config('shop.order_lookup_days', 30)));
        $show = $recent && $order->delivers();
        $voucher = $show ? $order->voucher()->first() : null;

        return response()->json([
            /** @var 'paid'|'refunded'|'partially_refunded'|'disputed'|'failed' */
            'status' => $order->status,
            /** @var 'gift_voucher'|'promise_kit' */
            'product' => $order->product_key,
            'order_number' => $order->number,
            'locale' => $order->locale,
            /** The buyer's e-mail, middle hidden — the e-mail went there. */
            'email_masked' => $order->maskedEmail(),
            'voucher' => $voucher === null ? null : [
                'code' => $voucher->code(),
                'purchased_at' => $voucher->purchased_at->toIso8601String(),
                'expires_at' => $voucher->expires_at->toIso8601String(),
                /** @var 'purchased'|'redeemed'|'refunded'|'expired'|'disabled' */
                'status' => $voucher->displayStatus(),
            ],
            /** @var list<array{locale: string, url: string}> */
            'downloads' => $show ? ShopFiles::links($order, (int) config('shop.download_link_hours', 24) * 60) : [],
        ]);
    }

    /**
     * A file of a paid order (signed, expiring link from the thank-you page or the e-mail).
     * 404 when the order no longer delivers (refunded) or the file is missing.
     *
     * GET /api/shop/downloads/{order}/{locale}
     */
    public function download(int $order, string $locale): StreamedResponse|JsonResponse
    {
        $row = ShopOrder::find($order);
        $product = $row !== null ? ShopFiles::productFor($row) : null;
        if ($row === null || $product === null || ! $row->delivers() || ! ShopFiles::exists($product, $locale)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return ShopFiles::disk()->download(ShopFiles::path($product, $locale), 'petprep-'.str_replace('_', '-', $product).'-'.$locale.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The free promise kit sample (signed link from POST /api/summary-leads).
     *
     * GET /api/shop/sample/{locale}
     */
    public function sample(string $locale): StreamedResponse|JsonResponse
    {
        if (! ShopFiles::exists(ShopFiles::SAMPLE, $locale)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return ShopFiles::disk()->download(ShopFiles::path(ShopFiles::SAMPLE, $locale), 'petprep-promise-sample-'.$locale.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
