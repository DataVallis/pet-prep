<?php

namespace App\Http\Controllers\Admin;

use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;

/**
 * GET /admin/locale/{locale} (route `filament.admin.locale`, superadmin
 * panel only): remembers the panel language in a cookie and returns to the
 * page the admin came from (only an /admin page — never an outside URL).
 */
class SwitchAdminLocaleController
{
    public function __invoke(string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, (array) config('locales.supported'), true), 404);

        $admin = url('/admin');
        $back = URL::previous($admin);
        $target = ($back === $admin || str_starts_with($back, $admin.'/')) && ! str_contains($back, '/admin/locale/') ? $back : $admin;

        return redirect()->to($target)->withCookie(Cookie::forever(SetAdminLocale::COOKIE, $locale));
    }
}
