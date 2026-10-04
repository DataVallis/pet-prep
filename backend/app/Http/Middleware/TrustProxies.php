<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trusted proxies from config/trustedproxy.php (M2-02) — read at request
 * time via config(), so `config:cache` and tests see the configured value.
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return static::$alwaysTrustProxies ?: config('trustedproxy.proxies', []);
    }
}
