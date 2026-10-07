<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RevenueCat webhook authentication (M3-08, AUDIT S2): fails closed.
 *
 * RevenueCat sends the Authorization header value configured in its
 * dashboard; we expect `Bearer <REVENUECAT_WEBHOOK_SECRET>` (the bare secret
 * is accepted too). No secret configured → 503, nothing is processed.
 * Runs before validation, so an unauthenticated caller learns nothing about
 * the payload shape. Comparison in constant time.
 */
class VerifyRevenueCatWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.revenuecat.webhook_secret');

        if ($secret === '') {
            Log::error('RevenueCatWebhook: REVENUECAT_WEBHOOK_SECRET is not set — refusing the webhook');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        $provided = (string) $request->header('Authorization', '');
        // Both comparisons always run (no short circuit on the first).
        $bearer = hash_equals('Bearer '.$secret, $provided);
        $bare = hash_equals($secret, $provided);

        if (! ($bearer | $bare)) {
            Log::warning('RevenueCatWebhook: invalid Authorization header');

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
