<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\Pet;
use App\Observers\ActivityLogObserver;
use App\Observers\PetObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
        // Register model observers for real-time broadcasting
        Pet::observe(PetObserver::class);
        ActivityLog::observe(ActivityLogObserver::class);

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
    }
}
