<?php

namespace App\Services\Shop;

use Carbon\CarbonInterface;

/** Public website links used in shop e-mails and PDFs (M6-S09). */
final class ShopLinks
{
    /** `https://petprep.si/sl/unovci`, `https://petprep.si/redeem` … */
    public static function redeemUrl(string $locale): string
    {
        return (string) config('shop.website_url').self::redeemPath($locale);
    }

    /** `petprep.si/sl/unovci` (printed / written form). */
    public static function redeemDisplay(string $locale): string
    {
        return (string) preg_replace('#^https?://#', '', self::redeemUrl($locale));
    }

    public static function redeemPath(string $locale): string
    {
        $slug = (string) (config('shop.redeem_slugs.'.$locale) ?? config('shop.redeem_slugs.en'));

        return ($locale === 'en' ? '' : '/'.$locale).'/'.$slug;
    }

    /** Date in the buyer's language, e.g. »17. 11. 2027« / »17 November 2027«. */
    public static function date(CarbonInterface $date, string $locale): string
    {
        return $date->copy()->locale($locale)->isoFormat('LL');
    }
}
