<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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

        // M2-02: Caddy (same Docker network) is the only proxy. Trust it —
        // and only private / loopback peers — so $request->ip() is the real
        // client (per-IP PIN-login limits). Caddy overwrites X-Forwarded-For
        // with {remote_host}; a direct public hit can't spoof it.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env(
                'TRUSTED_PROXIES',
                '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
            ))))),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

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
