<?php

namespace App\Http\Controllers;

use App\Services\Shop\StripeException;
use App\Services\Shop\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeWebhookService $webhooks) {}

    /**
     * Stripe webhook (M6-S09 website shop). Authenticated by `Stripe-Signature`
     * (VerifyStripeWebhook — fails closed). Each event id is processed once; a duplicate
     * answers 200 `{duplicate: true}`. Stripe unreachable while fulfilling → 503 (Stripe
     * retries).
     *
     * POST /api/webhooks/stripe
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $outcome = $this->webhooks->handle($request->getContent());
        } catch (StripeException $e) {
            report($e);

            return response()->json(['message' => 'Try again later.'], 503);
        }

        return $outcome === null
            ? response()->json(['received' => true, 'duplicate' => true])
            : response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
