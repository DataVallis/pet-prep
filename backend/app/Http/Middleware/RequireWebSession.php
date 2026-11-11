<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\WebParentSessionService;
use App\Services\FamilyService;
use App\Services\Gift\GiftRedeemResult;
use App\Support\WebsiteLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/gift/redeem (M6-S01b, CONTRACT_WEB_SIGNIN §3.2, W3): ONLY a website
 * sign-in token — abilities exactly ["gift:redeem"], a set expiry in the future,
 * a User. No token, an expired one, or an APP token (parent / child / legacy
 * ['*']) → 401 `unauthenticated` (redemption is never an app API). The user must
 * still be a parent with a family → else 403 `not_parent`. The family is passed
 * on as the request attribute `web_family`.
 */
class RequireWebSession
{
    public const FAMILY_ATTRIBUTE = 'web_family';

    public function __construct(private readonly FamilyService $families) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum's guard: null for unknown / expired tokens or a deleted user (memoised —
        // RestrictWebSessionTokens already resolved it; last_used_at is touched there).
        $user = $request->bearerToken() !== null ? Auth::guard('sanctum')->user() : null;
        $token = $user?->currentAccessToken();

        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->exists
            || ! WebParentSessionService::isWebToken($token)
            || $token->expires_at === null || ! $token->expires_at->isFuture()) {
            return $this->refuse($request, GiftRedeemResult::UNAUTHENTICATED);
        }

        $family = $user->isParent() ? $this->families->familyOf($user) : null;
        if ($family === null) {
            return $this->refuse($request, GiftRedeemResult::NOT_PARENT);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::FAMILY_ATTRIBUTE, $family);

        return $next($request);
    }

    private function refuse(Request $request, string $error): Response
    {
        WebsiteLocale::apply($request->input('locale'));

        return response()->json(['error' => $error, 'message' => __('gift.'.$error)], GiftRedeemResult::HTTP[$error]);
    }
}
