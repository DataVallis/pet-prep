<?php

namespace App\Services\Auth;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-account brute-force lockout of POST /api/login (David 2026-11-11).
 *
 * 5 wrong passwords (AppServiceProvider::LOGIN_PASSWORD_FAILURES) per normalised
 * e-mail within 15 minutes, from any IP, lock the account's password sign-in: every
 * further request — even with the right password — is the standard Laravel throttle
 * 429 (`{message}` + `Retry-After`) until the window ends. A success BEFORE the lock
 * resets the counter. Unknown e-mails are counted too (no account enumeration).
 * The per-IP limit is the `login` route throttle. Nothing personal is logged and the
 * key holds only a hash of the e-mail.
 *
 * Trade-off (accepted): someone who knows an e-mail can lock that account for up to
 * 15 minutes; social sign-in and the child PIN are unaffected.
 */
class PasswordLoginGuard
{
    private static ?string $dummyHash = null;

    /**
     * @throws ThrottleRequestsException
     */
    public function assertNotLocked(string $email): void
    {
        $key = self::key($email);
        if (RateLimiter::tooManyAttempts($key, AppServiceProvider::LOGIN_PASSWORD_FAILURES)) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $wait,
                'X-RateLimit-Limit' => AppServiceProvider::LOGIN_PASSWORD_FAILURES,
                'X-RateLimit-Remaining' => 0,
            ]);
        }
    }

    public function recordFailure(string $email): void
    {
        RateLimiter::hit(self::key($email), AppServiceProvider::LOGIN_FAILURE_WINDOW_SECONDS);
    }

    public function clear(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }

    /** Bcrypt hash of a random value, for the constant-time path of unknown e-mails. */
    public function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16)));
    }

    public static function key(string $email): string
    {
        return 'login:pw:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
