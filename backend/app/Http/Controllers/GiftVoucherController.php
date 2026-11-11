<?php

namespace App\Http\Controllers;

use App\Http\Requests\RedeemGiftRequest;
use App\Services\Gift\GiftVoucherRedeemService;
use Illuminate\Http\JsonResponse;

class GiftVoucherController extends Controller
{
    public function __construct(private readonly GiftVoucherRedeemService $redeem) {}

    /**
     * Redeem a website gift voucher (M6-S01, WEBSITE_SALES_SPEC §3.4).
     *
     * Public, called by petprep.si from the browser; rate limited per IP (10 / min,
     * 30 / h) and per code (10 / h) → 429 `rate_limited`. The code (a Lemon Squeezy
     * license key) is checked with Lemon Squeezy first; only then is the parent
     * looked up by the account e-mail. Success = one unused 12-week challenge
     * purchase in the family (the parent chooses the pet in the app).
     *
     * 200 `{status: redeemed, already}` · 422 `invalid_request` | `invalid_code` |
     * `expired` | `refunded` | `no_account` · 409 `already_redeemed` · 502
     * `upstream_error` · 503 `unavailable` (switch off / not configured). `message`
     * is for logs only (sl | en by `locale`).
     *
     * POST /api/gift/redeem
     */
    public function redeem(RedeemGiftRequest $request): JsonResponse
    {
        $locale = $request->validated('locale');
        if (is_string($locale)) {
            app()->setLocale($locale);
        }

        $result = $this->redeem->redeem($request->code(), $request->email());

        if ($result->error === null) {
            return response()->json([
                /** @var 'redeemed' */
                'status' => 'redeemed',
                'already' => $result->already,
            ], 200);
        }

        return response()->json([
            /** @var 'invalid_request'|'invalid_code'|'expired'|'refunded'|'no_account'|'already_redeemed'|'rate_limited'|'upstream_error'|'unavailable' */
            'error' => $result->error,
            'message' => __('gift.'.$result->error),
        ], $result->httpStatus());
    }
}
