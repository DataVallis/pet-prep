<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Enums\TokenAbility;
use App\Exceptions\SocialAuthException;
use App\Exceptions\WebSessionException;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Providers\AppServiceProvider;
use App\Services\FamilyService;
use App\Services\Gift\GiftVoucherRedeemService;
use App\Support\DummyPasswordHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Website sign-in of a parent on the gift redeem page (M6-S01b,
 * CONTRACT_WEB_SIGNIN §1). E-mail + password, Google or Apple — the same
 * account as in the app — opens a 15-minute token with the single ability
 * `gift:redeem`, which works only on POST /api/gift/redeem and
 * DELETE /api/web/parent-session (RestrictWebSessionTokens, rule W).
 *
 * Never creates, links or changes an account: Google / Apple identities are
 * looked up read-only in `user_social_accounts` (no e-mail fallback). Only the
 * WEBSITE client ids are accepted (SocialProvider::webAudiences) and a nonce
 * is required for both providers. Dead while the gift switch is off.
 *
 * Logs: user id + outcome only — never an e-mail, password, ID token or token.
 */
class WebParentSessionService
{
    public const TOKEN_NAME = 'web:gift-redeem';

    public const TTL_MINUTES = 15;

    /** Web tokens a user may hold at once (the newest; older ones are deleted at sign-in). */
    public const MAX_TOKENS = 3;

    /** Password failures per account (any IP) in FAILURE_WINDOW_SECONDS → 429 (AppServiceProvider::WEB_SESSION_PASSWORD_FAILURES). */
    public const FAILURE_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly GiftVoucherRedeemService $gifts,
        private readonly SocialTokenVerifier $verifier,
        private readonly FamilyService $families,
    ) {}

    /**
     * @throws WebSessionException
     */
    public function openWithPassword(string $email, string $password): WebSession
    {
        $this->assertAvailable();

        $lockKey = self::passwordFailureKey($email);
        if (RateLimiter::tooManyAttempts($lockKey, AppServiceProvider::WEB_SESSION_PASSWORD_FAILURES)) {
            throw new WebSessionException(WebSessionException::RATE_LIMITED, max(1, RateLimiter::availableIn($lockKey)));
        }

        // As AuthController::login: no hash / '' counts as wrong. An unknown e-mail still
        // runs ONE Hash::check (on a constant precomputed hash) so all three failures cost the same.
        $user = User::findByEmail($email);
        $hash = $user?->getAuthPassword();
        $usable = $user !== null && is_string($hash) && $hash !== '';
        $ok = Hash::check($password, $usable ? $hash : DummyPasswordHash::VALUE) && $usable;

        if (! $ok) {
            // Counted on every failure, unknown e-mails included (no enumeration). A success
            // never clears it (no laundering — like the child PIN lock).
            RateLimiter::hit($lockKey, self::FAILURE_WINDOW_SECONDS);

            throw new WebSessionException(WebSessionException::INVALID_CREDENTIALS);
        }

        // Superadmins may use the password method (parity with /api/login).
        return $this->issue($user, allowSuperadmin: true);
    }

    /**
     * @throws WebSessionException
     */
    public function openWithProvider(SocialProvider $provider, string $idToken, string $rawNonce): WebSession
    {
        $this->assertAvailable();

        try {
            // JWKS HTTP — before any transaction. Website client ids only, nonce for both providers.
            $identity = $this->verifier->verify(
                $provider,
                $idToken,
                $rawNonce,
                audiences: $provider->webAudiences(),
                requireNonce: true,
            );
        } catch (SocialAuthException $e) {
            throw WebSessionException::fromSocial($e);
        }

        // Replay guard: one web session per ID token (kept until the token is dead).
        $exp = $this->expiryOf($idToken);
        $ttl = max(60, $exp - now()->getTimestamp() + SocialTokenVerifier::LEEWAY_SECONDS);
        if (! Cache::add('web_session.id_token:'.hash('sha256', $idToken), 1, $ttl)) {
            Log::info('Web sign-in: ID token replayed', ['provider' => $provider->value]);

            throw new WebSessionException(WebSessionException::INVALID_TOKEN);
        }

        // Read only — never an e-mail fallback, never a link (the app links; the web never writes).
        $account = UserSocialAccount::where('provider', $provider->value)
            ->where('subject', $identity->subject)
            ->first();
        $user = $account !== null ? User::find($account->user_id) : null;
        if ($user === null) {
            throw new WebSessionException(WebSessionException::ACCOUNT_NOT_FOUND);
        }

        // Superadmins never via Google / Apple (parity with SocialSignInService::assertMaySignIn).
        return $this->issue($user, allowSuperadmin: false);
    }

    /**
     * DELETE /api/web/parent-session: deletes the presented token only when it is a valid
     * web token; anything else (none, unknown, expired, an APP token) deletes nothing.
     */
    public function close(?string $bearer): void
    {
        if ($bearer === null || $bearer === '') {
            return;
        }
        $token = PersonalAccessToken::findToken($bearer);
        if ($token instanceof PersonalAccessToken && self::isWebToken($token)
            && $token->expires_at !== null && $token->expires_at->isFuture()) {
            $token->delete();
        }
    }

    /** Rule W (CONTRACT_WEB_SIGNIN §1.6): exactly ["gift:redeem"] — never can(), legacy ['*'] would match. */
    public static function isWebToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && $token->abilities === [TokenAbility::GiftRedeem->value];
    }

    public static function passwordFailureKey(string $email): string
    {
        return 'web-session:pw:'.hash('sha256', mb_strtolower(trim($email)));
    }

    /** Off while the gift switch is off or Lemon Squeezy is not configured — before any credential check. */
    private function assertAvailable(): void
    {
        if (! $this->gifts->available()) {
            throw new WebSessionException(WebSessionException::UNAVAILABLE);
        }
    }

    /**
     * @throws WebSessionException not_parent
     */
    private function issue(User $user, bool $allowSuperadmin): WebSession
    {
        if (! $user->isParent()
            || (! $allowSuperadmin && $user->isSuperadmin())
            || $this->families->familyOf($user) === null) {
            Log::info('Web sign-in refused: not a parent with a family', ['user_id' => $user->id]);

            throw new WebSessionException(WebSessionException::NOT_PARENT);
        }

        $expiresAt = CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);
        $plain = DB::transaction(function () use ($user, $expiresAt): string {
            // QA m4: parallel sign-ins of one user queue here, so the cap holds.
            User::whereKey($user->id)->lockForUpdate()->first();
            $webTokens = $user->tokens()
                ->where('name', self::TOKEN_NAME)
                ->where('abilities', json_encode([TokenAbility::GiftRedeem->value]));
            // Keep the newest MAX_TOKENS − 1 unexpired web tokens; the new one makes MAX_TOKENS.
            $keep = (clone $webTokens)->where('expires_at', '>', now())
                ->orderByDesc('id')->limit(self::MAX_TOKENS - 1)->pluck('id');
            (clone $webTokens)->whereNotIn('id', $keep)->delete();

            return $user->createToken(self::TOKEN_NAME, [TokenAbility::GiftRedeem->value], $expiresAt)->plainTextToken;
        });

        Log::info('Web sign-in: session opened', ['user_id' => $user->id]);

        return new WebSession($plain, $expiresAt);
    }

    /** `exp` of an ID token the verifier has just accepted. */
    private function expiryOf(string $idToken): int
    {
        try {
            $exp = RsaJwt::parse($idToken)->claims['exp'] ?? null;
        } catch (Throwable) {
            $exp = null;
        }

        return is_int($exp) || is_float($exp) ? (int) $exp : now()->getTimestamp() + 3600;
    }
}
