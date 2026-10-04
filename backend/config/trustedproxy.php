<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies (M2-02)
|--------------------------------------------------------------------------
|
| Caddy (same Docker network) is the only proxy in production. Requests
| from these peers may set X-Forwarded-For / -Proto, so $request->ip() is
| the real client (per-IP PIN-login limits). Caddy overwrites
| X-Forwarded-For with {remote_host}; a direct public hit can't spoof it
| because only Caddy publishes ports. Read by App\Http\Middleware\TrustProxies.
|
*/

return [
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
    ))),
];
