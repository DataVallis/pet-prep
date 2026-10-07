<?php

namespace App\Http\Middleware;

use App\Support\RequestLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * API group (M1-18): the request's language from `Accept-Language`
 * (supported list in config/locales.php, default English) becomes the app
 * locale for translated server texts (__()). Always set — also to the
 * default — so nothing leaks from an earlier request in a long-lived
 * worker. The answer carries `Content-Language` and `Vary: Accept-Language`.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = RequestLocale::resolve($request->header('Accept-Language'));
        App::setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
