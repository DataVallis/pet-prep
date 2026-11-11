<?php

namespace App\Support;

/**
 * The 15 languages of petprep.si (M6-S01b, CONTRACT_WEB_SIGNIN §4) — the
 * website's `src/i18n/config.ts` `locales`. Accepted as `locale` by the website
 * endpoints (POST/DELETE /api/web/parent-session, POST /api/gift/redeem).
 *
 * The server itself only speaks en / sl (config/locales.php stays ['en', 'sl']):
 * `message` texts use messageLocale() — never App::setLocale('fi') etc. The
 * website maps the `error` codes to its own texts.
 */
final class WebsiteLocale
{
    public const ALL = ['en', 'sl', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'cs', 'hr', 'sv', 'da', 'fi', 'nb'];

    /** The server language of the `message` text: sl / en as asked, anything else → en. */
    public static function messageLocale(mixed $locale): string
    {
        return in_array($locale, ['sl', 'en'], true) ? $locale : 'en';
    }

    public static function apply(mixed $locale): void
    {
        app()->setLocale(self::messageLocale($locale));
    }
}
