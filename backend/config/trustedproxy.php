<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies (M2-02)
|--------------------------------------------------------------------------
|
| Caddy (same Docker network) is the only proxy in production. Since
| M4-05b it talks FastCGI to PHP-FPM (php_fastcgi): REMOTE_ADDR is already
| the client address Caddy sees, and Caddy forwards X-Forwarded-For /
| -Proto (overwritten with that same client, Caddy has no trusted_proxies)
| as HTTP_X_FORWARDED_* params plus HTTPS=on. A public REMOTE_ADDR is not in
| this list, so X-Forwarded-For is ignored and $request->ip() = REMOTE_ADDR;
| a private one (e.g. Docker's userland proxy, a host-local health check)
| trusts Caddy's X-Forwarded-For — the same value. Either way per-IP limits
| (PIN login, webhooks, media) key on the real client, and nobody can spoof
| it: only Caddy publishes ports. Changing TRUSTED_PROXIES needs the PHP
| containers recreated (config is cached per container on start).
| Read by App\Http\Middleware\TrustProxies.
|
*/

return [
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')),
    ))),
];
