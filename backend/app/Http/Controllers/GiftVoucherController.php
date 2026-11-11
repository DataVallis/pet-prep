<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireWebSession;
use App\Http\Requests\RedeemGiftRequest;
use App\Models\Family;
use App\Services\Gift\GiftVoucherRedeemService;
use App\Support\WebsiteLocale;
use Illuminate\Http\JsonResponse;

class GiftVoucherController extends Controller
{
    public function __construct(private readonly GiftVoucherRedeemService $redeem) {}

    /**
     * Redeem a website gift voucher (M6-S01, WEBSITE_SALES_SPEC §3.4).
     *
     * Called by petprep.si from the browser with `Authorization: Bearer <token>` from
     * `POST /api/web/parent-session` (M6-S01b) — ONLY that website token (ability
     * `gift:redeem`); app tokens get 401. The family is the token's parent's family.
     * Rate limited per IP (10 / min, 30 / h), per code (10 / h) and per family
     * (20 / h) → 429 `rate_limited`. The code (a Lemon Squeezy license key) is checked
     * with Lemon Squeezy. Success = one unused 12-week challenge purchase in the
     * family (the parent chooses the pet in the app).
     *
     * 200 `{status: redeemed, already}` · 422 `invalid_request` | `invalid_code` |
     * `expired` | `refunded` · 409 `already_redeemed` · 401 `unauthenticated` (no /
     * expired / non-web token; family gone) · 403 `not_parent` · 502
     * `upstream_error` · 503 `unavailable` (switch off / not configured — checked
     * after the token). `message` is for logs only (sl | en by `locale`; the other
     * website languages → en).
     *
     * POST /api/gift/redeem
     */
    public function redeem(RedeemGiftRequest $request): JsonResponse
    {
        WebsiteLocale::apply($request->validated('locale'));

        /** @var Family $family set by RequireWebSession */
        $family = $request->attributes->get(RequireWebSession::FAMILY_ATTRIBUTE);
        $result = $this->redeem->redeem($request->code(), $family);

        if ($result->error === null) {
            return response()->json([
                /** @var 'redeemed' */
                'status' => 'redeemed',
                'already' => $result->already,
            ], 200);
        }

        return response()->json([
            /** @var 'invalid_request'|'invalid_code'|'expired'|'refunded'|'already_redeemed'|'unauthenticated'|'not_parent'|'rate_limited'|'upstream_error'|'unavailable' */
            'error' => $result->error,
            'message' => __('gift.'.$result->error),
        ], $result->httpStatus());
    }
}
