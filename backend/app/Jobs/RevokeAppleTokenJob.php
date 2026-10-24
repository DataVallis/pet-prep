<?php

namespace App\Jobs;

use App\Services\Auth\AppleClientSecret;
use App\Services\Auth\AppleRevocationClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Revoke the Sign in with Apple tokens of a deleted parent account (M2-10c,
 * App Store guideline 5.1.1(v)). Queue `auth` (worker: `--queue=auth,default,alerts`).
 *
 * Dispatched after the deletion commits, only for a parent with a linked
 * Apple identity whose app sent the sign-in's `authorization_code`:
 * client_secret (ES256) → POST /auth/token → check that the returned
 * id_token's `sub` is the deleted parent's Apple subject → POST /auth/revoke
 * (refresh_token, else access_token).
 *
 * - client_id: APPLE_CLIENT_ID (override) › `aud` of the Apple token verified
 *   for the deletion › bundle id.
 * - No key → warning, no HTTP. `invalid_grant` (code > 5 min / used) → info,
 *   final. Any other refusal (invalid_client, unauthorized_client, other 4xx,
 *   subject mismatch) → error, final.
 * - Exchange: network / 5xx / 429 → the job throws and is retried (3 tries,
 *   5 / 20 s — the code lives 5 minutes).
 * - Revoke: retried in place 3× (the code is single-use); still failing →
 *   RevokeAppleRefreshTokenJob takes the token over (longer backoff).
 *
 * The code and tokens are credentials: encrypted payload, never logged,
 * never in an exception message. No user id — the account is gone.
 */
class RevokeAppleTokenJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'auth';

    public const REVOKE_ATTEMPTS = 3;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public function __construct(
        private readonly string $authorizationCode,
        /** `aud` of the Apple token verified for the deletion (null → default). */
        private readonly ?string $tokenAudience = null,
        /** The deleted parent's Apple `sub` (null → not compared). */
        private readonly ?string $expectedSubject = null,
    ) {}

    public function handle(AppleClientSecret $secrets, AppleRevocationClient $apple): void
    {
        if (! $secrets->isConfigured()) {
            Log::warning('Apple token revocation skipped: Sign in with Apple key is not configured.');

            return;
        }

        $clientId = $secrets->clientId($this->tokenAudience);
        try {
            $clientSecret = $secrets->make($clientId);
        } catch (Throwable) {
            Log::error('Apple token revocation skipped: could not build the client secret.');

            return;
        }

        $exchange = $apple->exchange($clientId, $clientSecret, $this->authorizationCode);

        if (! $exchange->successful()) {
            if (AppleRevocationClient::isTransient($exchange)) {
                throw new RuntimeException("Apple token exchange failed (HTTP {$exchange->status()}).");
            }
            $error = AppleRevocationClient::appleError($exchange);
            $context = ['status' => $exchange->status(), 'error' => $error];
            $error === 'invalid_grant'
                ? Log::info('Apple token revocation stopped: authorization code expired or already used.', $context)
                : Log::error('Apple token revocation stopped: token exchange refused.', $context);

            return;
        }

        if ($this->expectedSubject !== null && AppleRevocationClient::idTokenSubject($exchange) !== $this->expectedSubject) {
            Log::error('Apple token revocation stopped: the code belongs to another Apple account.');

            return;
        }

        $refresh = $exchange->json('refresh_token');
        $access = $exchange->json('access_token');
        [$token, $hint] = is_string($refresh) && $refresh !== ''
            ? [$refresh, 'refresh_token']
            : (is_string($access) && $access !== '' ? [$access, 'access_token'] : [null, null]);

        if ($token === null) {
            Log::error('Apple token revocation stopped: no token in the exchange response.');

            return;
        }

        $revoke = null;
        for ($attempt = 1; $attempt <= self::REVOKE_ATTEMPTS; $attempt++) {
            try {
                $revoke = $apple->revoke($clientId, $clientSecret, $token, $hint);
            } catch (ConnectionException) {
                $revoke = null;
            }
            if ($revoke instanceof Response && ! AppleRevocationClient::isTransient($revoke)) {
                break;
            }
            if ($attempt < self::REVOKE_ATTEMPTS) {
                Sleep::for(500 * $attempt)->milliseconds();
            }
        }

        if ($revoke instanceof Response && $revoke->successful()) {
            Log::info('Apple tokens revoked after account deletion.');

            return;
        }

        if ($revoke instanceof Response && ! AppleRevocationClient::isTransient($revoke)) {
            Log::error('Apple token revocation refused.', ['status' => $revoke->status(), 'error' => AppleRevocationClient::appleError($revoke)]);

            return;
        }

        // Apple unreachable for now: hand the token over to a slower retry.
        Log::warning('Apple token revocation postponed: revoke endpoint unavailable, retrying later.', ['status' => $revoke?->status()]);
        RevokeAppleRefreshTokenJob::dispatch($token, $hint, $clientId)->onQueue(self::QUEUE);
    }
}
