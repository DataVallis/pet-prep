<?php

use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\TrustProxies;
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

        // API guests get a JSON 401 (see withExceptions), not a redirect to a
        // `login` route this app doesn't have. Filament has its own login.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : url('/admin/login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API clients (pusher-js' channel authorizer among them) don't always
        // send `Accept: application/json`; a guest must still get a JSON 401,
        // never a redirect to a web login route that doesn't exist.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
