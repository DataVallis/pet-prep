<?php

namespace App\Http\Controllers;

use App\Services\Gift\LemonSqueezyWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LemonSqueezyWebhookController extends Controller
{
    public function __construct(private readonly LemonSqueezyWebhookService $webhooks) {}

    /**
     * Lemon Squeezy webhook (M6-S01, website gift vouchers).
     *
     * Auth: `X-Signature` = hex HMAC-SHA256 of the raw body with
     * LEMONSQUEEZY_WEBHOOK_SECRET — not configured → 503, wrong → 401
     * (VerifyLemonSqueezyWebhook). Every delivery is stored once in
     * `lemonsqueezy_events` (a repeat → 200 `duplicate: true`). `order_created` is
     * recorded; `license_key_created` / `_updated` create or update the voucher;
     * a full `order_refunded` or a disabled key invalidates it and revokes its
     * challenge credit. Processed whatever the »Gift vouchers« switch says.
     *
     * POST /api/webhooks/lemonsqueezy
     */
    public function handle(Request $request): JsonResponse
    {
        $result = $this->webhooks->handle($request->getContent());

        return response()->json([
            'received' => true,
            'duplicate' => $result === null,
            /** @var 'recorded'|'voucher_created'|'voucher_updated'|'refunded'|'revoked'|'disabled'|'expired'|'partial_refund'|'ignored'|'foreign_store'|'unknown_product'|'test_mode_ignored'|null */
            'outcome' => $result,
        ], 200);
    }
}
