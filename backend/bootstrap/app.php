<?php

use App\Http\Middleware\RecordClientFeatures;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\TrustProxies;
use App\Services\SystemHealth\ErrorRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // M1-08: channel auth for the mobile apps with the Sanctum bearer token —
    // POST /api/broadcasting/auth (no session / CSRF). Only private channels.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'throttle:api']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // M2-03: Sanctum token abilities. `ability:parent` / `ability:child`
        // on the route groups; tokens from before M2-02 have ['*'] and pass.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // M2-02: trust Caddy (private / loopback peers) so $request->ip() is
        // the real client (per-IP PIN-login limits). The list lives in
        // config/trustedproxy.php (env TRUSTED_PROXIES), read per request.
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);

        // M1-18: Accept-Language → App::setLocale() for every API request
        // (also /api/broadcasting/auth, which uses the `api` group).
        $middleware->api(prepend: [SetRequestLocale::class]);

        // M5-R14 – R20 follow-up: `X-PetPrep-Features` → users.client_features
        // (what the app on that phone can show now; existing dogs' care tasks).
        $middleware->api(append: [RecordClientFeatures::class]);

        // M5-R08: the pet name has its own trimming (PetNameService::normalize).
        // Laravel's TrimStrings would silently strip a leading / trailing BOM,
        // bidi mark or Hangul filler that the name rules reject (name_invalid),
        // like the app does.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/parent/pets/*/name'),
        ]);

        // API guests get a JSON 401 (see withExceptions), not a redirect to a
        // `login` route this app doesn't have. Filament has its own login.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : url('/admin/login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // M2-10c: provider tokens / codes are credentials — never flashed to a session.
        $exceptions->dontFlash(['id_token', 'nonce', 'authorization_code']);

        // System health (David 2026-10-19): every reported exception (uncaught
        // HTTP / command / job errors and every report($e)) is also stored in
        // `system_errors` for /admin/system-health. Never throws; logging
        // continues as before (the callback returns nothing).
        $exceptions->report(function (Throwable $e): void {
            app(ErrorRecorder::class)->recordException($e);
        });

        // API clients (pusher-js' channel authorizer among them) don't always
        // send `Accept: application/json`; a guest must still get a JSON 401,
        // never a redirect to a web login route that doesn't exist.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
