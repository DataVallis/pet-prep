<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\SuperadminReadOnly;
use App\Filament\Resources\ShopOrderResource\Pages;
use App\Models\ShopOrder;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Website shop orders (M6-S09, WEBSITE_SALES_SPEC §3.7) — superadmin, read only; the
 * actions (show code, resend, change e-mail) live on the view page and are audited.
 * Refunds are made in Stripe; the webhook updates the order.
 */
class ShopOrderResource extends Resource
{
    use SuperadminReadOnly;

    protected static ?string $model = ShopOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $slug = 'shop-orders';

    protected static ?int $navigationSort = -10;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.shop_orders');
    }

    public static function getModelLabel(): string
    {
        return __('admin.navigation.shop_order_model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.navigation.shop_orders');
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            ShopOrder::STATUS_PAID => 'success',
            ShopOrder::STATUS_PENDING => 'gray',
            ShopOrder::STATUS_PARTIALLY_REFUNDED, ShopOrder::STATUS_DISPUTED => 'warning',
            default => 'danger',
        };
    }

    public static function stripePaymentUrl(ShopOrder $order): ?string
    {
        return $order->stripe_payment_intent_id === null ? null
            : 'https://dashboard.stripe.com/'.($order->livemode ? '' : 'test/').'payments/'.$order->stripe_payment_intent_id;
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label($t('number'))->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label($t('date'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->sortable(),
                Tables\Columns\TextColumn::make('product_key')->label($t('product'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.products.'.$state)),
                Tables\Columns\TextColumn::make('amount_total')->label($t('amount'))
                    ->formatStateUsing(fn (?int $state, ShopOrder $record): string => ShopOrder::money($state, $record->currency)),
                Tables\Columns\TextColumn::make('amount_tax')->label($t('tax'))->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?int $state, ShopOrder $record): string => ShopOrder::money($state, $record->currency)),
                Tables\Columns\TextColumn::make('customer_email')->label($t('email'))->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer_country')->label($t('country'))->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label($t('status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.statuses.'.$state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('livemode')->label($t('mode'))->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? $t('live') : $t('test'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('stripe_checkout_session_id')->label($t('session'))->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)->limit(24),
                Tables\Columns\TextColumn::make('stripe_payment_intent_id')->label($t('payment'))->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label($t('status'))
                    ->options(collect(ShopOrder::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('admin.shop_admin.statuses.'.$s)])->all()),
                Tables\Filters\SelectFilter::make('product_key')->label($t('product'))
                    ->options(collect(array_keys((array) config('shop.products')))->mapWithKeys(fn (string $p): array => [$p => __('admin.shop_admin.products.'.$p)])->all()),
                Tables\Filters\TernaryFilter::make('livemode')->label($t('mode'))->trueLabel($t('live'))->falseLabel($t('test')),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $t = fn (string $k): string => __('admin.shop_admin.'.$k);
        $money = fn (string $field) => fn (ShopOrder $record): string => ShopOrder::money($record->{$field}, $record->currency);

        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('number')->label($t('number'))->placeholder('—')->copyable(),
                TextEntry::make('status')->label($t('status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.statuses.'.$state))
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('product_key')->label($t('product'))
                    ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.products.'.$state)),
                TextEntry::make('amount_total')->label($t('amount'))->state($money('amount_total')),
                TextEntry::make('amount_tax')->label($t('tax'))->state($money('amount_tax')),
                TextEntry::make('amount_refunded')->label($t('refunded'))->state($money('amount_refunded')),
                TextEntry::make('customer_email')->label($t('email'))->placeholder('—')->copyable()
                    ->url(fn (ShopOrder $record): ?string => $record->customer_email === null ? null
                        : ShopOrderResource::getUrl('index', ['tableSearch' => $record->customer_email])),
                TextEntry::make('customer_name')->label($t('name'))->placeholder('—'),
                TextEntry::make('customer_country')->label($t('country'))->placeholder('—'),
                TextEntry::make('locale')->label($t('locale')),
                TextEntry::make('created_at')->label($t('date'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana'),
                TextEntry::make('paid_at')->label($t('paid_at'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->placeholder('—'),
                TextEntry::make('stripe_payment_intent_id')->label($t('payment'))->placeholder('—')
                    ->url(fn (ShopOrder $record): ?string => self::stripePaymentUrl($record), shouldOpenInNewTab: true),
                TextEntry::make('livemode')->label($t('mode'))
                    ->formatStateUsing(fn (bool $state): string => $state ? $t('live') : $t('test')),
            ]),
            Section::make($t('voucher'))->columns(4)
                ->visible(fn (ShopOrder $record): bool => $record->voucher !== null)
                ->schema([
                    TextEntry::make('voucher.key_last4')->label($t('code'))->formatStateUsing(fn (?string $state): string => 'PP-…'.$state),
                    TextEntry::make('voucher.status')->label($t('status'))->badge()
                        ->state(fn (ShopOrder $record): ?string => $record->voucher?->displayStatus()),
                    TextEntry::make('voucher.expires_at')->label($t('expires_at'))->date('Y-m-d'),
                    TextEntry::make('voucher.redeemed_at')->label($t('redeemed_at'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana')->placeholder('—'),
                    TextEntry::make('voucher.family_id')->label($t('family'))->placeholder('—')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '#'.$state),
                    TextEntry::make('voucher.credit.pet_id')->label($t('pet'))->placeholder('—')
                        ->url(fn (ShopOrder $record): ?string => $record->voucher?->credit?->pet_id !== null
                            ? PetResource::getUrl('edit', ['record' => $record->voucher->credit->pet_id]) : null),
                ]),
            Section::make($t('emails'))->schema([
                RepeatableEntry::make('emails')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label($t('date'))->dateTime('Y-m-d H:i', 'Europe/Ljubljana'),
                    TextEntry::make('to_address')->label($t('to')),
                    TextEntry::make('status')->label($t('status'))->badge()
                        ->formatStateUsing(fn (string $state): string => __('admin.shop_admin.email_statuses.'.$state)),
                    TextEntry::make('attempts')->label($t('attempts')),
                ]),
            ]),
            Section::make($t('events'))->collapsed()->schema([
                RepeatableEntry::make('stripeEvents')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('created_at')->label($t('date'))->dateTime('Y-m-d H:i:s', 'Europe/Ljubljana'),
                    TextEntry::make('type'),
                    TextEntry::make('outcome')->badge(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopOrders::route('/'),
            'view' => Pages\ViewShopOrder::route('/{record}'),
        ];
    }
}
