<?php

namespace App\Http\Controllers;

use App\Exceptions\WebSessionException;
use App\Http\Requests\WebParentSessionRequest;
use App\Services\Auth\WebParentSessionService;
use App\Support\WebsiteLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebParentSessionController extends Controller
{
    public function __construct(private readonly WebParentSessionService $sessions) {}

    /**
     * Website sign-in of a parent for gift voucher redemption (M6-S01b).
     *
     * Called by petprep.si's redeem page. `method` = `password` (`email` +
     * `password`), `google` or `apple` (`id_token` from the provider's redirect for
     * the WEBSITE client id + the raw `nonce`, required for both). Signs in an
     * EXISTING parent with a family — never creates, links or changes an account.
     * The token has the single ability `gift:redeem`, expires after 15 minutes and
     * works only on `POST /api/gift/redeem` and `DELETE /api/web/parent-session`
     * (every other route → 403 `token_scope`). No user data in the answer.
     *
     * Errors `{error, message}`: 422 `invalid_request` · 401 `invalid_credentials`
     * (unknown e-mail / wrong password / account without a password — one body) |
     * `invalid_token` · 404 `account_not_found` · 403 `not_parent` · 429
     * `rate_limited` + `Retry-After` (10 / min + 30 / h per IP; 5 password failures
     * per account in 15 min from any IP) · 503 `provider_disabled` |
     * `provider_unavailable` | `unavailable` (gift switch off). `message` is for
     * logs only (sl | en by `locale`, other website languages → en).
     *
     * POST /api/web/parent-session
     */
    public function store(WebParentSessionRequest $request): JsonResponse
    {
        WebsiteLocale::apply($request->messageLocale());

        try {
            $session = $request->signInMethod() === 'password'
                ? $this->sessions->openWithPassword($request->emailAddress(), $request->plainPassword())
                : $this->sessions->openWithProvider($request->provider(), $request->idToken(), $request->rawNonce());
        } catch (WebSessionException $e) {
            $headers = $e->retryAfter !== null ? ['Retry-After' => (string) $e->retryAfter] : [];

            return response()->json([
                /** @var 'invalid_credentials'|'invalid_token'|'account_not_found'|'not_parent'|'rate_limited'|'provider_disabled'|'provider_unavailable'|'unavailable' */
                'error' => $e->error,
                'message' => __('web_session.'.$e->error),
            ], $e->status(), $headers);
        }

        return response()->json([
            'token' => $session->token,
            /** @var 'Bearer' */
            'token_type' => 'Bearer',
            /** @var list<'gift:redeem'> */
            'abilities' => ['gift:redeem'],
            'expires_at' => $session->expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_in' => WebParentSessionService::TTL_MINUTES * 60,
        ], 200);
    }

    /**
     * Sign out of the website session (M6-S01b). Deletes the presented token only
     * when it is a valid web (`gift:redeem`) token; no token, an unknown or expired
     * one, or an APP token → also 204 and nothing is deleted.
     *
     * DELETE /api/web/parent-session
     */
    public function destroy(Request $request): Response
    {
        $this->sessions->close($request->bearerToken());

        return response()->noContent();
    }
}
