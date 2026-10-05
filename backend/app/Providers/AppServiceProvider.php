<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * POST /api/child/pin-login requests per IP per minute (M2-02).
     */
    public const PIN_LOGIN_PER_MINUTE = 10;

    /**
     * POST /api/register requests per IP (M2-10a).
     */
    public const REGISTER_PER_MINUTE = 5;

    public const REGISTER_PER_HOUR = 20;

    /**
     * Data exports per user per hour (M2-08).
     */
    public const ACCOUNT_EXPORT_PER_HOUR = 3;

    /**
     * Minimum password length for parent accounts (M2-10a).
     */
    public const PASSWORD_MIN_LENGTH = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // No broadcasting model observers (M1-08): every PetUpdated is
        // emitted explicitly by the service that made the change, via
        // PetUpdated::afterCommit() — exactly one per state change.

        // Parent passwords (M2-10a): ≥ 10 characters, upper + lower case and
        // a digit. Deliberately no ->uncompromised(): it calls the external
        // HIBP API (no external HTTP during sign-up — DECISIONS 2026-10-05).
        Password::defaults(fn () => Password::min(self::PASSWORD_MIN_LENGTH)->mixedCase()->numbers());

        // Configure rate limiters (per Phase 7 engineering standards)
        // In testing, rate limits are set to unlimited to avoid
        // interfering with functional test assertions.
        RateLimiter::for('api', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Child care actions (M1-07): a child taps a few buttons a day and the
        // phone syncs steps every few minutes; 30/min per user is generous
        // and still stops a scripted loop.
        // Signed pet media (M4-05): players issue several range requests per
        // video; generous per-IP limit, still bounded.
        RateLimiter::for('media', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(240)->by('media:'.ClientIp::rateLimitKey($request->ip()));
        });

        RateLimiter::for('child-actions', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('pairing', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // PIN-only child login (M2-02): unauthenticated, 6-digit space →
        // hard per-IP limit. Active in testing too (the suite strips the
        // throttle middleware; the limit test re-enables it). Failed
        // attempts are limited separately (per IP + global) in
        // ChildPinLoginService.
        RateLimiter::for('pin-login', function (Request $request) {
            // IPv6 keyed on its /64 prefix (ClientIp).
            return Limit::perMinute(self::PIN_LOGIN_PER_MINUTE)->by('pin-login:'.ClientIp::rateLimitKey($request->ip()));
        });

        // Parent self-registration (M2-10a): unauthenticated, creates rows →
        // 5 per minute and 20 per hour per IP (IPv6 /64). Live in testing too
        // (like pin-login); tests that register more often vary the IP.
        RateLimiter::for('register', function (Request $request) {
            $key = 'register:'.ClientIp::rateLimitKey($request->ip());

            return [
                Limit::perMinute(self::REGISTER_PER_MINUTE)->by($key.':minute'),
                Limit::perHour(self::REGISTER_PER_HOUR)->by($key.':hour'),
            ];
        });

        // Second-parent invite codes (M2-01): a parent needs one or two;
        // 10 per hour per account stops code farming. Wrong codes on
        // join-family are limited separately in FamilyInviteService.
        // Data export (M2-08): builds the whole family synchronously → 3 per
        // hour per user. Live in testing.
        RateLimiter::for('account-export', function (Request $request) {
            return Limit::perHour(self::ACCOUNT_EXPORT_PER_HOUR)
                ->by('account-export:'.($request->user()?->id ?: ClientIp::rateLimitKey($request->ip())));
        });

        RateLimiter::for('family-invites', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perHour(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}
