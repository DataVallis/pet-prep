<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies fal.ai webhook signatures (ED25519).
 *
 * fal.ai signs every webhook with its private key. The signed message is:
 *
 *   X-Fal-Webhook-Request-Id \n X-Fal-Webhook-User-Id \n X-Fal-Webhook-Timestamp \n hex(sha256(raw body))
 *
 * and the hex-encoded signature is sent in X-Fal-Webhook-Signature. Public keys
 * are published as a JWKS (base64url `x` field per key) and cached for 24 h.
 *
 * There is no shared secret — the verifier fails closed whenever a header is
 * missing, the timestamp is outside the tolerance, or no key validates.
 *
 * @see https://fal.ai/docs/model-apis/model-endpoints/webhooks
 */
class FalWebhookVerifier
{
    public const CACHE_KEY = 'fal_ai.webhook_jwks';

    public const CACHE_TTL_SECONDS = 86400;

    /** At most one JWKS fetch per this many seconds (prevents forced re-fetch abuse). */
    public const REFRESH_LOCK_KEY = 'fal_ai.webhook_jwks_refresh_lock';

    public const REFRESH_INTERVAL_SECONDS = 60;

    public function verify(Request $request): bool
    {
        $requestId = (string) $request->header('X-Fal-Webhook-Request-Id', '');
        $userId = (string) $request->header('X-Fal-Webhook-User-Id', '');
        $timestamp = (string) $request->header('X-Fal-Webhook-Timestamp', '');
        $signatureHex = (string) $request->header('X-Fal-Webhook-Signature', '');

        if ($requestId === '' || $userId === '' || $timestamp === '' || $signatureHex === '') {
            return $this->reject('missing signature headers');
        }

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > $this->tolerance()) {
            return $this->reject('timestamp outside tolerance', ['timestamp' => $timestamp]);
        }

        if (! ctype_xdigit($signatureHex) || strlen($signatureHex) !== SODIUM_CRYPTO_SIGN_BYTES * 2) {
            return $this->reject('malformed signature');
        }

        $signature = hex2bin($signatureHex);
        $message = implode("\n", [
            $requestId,
            $userId,
            $timestamp,
            hash('sha256', $request->getContent()),
        ]);

        $cached = $this->publicKeys();

        if ($this->matchesAnyKey($message, $signature, $cached)) {
            return true;
        }

        // Keys may have rotated since we cached them — refresh (rate-limited) and retry.
        $refreshed = $this->refreshPublicKeys();

        if ($refreshed !== null && $this->matchesAnyKey($message, $signature, $refreshed)) {
            return true;
        }

        return $this->reject('signature mismatch', ['request_id' => $requestId]);
    }

    /**
     * @param  list<string>  $keys  raw 32-byte ED25519 public keys
     */
    private function matchesAnyKey(string $message, string $signature, array $keys): bool
    {
        foreach ($keys as $key) {
            try {
                if (sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                    return true;
                }
            } catch (Throwable) {
                // Invalid key length etc. — try the next key.
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function publicKeys(): array
    {
        $keys = Cache::get(self::CACHE_KEY);

        if (is_array($keys) && $keys !== []) {
            return array_map('base64_decode', $keys);
        }

        return $this->refreshPublicKeys() ?? [];
    }

    /**
     * Fetch the JWKS, at most once per REFRESH_INTERVAL_SECONDS across all requests.
     *
     * @return list<string>|null null when a refresh happened too recently
     */
    private function refreshPublicKeys(): ?array
    {
        if (! Cache::add(self::REFRESH_LOCK_KEY, true, self::REFRESH_INTERVAL_SECONDS)) {
            return null;
        }

        $fetched = $this->fetchPublicKeys();

        if ($fetched !== []) {
            // Store base64 so any cache driver can serialise the binary keys safely.
            Cache::put(self::CACHE_KEY, array_map('base64_encode', $fetched), self::CACHE_TTL_SECONDS);
        }

        return $fetched;
    }

    /**
     * @return list<string>
     */
    private function fetchPublicKeys(): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get($this->jwksUrl());
        } catch (Throwable $e) {
            Log::error('FalWebhookVerifier: could not fetch JWKS', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::error('FalWebhookVerifier: JWKS request failed', ['status' => $response->status()]);

            return [];
        }

        $keys = [];
        foreach ((array) $response->json('keys', []) as $jwk) {
            $x = is_array($jwk) ? ($jwk['x'] ?? null) : null;
            if (! is_string($x) || $x === '') {
                continue;
            }

            $raw = base64_decode(strtr($x, '-_', '+/'), true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $raw;
            }
        }

        return $keys;
    }

    private function jwksUrl(): string
    {
        return (string) config('services.fal_ai.jwks_url', 'https://rest.fal.ai/.well-known/jwks.json');
    }

    private function tolerance(): int
    {
        return (int) config('services.fal_ai.webhook_tolerance_seconds', 300);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(string $reason, array $context = []): bool
    {
        // info, not warning: unauthenticated callers must not be able to flood alerting.
        Log::info("FalWebhookVerifier: rejected webhook ({$reason})", $context);

        return false;
    }
}
