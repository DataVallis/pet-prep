<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailSignupRequest;
use App\Services\EmailSignupService;
use Illuminate\Http\Response;

/**
 * Every e-mail left on petprep.si (M6-S09) — newsletter and early-access forms, the calculator and
 * the breed quiz — in one segmented store.
 */
class EmailSignupController extends Controller
{
    public function __construct(private readonly EmailSignupService $signups) {}

    /**
     * Leave an e-mail with consent. Public, no token; throttled per IP (20 / hour, IPv6 /64) — the
     * address is only the throttle key, never stored or logged. `purpose` newsletter | early_access,
     * `source` = the form on the site, `page_path` (+ optional `section`) = where on the page; optional
     * context for targeting: `locale` (one of the 15 website languages), `species`, `breed`, `stage`,
     * `quiz` {result ≤ 3 breed ids, answers}, `utm`, `referrer_host`. One contact per e-mail (lower-case);
     * a repeat adds a signup row, never a second contact. The answer is the same for a new and a known
     * address. No e-mail is sent and nothing about the visitor's address or browser is stored. A filled
     * hidden `website` field = bot: 204 and nothing stored.
     *
     * 204 no content; 422 invalid body; 429 throttled.
     *
     * POST /api/email-signups
     */
    public function store(StoreEmailSignupRequest $request): Response
    {
        $this->signups->record($request->signupData());

        return response()->noContent();
    }
}
