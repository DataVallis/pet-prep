<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Language of the superadmin panel (/admin, David 2026-10-11): English by
 * default, Slovenian on request. The choice is a cookie set by the switcher
 * route `filament.admin.locale` (top bar + user menu), so it survives
 * sign-out and is per browser. Only languages in config/locales.php count.
 * Runs inside the panel's middleware stack (after EncryptCookies), so the
 * cookie is encrypted and can't be forged into an unsupported value.
 */
class SetAdminLocale
{
    public const COOKIE = 'petprep_admin_locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(self::resolve($request->cookie(self::COOKIE)));

        return $next($request);
    }

    public static function resolve(mixed $value): string
    {
        /** @var list<string> $supported */
        $supported = config('locales.supported', ['en']);
        $default = (string) config('locales.default', 'en');

        return is_string($value) && in_array($value, $supported, true) ? $value : $default;
    }
}
