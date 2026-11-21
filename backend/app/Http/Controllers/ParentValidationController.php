<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardOpenedRequest;
use App\Http\Requests\Phase2InterestRequest;
use App\Models\User;
use App\Services\Validation\DashboardOpenService;
use App\Services\Validation\Phase2InterestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Validation signals of the parent app (VALIDATION_PLAN §4.1 / §4.4, M5-08, contract
 * docs/engineering/VALIDATION_API.md, David 2026-11-21): dashboard opens (H6) and the Phase-2
 * fake door »Dobili smo pravo žival« (H7). Parents only; no child data.
 */
class ParentValidationController extends Controller
{
    public function __construct(
        private readonly DashboardOpenService $opens,
        private readonly Phase2InterestService $phase2,
    ) {}

    /**
     * The parent dashboard gained focus (after a cold start, a return to the
     * foreground or a push tap — `trigger`, optional). The server keeps at
     * most one open per parent per 30 minutes; a call inside that window is
     * accepted and ignored. Always 204. Throttled 30 / min per parent.
     *
     * POST /api/parent/dashboard-opened
     */
    public function dashboardOpened(DashboardOpenedRequest $request): Response
    {
        $this->opens->record($request->user(), $request->trigger());

        return response()->noContent();
    }

    /**
     * The parent tapped »Dobili smo pravo žival«: the first tap is stored,
     * later taps update the last one. 204.
     *
     * POST /api/parent/phase2-interest/viewed
     */
    public function phase2Viewed(Request $request): Response|JsonResponse
    {
        if (! $request->user()->can('manageFamily', User::class)) {
            return response()->json(['message' => 'Only parent profiles can do this.'], 403);
        }

        $this->phase2->viewed($request->user());

        return response()->noContent();
    }

    /**
     * Answer of the Phase-2 screen (»v pripravi« — nothing is sold): price
     * choice (monthly: free / 2,99 € / 5,99 €) and »Obvestite me, ko bo na
     * voljo«. One row per parent (a new answer replaces the old one). With
     * `notify` true the `email` is required (422 `code: email_required`) and
     * goes into the PetPrep e-mail store; `notify` false removes an address
     * left here earlier. 200 `{status: "saved"}`.
     *
     * POST /api/parent/phase2-interest
     */
    public function phase2Submit(Phase2InterestRequest $request): JsonResponse
    {
        $this->phase2->submit($request->user(), $request->priceChoice(), $request->notify(), $request->email());

        return response()->json([
            /** @var 'saved' */
            'status' => 'saved',
        ], 200);
    }
}
