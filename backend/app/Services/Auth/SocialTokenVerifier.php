<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\SocialAuthException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;
use Throwable;
use UnexpectedValueException;

/**
 * Verifies a Google / Apple ID token from the native sign-in SDKs (M2-10c).
 *
 * Checks: RS256 signature against the provider's JWKS (cached; one forced
 * re-fetch per minute when an unknown `kid` shows up — key rotation), `iss`,
 * `aud` ∈ our configured client IDs, `exp` / `nbf` / `iat` with 60 s leeway,
 * a `sub`, and for Apple `nonce` = sha256(raw nonce) (hex, lower case).
 *
 * Makes external HTTP (the JWKS) — call it before any DB transaction.
 * Failures: 401 `invalid_token`, 503 `provider_disabled` (no client ID
 * configured), 503 `provider_unavailable` (JWKS unreachable).
 */
class SocialTokenVerifier
{
    public const LEEWAY_SECONDS = 60;

    public const HTTP_TIMEOUT_SECONDS = 5;

    /** Without a usable `Cache-Control: max-age` the JWKS is kept this long. */
    public const DEFAULT_JWKS_TTL_SECONDS = 3600;

    public const MIN_JWKS_TTL_SECONDS = 300;

    public const MAX_JWKS_TTL_SECONDS = 86400;

    /** Unknown `kid`: at most one forced JWKS fetch per provider per this many seconds. */
    public const REFRESH_INTERVAL_SECONDS = 60;

    /**
     * @param  string|null  $rawNonce  Apple: the raw nonce whose SHA-256 the app passed to Apple
     *
     * @throws SocialAuthException
     */
    public function verify(SocialProvider $provider, string $idToken, ?string $rawNonce = null): SocialIdentity
    {
        if (! $provider->isEnabled()) {
            throw SocialAuthException::providerDisabled();
        }

        try {
            $jwt = RsaJwt::parse($idToken);
        } catch (UnexpectedValueException $e) {
            throw $this->reject($provider, 'malformed token: '.$e->getMessage());
        }

        $key = $this->keyFor($provider, $jwt->kid);
        if ($key === null) {
            throw $this->reject($provider, 'unknown kid');
        }
        if (! $jwt->verifiesWith($key)) {
            throw $this->reject($provider, 'bad signature');
        }

        return $this->identityFromClaims($provider, $jwt->claims, $rawNonce);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function identityFromClaims(SocialProvider $provider, array $claims, ?string $rawNonce): SocialIdentity
    {
        $now = now()->getTimestamp();

        $iss = $claims['iss'] ?? null;
        if (! is_string($iss) || ! in_array($iss, $provider->issuers(), true)) {
            throw $this->reject($provider, 'iss');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_string($aud) ? [$aud] : (is_array($aud) ? array_filter($aud, 'is_string') : []);
        if (array_intersect($audiences, $provider->audiences()) === []) {
            throw $this->reject($provider, 'aud');
        }

        $exp = $claims['exp'] ?? null;
        if (! is_int($exp) && ! is_float($exp)) {
            throw $this->reject($provider, 'exp missing');
        }
        if ($exp + self::LEEWAY_SECONDS <= $now) {
            throw $this->reject($provider, 'expired');
        }

        $iat = $claims['iat'] ?? null;
        if (! is_int($iat) && ! is_float($iat)) {
            throw $this->reject($provider, 'iat missing');
        }
        if ($iat > $now + self::LEEWAY_SECONDS) {
            throw $this->reject($provider, 'iat in the future');
        }

        $nbf = $claims['nbf'] ?? null;
        if ($nbf !== null && (! (is_int($nbf) || is_float($nbf)) || $nbf > $now + self::LEEWAY_SECONDS)) {
            throw $this->reject($provider, 'nbf');
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 255) {
            throw $this->reject($provider, 'sub');
        }

        if ($provider->requiresNonce()) {
            $nonce = $claims['nonce'] ?? null;
            if ($rawNonce === null || $rawNonce === '' || ! is_string($nonce)
                || ! hash_equals(hash('sha256', $rawNonce), strtolower($nonce))) {
                throw $this->reject($provider, 'nonce');
            }
        }

        $email = $claims['email'] ?? null;
        $email = is_string($email) && trim($email) !== '' && strlen($email) <= 255
            && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false
            ? mb_strtolower(trim($email))
            : null;

        // Google: boolean; Apple: boolean or the string "true".
        $verified = $claims['email_verified'] ?? null;
        $emailVerified = $email !== null && ($verified === true || $verified === 'true');
        $emailLinkable = $emailVerified && match ($provider) {
            SocialProvider::Apple => true,
            SocialProvider::Google => self::isGoogleAuthoritative((string) $email, $claims['hd'] ?? null),
        };

        $name = $claims['name'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        return new SocialIdentity(
            provider: $provider,
            subject: $sub,
            email: $email,
            emailVerified: $emailVerified,
            emailLinkable: $emailLinkable,
            name: $name,
            issuedAt: CarbonImmutable::createFromTimestampUTC((int) $iat),
        );
    }

    /**
     * Google is authoritative for Gmail addresses and for Workspace accounts
     * (`hd` claim present); for any other verified address it only says the
     * address was verified once, not that this Google user still owns it.
     */
    public static function isGoogleAuthoritative(string $email, mixed $hd): bool
    {
        if (is_string($hd) && trim($hd) !== '') {
            return true;
        }

        return str_ends_with($email, '@gmail.com') || str_ends_with($email, '@googlemail.com');
    }

    private function reject(SocialProvider $provider, string $why): SocialAuthException
    {
        // Never log the token or its claims (personal data).
        Log::info('Social sign-in token rejected', ['provider' => $provider->value, 'why' => $why]);

        return SocialAuthException::invalidToken();
    }

    /**
     * The provider's key for `kid`: cached JWKS first; an unknown kid forces
     * one re-fetch (rate-limited per provider).
     *
     * @throws SocialAuthException provider_unavailable
     */
    private function keyFor(SocialProvider $provider, string $kid): ?OpenSSLAsymmetricKey
    {
        $cached = Cache::get($this->cacheKey($provider));
        $jwks = is_array($cached) ? $cached : null;

        if ($jwks === null) {
            $jwks = $this->fetchAndCache($provider);
            Cache::put($this->refreshLockKey($provider), true, self::REFRESH_INTERVAL_SECONDS);
        } elseif (! isset($jwks[$kid]) && Cache::add($this->refreshLockKey($provider), true, self::REFRESH_INTERVAL_SECONDS)) {
            $jwks = $this->fetchAndCache($provider);
        }

        $pem = $jwks[$kid] ?? null;
        if (! is_string($pem)) {
            return null;
        }

        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /**
     * @return array<string, string> kid → PEM
     *
     * @throws SocialAuthException provider_unavailable
     */
    private function fetchAndCache(SocialProvider $provider): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($provider->jwksUrl());
        } catch (Throwable $e) {
            Log::warning('Social sign-in: JWKS fetch failed', ['provider' => $provider->value, 'error' => $e->getMessage()]);

            throw SocialAuthException::providerUnavailable();
        }

        if (! $response->successful()) {
            Log::warning('Social sign-in: JWKS request failed', ['provider' => $provider->value, 'status' => $response->status()]);

            throw SocialAuthException::providerUnavailable();
        }

        $keys = [];
        foreach ((array) $response->json('keys', []) as $jwk) {
            if (! is_array($jwk) || ! is_string($jwk['kid'] ?? null) || $jwk['kid'] === '') {
                continue;
            }
            $key = RsaJwt::publicKeyFromJwk($jwk);
            $details = $key !== null ? openssl_pkey_get_details($key) : false;
            if (is_array($details) && is_string($details['key'] ?? null)) {
                $keys[$jwk['kid']] = $details['key'];
            }
        }

        if ($keys === []) {
            Log::warning('Social sign-in: JWKS without usable keys', ['provider' => $provider->value]);

            throw SocialAuthException::providerUnavailable();
        }

        Cache::put($this->cacheKey($provider), $keys, $this->ttlFrom($response));

        return $keys;
    }

    private function ttlFrom(Response $response): int
    {
        $header = (string) $response->header('Cache-Control');
        if (preg_match('/(?:^|[,\s])max-age=(\d+)/i', $header, $m) === 1) {
            return max(self::MIN_JWKS_TTL_SECONDS, min(self::MAX_JWKS_TTL_SECONDS, (int) $m[1]));
        }

        return self::DEFAULT_JWKS_TTL_SECONDS;
    }

    private function cacheKey(SocialProvider $provider): string
    {
        return 'social_auth.jwks.'.$provider->value;
    }

    private function refreshLockKey(SocialProvider $provider): string
    {
        return 'social_auth.jwks_refresh_lock.'.$provider->value;
    }
}
