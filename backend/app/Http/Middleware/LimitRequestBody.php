<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a request body larger than `$maxBytes` with 413 before it is parsed
 * (M5-05 QA #375 m5: `POST /api/app-errors` is public). Checks the declared
 * Content-Length and the body actually received (chunked uploads have none).
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, string $maxBytes): Response
    {
        $max = (int) $maxBytes;
        $declared = $request->headers->get('Content-Length');
        if (($declared !== null && (int) $declared > $max) || strlen((string) $request->getContent()) > $max) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        return $next($request);
    }
}
