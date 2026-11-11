<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartnerInquiryRequest;
use App\Jobs\SendPartnerInquiryAlert;
use App\Models\PartnerInquiry;
use App\Services\AppSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Partner inquiries from the petprep.si partners page (M6-S03).
 */
class PartnerInquiryController extends Controller
{
    public function __construct(private readonly AppSettingsService $settings) {}

    /**
     * Send a partner inquiry. Public, no token; throttled per IP (5 / hour,
     * IPv6 /64). The server stores it and posts a Discord alert without the
     * sender's data; there is no automatic e-mail. Nothing about the visitor's
     * address is stored. A filled hidden `website` field = bot: 201 and nothing
     * stored.
     *
     * 201 `{ok: true}`; 422 invalid body; 429 throttled; 503 while the
     * superadmin switch »Partner inquiries« is off.
     *
     * POST /api/partner-inquiries
     */
    public function store(StorePartnerInquiryRequest $request): JsonResponse
    {
        if (! $this->settings->partnerInquiries()) {
            return response()->json(['message' => 'Partner inquiries are not accepted right now.'], 503);
        }

        /** @var array{company: string, contact_name: string, email: string, type: string, message: string, locale?: string|null} $data */
        $data = $request->safe()->only(['company', 'contact_name', 'email', 'type', 'message', 'locale']);

        $inquiry = PartnerInquiry::create($data);

        // After the insert (no transaction here, and afterCommit() keeps it so if a caller wraps
        // one): the alert job makes the Discord call, never this request. Type only — no PII.
        SendPartnerInquiryAlert::dispatch($inquiry->type->value)->afterCommit();

        return response()->json(['ok' => true], 201);
    }
}
