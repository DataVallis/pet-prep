<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SuperadminReadOnly;
use App\Filament\Resources\ShopProductResource\Pages;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Services\Shop\ShopFiles;
use App\Support\WebsiteLocale;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Website shop products (M6-S09, spec §3.7) — the admin copy of the Stripe products
 * (prices are edited in Stripe; »Refresh from Stripe« copies them here) and the sold
 * PDF files per language (upload on the list page).
 */
class ShopProductResource extends Resource
{
    use SuperadminReadOnly;

    protected static ?string $model = ShopProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $slug = 'shop-products';

    protected static ?int $navigationSort = -9;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.shop_products');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.shop_product_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.shop_products');
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);
        $paid = [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PARTIALLY_REFUNDED, ShopOrder::STATUS_DISPUTED];

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label($t('product'))->description(fn (ShopProduct $r): string => $r->key),
                Tables\Columns\TextColumn::make('unit_amount')->label($t('price'))
                    ->formatStateUsing(fn (?int $state, ShopProduct $r): string => ShopOrder::money($state, $r->currency)),
                Tables\Columns\IconColumn::make('active')->label($t('active'))->boolean(),
                Tables\Columns\TextColumn::make('livemode')->label($t('mode'))->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? $t('live') : $t('test')),
                Tables\Columns\TextColumn::make('sold')->label($t('sold'))
                    ->state(fn (ShopProduct $r): int => ShopOrder::where('product_key', $r->key)->where('livemode', $r->livemode)->whereIn('status', $paid)->count()),
                Tables\Columns\TextColumn::make('revenue')->label($t('revenue'))
                    ->state(function (ShopProduct $r) use ($paid): string {
                        $q = ShopOrder::where('product_key', $r->key)->where('livemode', $r->livemode)->whereIn('status', $paid);

                        return ShopOrder::money((int) (clone $q)->sum('amount_total') - (int) (clone $q)->sum('amount_refunded'), $r->currency);
                    }),
                Tables\Columns\TextColumn::make('files')->label($t('files'))
                    ->state(fn (ShopProduct $r): string => self::filesOf($r->key === 'gift_voucher' ? 'promise_kit' : $r->key)),
                Tables\Columns\TextColumn::make('synced_at')->label($t('synced_at'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana'),
            ])
            ->actions([
                Tables\Actions\Action::make('stripe')->label($t('edit_in_stripe'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ShopProduct $r): ?string => $r->dashboardUrl(), shouldOpenInNewTab: true),
            ])
            ->bulkActions([])
            ->paginated(false);
    }

    /** »sl, en, de … (12 / 15)« — which languages have a file. */
    public static function filesOf(string $product): string
    {
        $have = ShopFiles::locales($product);

        return ($have === [] ? '—' : implode(', ', $have)).' ('.count($have).' / '.count(WebsiteLocale::ALL).')';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopProducts::route('/'),
        ];
    }
}
