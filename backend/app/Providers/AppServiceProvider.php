<?php

namespace App\Providers;

use App\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * POST /api/child/pin-login requests per IP per minute (M2-02).
     */
    public const PIN_LOGIN_PER_MINUTE = 10;

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

        // Second-parent invite codes (M2-01): a parent needs one or two;
        // 10 per hour per account stops code farming. Wrong codes on
        // join-family are limited separately in FamilyInviteService.
        RateLimiter::for('family-invites', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perHour(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}
