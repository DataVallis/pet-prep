<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lemon Squeezy webhook authentication (M6-S01, CONTRACT §5.2 / L7): fails closed.
 *
 * No signing secret, store id or gift product id configured → 503, nothing is
 * processed (LS retries, so nothing is lost once it is configured). `X-Signature`
 * must be the lowercase hex HMAC-SHA256 of the RAW request body keyed by the
 * secret — computed over the bytes received, never over re-encoded JSON —
 * compared in constant time. Runs before any JSON parsing.
 */
class VerifyLemonSqueezyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.lemonsqueezy.webhook_secret');

        if ($secret === '' || (string) config('services.lemonsqueezy.store_id') === ''
            || (string) config('services.lemonsqueezy.gift_product_id') === '') {
            Log::error('LemonSqueezyWebhook: LEMONSQUEEZY_WEBHOOK_SECRET / STORE_ID / GIFT_PRODUCT_ID not set — refusing the webhook');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        $provided = strtolower(trim((string) $request->header('X-Signature', '')));
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('LemonSqueezyWebhook: invalid or missing X-Signature');

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
