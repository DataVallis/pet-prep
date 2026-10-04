<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
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
