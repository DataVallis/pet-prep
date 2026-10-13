<?php

namespace App\Jobs;

use App\Services\Auth\AppleClientSecret;
use App\Services\Auth\AppleRevocationClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Follow-up of RevokeAppleTokenJob (M2-10c): the code was already exchanged,
 * only /auth/revoke kept failing (network / 5xx / 429). Carries the Apple
 * refresh (or access) token — encrypted payload, never logged. Queue `auth`,
 * 5 tries with backoff 1, 5, 15, 60 minutes; a non-transient refusal is final
 * (error log); exhausting the tries logs an error (failed()).
 */
class RevokeAppleRefreshTokenJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        private readonly string $token,
        /** `refresh_token` | `access_token` */
        private readonly string $tokenTypeHint,
        private readonly string $clientId,
    ) {}

    public function handle(AppleClientSecret $secrets, AppleRevocationClient $apple): void
    {
        if (! $secrets->isConfigured()) {
            Log::warning('Apple token revocation skipped: Sign in with Apple key is not configured.');

            return;
        }

        $response = $apple->revoke($this->clientId, $secrets->make($this->clientId), $this->token, $this->tokenTypeHint);

        if ($response->successful()) {
            Log::info('Apple tokens revoked after account deletion (delayed).');

            return;
        }
        if (AppleRevocationClient::isTransient($response)) {
            throw new RuntimeException("Apple token revoke failed (HTTP {$response->status()}).");
        }

        Log::error('Apple token revocation refused.', ['status' => $response->status(), 'error' => AppleRevocationClient::appleError($response)]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Apple token revocation failed for good after retries.');
    }
}
