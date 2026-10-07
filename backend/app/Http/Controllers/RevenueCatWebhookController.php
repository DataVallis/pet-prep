<?php

namespace App\Http\Controllers;

use App\Http\Requests\RevenueCatWebhookRequest;
use App\Services\RevenueCatWebhookService;
use Illuminate\Http\JsonResponse;

class RevenueCatWebhookController extends Controller
{
    public function __construct(private readonly RevenueCatWebhookService $webhooks) {}

    /**
     * RevenueCat webhook (M3-08).
     *
     * Auth: `Authorization: Bearer <REVENUECAT_WEBHOOK_SECRET>` — no secret
     * configured → 503, wrong header → 401 (VerifyRevenueCatWebhook).
     * Every event is stored once in `purchase_events` (idempotent by
     * `event.id`; a repeat → 200 `duplicate: true`, nothing reprocessed) and
     * applied to the family's entitlements (EntitlementService). Unknown
     * users, sandbox events (when not accepted) and event types we don't
     * act on are stored and answered 200 so RevenueCat stops retrying.
     *
     * POST /api/webhooks/revenuecat
     */
    public function handle(RevenueCatWebhookRequest $request): JsonResponse
    {
        $outcome = $this->webhooks->handle($request->body());

        return response()->json([
            'received' => true,
            'duplicate' => $outcome === null,
            /** @var 'granted'|'extended'|'revoked'|'transferred'|'recorded'|'ignored'|'unknown_user'|'sandbox_ignored'|'no_entitlement'|null */
            'outcome' => $outcome?->value,
        ], 200);
    }
}
