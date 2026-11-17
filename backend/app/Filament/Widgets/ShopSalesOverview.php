<?php

namespace App\Filament\Widgets;

use App\Models\ShopOrder;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * M6-S09 (spec §3.7): paid live shop orders today / this month per product, from
 * shop_orders. Overview only — the books are Stripe's (Link is the seller).
 */
class ShopSalesOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $tz = 'Europe/Ljubljana';
        $stats = [];
        foreach (array_keys((array) config('shop.products')) as $product) {
            foreach (['today' => now($tz)->startOfDay(), 'month' => now($tz)->startOfMonth()] as $period => $from) {
                $q = ShopOrder::query()->where('product_key', $product)->where('livemode', true)
                    ->whereIn('status', [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PARTIALLY_REFUNDED, ShopOrder::STATUS_DISPUTED])
                    ->where('paid_at', '>=', $from->copy()->utc());
                $count = (clone $q)->count();
                $sum = (int) (clone $q)->sum('amount_total') - (int) (clone $q)->sum('amount_refunded');
                $currency = (clone $q)->value('currency') ?? 'eur';
                $stats[] = Stat::make(
                    __('admin.shop_admin.products.'.$product).' · '.__('admin.shop_admin.'.$period),
                    ShopOrder::money($sum, $currency),
                )->description(__('admin.shop_admin.orders_count', ['count' => $count]));
            }
        }

        return $stats;
    }
}
