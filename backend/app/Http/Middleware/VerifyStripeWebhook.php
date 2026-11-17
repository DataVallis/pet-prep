<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe webhook authentication (M6-S09): fails closed. No signing secret → 503
 * (Stripe retries, nothing is lost once it is set). `Stripe-Signature: t=<unix>,v1=<hex>[,v1=…]`;
 * a v1 must equal HMAC-SHA256("<t>.<raw body>", secret), compared in constant time,
 * and `t` must be within config('services.stripe.webhook_tolerance_seconds') of now.
 * Runs before any JSON parsing.
 */
class VerifyStripeWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            Log::error('StripeWebhook: STRIPE_WEBHOOK_SECRET not set — refusing the webhook');

            return response()->json(['message' => 'Webhook not configured.'], 503);
        }

        if (! self::valid((string) $request->header('Stripe-Signature', ''), $request->getContent(), $secret)) {
            Log::warning('StripeWebhook: invalid, missing or stale Stripe-Signature');

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }

    public static function valid(string $header, string $body, string $secret, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = strtolower($v);
            }
        }
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        $tolerance = (int) config('services.stripe.webhook_tolerance_seconds', 300);
        if (abs(($now ?? now()->getTimestamp()) - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
