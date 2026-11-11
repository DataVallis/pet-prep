<?php

namespace App\Http\Middleware;

use App\Services\Auth\WebParentSessionService;
use App\Support\WebsiteLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API group (M6-S01b, CONTRACT_WEB_SIGNIN §1.6 W1): a website sign-in token
 * (abilities exactly ["gift:redeem"], rule W) may call ONLY the routes in
 * ALLOWED_ROUTES. Anywhere else — the bare auth group, routes that read a token
 * optionally (app-errors, signed media, certificates), broadcasting auth —
 * → 403 `token_scope`. Defence in depth next to the route abilities (W2).
 *
 * No bearer → pass without a query. Otherwise the sanctum guard resolves the
 * user once (RequestGuard memoises it, so the route's auth:sanctum reuses it).
 * App tokens (parent / child / legacy ['*']) pass unchanged.
 */
class RestrictWebSessionTokens
{
    public const ALLOWED_ROUTES = ['gift.redeem', 'web.parent-session.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $token = Auth::guard('sanctum')->user()?->currentAccessToken();
        if (WebParentSessionService::isWebToken($token)
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            WebsiteLocale::apply($request->input('locale'));

            return response()->json([
                'error' => 'token_scope',
                'message' => __('web_session.token_scope'),
            ], 403);
        }

        return $next($request);
    }
}
