<?php

namespace App\Services\Shop;

use App\Services\AppSettingsService;
use App\Support\WebsiteLocale;

/**
 * POST /api/shop/checkout (M6-S09, spec §3.4): one Stripe Checkout Session with
 * Managed Payments for one product. The website owns its (localised) paths and sends
 * them; we only ever redirect to config('shop.website_url') + a validated path.
 */
class ShopCheckoutService
{
    public const ERROR_UNKNOWN_PRODUCT = 'unknown_product';

    public const ERROR_UNAVAILABLE = 'unavailable';

    public const ERROR_UPSTREAM = 'upstream_error';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly AppSettingsService $settings,
    ) {}

    /** Is the product sellable right now (configured + its admin switch on)? */
    public function available(string $productKey): bool
    {
        return $this->stripe->configured()
            && (string) config('shop.products.'.$productKey.'.price_id') !== ''
            && $this->settings->shopProductEnabled($productKey, fresh: true);
    }

    /**
     * @return array{url: string}|array{error: string}
     */
    public function create(string $productKey, string $locale, string $successPath, string $cancelPath): array
    {
        if (! is_array(config('shop.products.'.$productKey))) {
            return ['error' => self::ERROR_UNKNOWN_PRODUCT];
        }
        if (! $this->available($productKey)) {
            return ['error' => self::ERROR_UNAVAILABLE];
        }
        $locale = self::locale($locale);
        $base = (string) config('shop.website_url');
        $success = $base.$successPath.(str_contains($successPath, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
        $brand = (array) config('shop.branding');

        try {
            $session = $this->stripe->createCheckoutSession([
                'mode' => 'payment',
                'line_items' => [['price' => (string) config('shop.products.'.$productKey.'.price_id'), 'quantity' => 1]],
                'managed_payments' => ['enabled' => 'true'],
                'success_url' => $success,
                'cancel_url' => $base.$cancelPath,
                'locale' => $locale,
                'metadata' => ['petprep_product' => $productKey, 'locale' => $locale],
                'branding_settings' => array_filter([
                    'display_name' => $brand['display_name'] ?? null,
                    'button_color' => $brand['button_color'] ?? null,
                    'background_color' => $brand['background_color'] ?? null,
                    'icon' => filled($brand['icon_url'] ?? null) ? ['type' => 'url', 'url' => $brand['icon_url']] : null,
                    'logo' => filled($brand['logo_url'] ?? null) ? ['type' => 'url', 'url' => $brand['logo_url']] : null,
                ]),
            ]);
        } catch (StripeException $e) {
            report($e);

            return ['error' => self::ERROR_UPSTREAM];
        }

        $url = $session['url'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            report(new StripeException('Checkout Session without url'));

            return ['error' => self::ERROR_UPSTREAM];
        }

        return ['url' => $url];
    }

    /** One of the 15 website languages, else `en`. */
    public static function locale(?string $locale): string
    {
        $locale = strtolower((string) $locale);

        return in_array($locale, WebsiteLocale::ALL, true) ? $locale : 'en';
    }
}
