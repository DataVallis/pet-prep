<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSummaryLeadRequest;
use App\Services\SummaryLeadService;
use Illuminate\Http\JsonResponse;

/**
 * Parent e-mails from the petprep.si page »Koliko dela je pes« (M6-S05).
 */
class SummaryLeadController extends Controller
{
    public function __construct(private readonly SummaryLeadService $leads) {}

    /**
     * Leave an e-mail for PetPrep news. Public, no token; throttled per IP
     * (10 / hour, IPv6 /64). The parent must tick the consent box (`consent`).
     * One row per e-mail: a repeat visit updates the row, it never adds one.
     * The answer is the same for a new and a known address. No e-mail is sent
     * and nothing about the visitor's address is stored. A filled hidden
     * `website` field = bot: 201 and nothing stored.
     *
     * 201 `{ok: true}`; 422 invalid body; 429 throttled.
     *
     * POST /api/summary-leads
     */
    public function store(StoreSummaryLeadRequest $request): JsonResponse
    {
        /** @var array{email: string, consent_version: string, source: string, locale?: string|null, breed?: string|null, stage?: string|null} $data */
        $data = $request->safe()->only(['email', 'consent_version', 'source', 'locale', 'breed', 'stage']);

        $this->leads->record($data);

        return response()->json(['ok' => true], 201);
    }
}
