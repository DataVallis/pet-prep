<?php

namespace App\Services\Shop;

use App\Models\ShopOrder;
use App\Support\WebsiteLocale;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Files sold in the shop (M6-S09, spec §3.4 / §4.2): `<files_dir>/<product>/<locale>.pdf`
 * on the private `shop.files_disk`. Uploaded by a superadmin (Shop products) or
 * `shop:import-files`. Handed out only as signed, expiring links of a paid order.
 */
class ShopFiles
{
    public static function path(string $productKey, string $locale): string
    {
        return config('shop.files_dir').'/'.$productKey.'/'.$locale.'.pdf';
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('shop.files_disk'));
    }

    public static function exists(string $productKey, string $locale): bool
    {
        return self::disk()->exists(self::path($productKey, $locale));
    }

    /** @return list<string> locales that have a file */
    public static function locales(string $productKey): array
    {
        return array_values(array_filter(WebsiteLocale::ALL, fn (string $l): bool => self::exists($productKey, $l)));
    }

    /** Which product's files an order may download (the gift includes the kit). */
    public static function productFor(ShopOrder $order): ?string
    {
        $product = (array) config('shop.products.'.$order->product_key);

        return match (true) {
            isset($product['includes_files_of']) => (string) $product['includes_files_of'],
            $order->product_key === ShopOrder::PRODUCT_PROMISE_KIT => ShopOrder::PRODUCT_PROMISE_KIT,
            default => null,
        };
    }

    /** The free promise kit sample (spec §4.2): product key of its files. */
    public const SAMPLE = 'promise_kit_sample';

    /** Signed 24 h link to the sample in a language (English fallback), null when none is uploaded. */
    public static function sampleUrl(string $locale): ?string
    {
        $locale = self::exists(self::SAMPLE, $locale) ? $locale : 'en';
        if (! self::exists(self::SAMPLE, $locale)) {
            return null;
        }

        return URL::temporarySignedRoute('shop.sample', now()->addHours((int) config('shop.download_link_hours', 24)), ['locale' => $locale]);
    }

    /**
     * Signed links of an order's files: the buyer's language first, then English, then the rest.
     *
     * @return list<array{locale: string, url: string}>
     */
    public static function links(ShopOrder $order, int $minutes, bool $all = true): array
    {
        $product = self::productFor($order);
        if ($product === null || ! $order->delivers()) {
            return [];
        }
        $have = self::locales($product);
        $ordered = array_values(array_unique(array_merge([$order->locale, 'en'], $all ? $have : [])));
        $links = [];
        foreach ($ordered as $locale) {
            if (! in_array($locale, $have, true)) {
                continue;
            }
            $links[] = [
                'locale' => $locale,
                'url' => URL::temporarySignedRoute('shop.download', now()->addMinutes($minutes), [
                    'order' => $order->id, 'locale' => $locale,
                ]),
            ];
        }

        return $links;
    }
}
