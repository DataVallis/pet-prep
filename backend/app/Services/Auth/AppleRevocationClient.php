<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP calls to Apple's token endpoints for revocation (M2-10c): form POSTs,
 * 10 s timeouts. Never logs anything — callers log only status + Apple's
 * error code (never the code or a token).
 */
class AppleRevocationClient
{
    public const HTTP_TIMEOUT_SECONDS = 10;

    /** @throws ConnectionException */
    public function exchange(string $clientId, string $clientSecret, string $code): Response
    {
        return $this->post((string) config('services.apple.token_url'), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);
    }

    /** @throws ConnectionException */
    public function revoke(string $clientId, string $clientSecret, string $token, string $hint): Response
    {
        return $this->post((string) config('services.apple.revoke_url'), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'token' => $token,
            'token_type_hint' => $hint,
        ]);
    }

    /** Network-level trouble on Apple's side: worth retrying. */
    public static function isTransient(Response $response): bool
    {
        return $response->serverError() || $response->status() === 429;
    }

    /** Apple's error code (e.g. `invalid_grant`) — a fixed token, safe to log. */
    public static function appleError(Response $response): string
    {
        $error = $response->json('error');

        return is_string($error) && preg_match('/^[a-z_]{1,64}$/', $error) === 1 ? $error : 'unknown';
    }

    /**
     * `sub` of the id_token in Apple's token response, read WITHOUT signature
     * verification — acceptable: the response comes straight from Apple over
     * TLS. Null when absent / unreadable.
     */
    public static function idTokenSubject(Response $response): ?string
    {
        $idToken = $response->json('id_token');
        if (! is_string($idToken)) {
            return null;
        }
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            $claims = json_decode(RsaJwt::base64UrlDecode($parts[1]), true, 16);
        } catch (\Throwable) {
            return null;
        }
        $sub = is_array($claims) ? ($claims['sub'] ?? null) : null;

        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /**
     * @param  array<string, string>  $form
     *
     * @throws ConnectionException
     */
    private function post(string $url, array $form): Response
    {
        return Http::asForm()
            ->acceptJson()
            ->timeout(self::HTTP_TIMEOUT_SECONDS)
            ->connectTimeout(self::HTTP_TIMEOUT_SECONDS)
            ->post($url, $form);
    }
}
